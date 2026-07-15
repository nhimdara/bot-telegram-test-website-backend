<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\BakongKhqr;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order, BakongKhqr $khqr): JsonResponse
    {
        $this->ensureOrderOwnership($request, $order);

        if ($order->payment?->status === 'paid') {
            return $this->paymentResponse($order->payment);
        }
        abort_unless($order->status === 'pending', 422, 'Only pending orders can be paid.');

        $payment = $order->payment;
        if ($payment?->status === 'pending'
            && $payment->expires_at->isFuture()
            && $khqr->matchesConfiguredAccountType($payment->khqr_payload)) {
            return $this->paymentResponse($payment);
        }

        try {
            $generated = $khqr->generate(
                (string) $order->total,
                config('services.bakong.currency', 'USD'),
                'ORDER-'.$order->id
            );
        } catch (InvalidArgumentException $exception) {
            abort(503, $exception->getMessage());
        }

        $payment = Payment::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => 'bakong',
                'status' => 'pending',
                'amount' => $order->total,
                'currency' => strtoupper(config('services.bakong.currency', 'USD')),
                'khqr_payload' => $generated['payload'],
                'md5' => $generated['md5'],
                'transaction_hash' => null,
                'provider_response' => null,
                'expires_at' => $generated['expires_at'],
                'paid_at' => null,
            ]
        );

        return $this->paymentResponse($payment, 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->ensurePaymentOwnership($request, $payment);

        return $this->paymentResponse($payment);
    }

    public function qr(Request $request, Payment $payment, BakongKhqr $khqr): Response
    {
        $this->ensurePaymentOwnership($request, $payment);
        if (! $khqr->matchesConfiguredAccountType($payment->khqr_payload)) {
            $generated = $khqr->generate(
                (string) $payment->amount,
                $payment->currency,
                'ORDER-'.$payment->order_id
            );
            $payment->update([
                'khqr_payload' => $generated['payload'],
                'md5' => $generated['md5'],
                'status' => 'pending',
                'expires_at' => $generated['expires_at'],
            ]);
        }

        $qrCode = new QrCode(
            data: $payment->khqr_payload,
            encoding: new Encoding('ISO-8859-1'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 360,
            margin: 16,
        );
        $svg = (new SvgWriter)->write($qrCode)->getString();

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }

    public function check(Request $request, Payment $payment): JsonResponse
    {
        $this->ensurePaymentOwnership($request, $payment);
        if ($payment->status === 'paid') {
            return $this->paymentResponse($payment);
        }

        $token = config('services.bakong.token');
        abort_unless(is_string($token) && $token !== '', 503, 'BAKONG_TOKEN is not configured.');

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->timeout(10)
                ->retry(2, 250)
                ->post(rtrim(config('services.bakong.api_url'), '/').'/v1/check_transaction_by_md5', [
                    'md5' => $payment->md5,
                ]);
        } catch (ConnectionException) {
            abort(502, 'Unable to contact Bakong.');
        }

        abort_unless($response->successful(), 502, 'Bakong verification failed.');
        $body = $response->json();
        $transaction = $body['data'] ?? null;

        if ((int) ($body['responseCode'] ?? 1) === 0 && is_array($transaction)) {
            $this->validateTransaction($payment, $transaction);

            DB::transaction(function () use ($payment, $body, $transaction) {
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
                if ($locked->status !== 'paid') {
                    $locked->update([
                        'status' => 'paid',
                        'transaction_hash' => $transaction['hash'] ?? null,
                        'provider_response' => $body,
                        'paid_at' => now(),
                    ]);
                    $locked->order()->update(['status' => 'paid']);
                }
            });
        } elseif ($payment->expires_at->isPast()) {
            $payment->update(['status' => 'expired', 'provider_response' => $body]);
        } else {
            $payment->update(['provider_response' => $body]);
        }

        return $this->paymentResponse($payment->fresh());
    }

    private function validateTransaction(Payment $payment, array $transaction): void
    {
        $amountMatches = abs((float) ($transaction['amount'] ?? -1) - (float) $payment->amount) < 0.001;
        $currencyMatches = strtoupper((string) ($transaction['currency'] ?? '')) === $payment->currency;
        $receiver = $transaction['toAccountId'] ?? null;
        $receiverMatches = $receiver === null || $receiver === config('services.bakong.account_id');

        abort_unless($amountMatches && $currencyMatches && $receiverMatches, 409, 'Bakong transaction details do not match this payment.');
    }

    private function ensureOrderOwnership(Request $request, Order $order): void
    {
        abort_unless($order->user_id === $request->user()->id, 404);
        $order->loadMissing('payment');
    }

    private function ensurePaymentOwnership(Request $request, Payment $payment): void
    {
        abort_unless($payment->order()->where('user_id', $request->user()->id)->exists(), 404);
    }

    private function paymentResponse(Payment $payment, int $status = 200): JsonResponse
    {
        return response()->json([
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'provider' => $payment->provider,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'md5' => $payment->md5,
            'khqr' => $payment->khqr_payload,
            'qr_url' => route('payments.qr', $payment),
            'expires_at' => $payment->expires_at,
            'paid_at' => $payment->paid_at,
        ], $status);
    }
}
