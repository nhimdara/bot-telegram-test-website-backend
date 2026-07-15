<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\AbaPayWay;
use App\Services\BakongKhqr;
use App\Services\OrderInventory;
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
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PaymentController extends Controller
{
    public function store(Request $request, Order $order, BakongKhqr $khqr, OrderInventory $inventory): JsonResponse
    {
        $this->ensureOrderOwnership($request, $order);

        if ($order->payment?->status === 'paid') {
            return $this->paymentResponse($order->payment);
        }
        abort_unless($order->status === 'pending', 422, 'Only pending orders can be paid.');

        $payment = $order->payment;
        if ($payment?->status === 'pending'
            && $payment->expires_at->isFuture()
            && $khqr->matchesConfiguredReceiver($payment->khqr_payload)) {
            return $this->paymentResponse($payment);
        }

        try {
            $currency = strtoupper(config('services.bakong.currency', 'USD'));
            $amount = $this->paymentAmount((float) $order->total, $currency);
            $generated = $khqr->generate(
                $amount,
                $currency,
                'ORDER-'.$order->id
            );
        } catch (InvalidArgumentException $exception) {
            $inventory->release($order);
            abort(503, $exception->getMessage());
        }

        $payment = Payment::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => 'bakong',
                'provider_reference' => null,
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
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

    public function storePayWay(Request $request, Order $order, AbaPayWay $payWay, OrderInventory $inventory): JsonResponse
    {
        $this->ensureOrderOwnership($request, $order);

        if ($order->payment?->status === 'paid') {
            return $this->paymentResponse($order->payment);
        }
        abort_unless($order->status === 'pending', 422, 'Only pending orders can be paid.');

        try {
            $payWay->ensureConfigured();
            $currency = strtoupper((string) config('services.payway.currency', 'USD'));
            abort_unless(in_array($currency, ['USD', 'KHR'], true), 503, 'PAYWAY_CURRENCY must be USD or KHR.');
            $amount = $this->paymentAmount((float) $order->total, $currency);
        } catch (RuntimeException $exception) {
            $inventory->release($order);
            abort(503, $exception->getMessage());
        }

        $existingReference = $order->payment?->provider === 'payway'
            ? $order->payment->provider_reference
            : null;
        $reference = $existingReference ?: 'PW'.$order->id.Str::upper(Str::random(10));

        $payment = Payment::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => 'payway',
                'provider_reference' => mb_substr($reference, 0, 20),
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'khqr_payload' => null,
                'md5' => null,
                'transaction_hash' => null,
                'provider_response' => null,
                'expires_at' => now()->addHours(2),
                'paid_at' => null,
            ]
        );

        try {
            $checkout = $payWay->checkout($payment, $order);
        } catch (RuntimeException $exception) {
            $inventory->release($order);
            abort(503, $exception->getMessage());
        }

        return $this->paymentResponse($payment, 201, ['checkout' => $checkout]);
    }

    public function storePayWayQr(Request $request, Order $order, AbaPayWay $payWay, OrderInventory $inventory): JsonResponse
    {
        $this->ensureOrderOwnership($request, $order);

        if ($order->payment?->status === 'paid') {
            return $this->paymentResponse($order->payment);
        }
        abort_unless($order->status === 'pending', 422, 'Only pending orders can be paid.');

        try {
            $payWay->ensureConfigured();
            $currency = strtoupper((string) config('services.payway.currency', 'USD'));
            abort_unless(in_array($currency, ['USD', 'KHR'], true), 503, 'PAYWAY_CURRENCY must be USD or KHR.');
            $amount = $this->paymentAmount((float) $order->total, $currency);
        } catch (RuntimeException $exception) {
            $inventory->release($order);
            abort(503, $exception->getMessage());
        }

        $existing = $order->payment;
        if ($existing?->provider === 'payway'
            && $existing->status === 'pending'
            && $existing->expires_at->isFuture()
            && $existing->payway_qr_string) {
            return $this->paymentResponse($existing);
        }

        $reference = $existing?->provider === 'payway'
            ? $existing->provider_reference
            : null;
        $reference = $reference ?: 'PW'.$order->id.Str::upper(Str::random(10));
        $lifetime = max(1, (int) config('services.payway.qr_lifetime', 15));

        $payment = Payment::query()->updateOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => 'payway',
                'provider_reference' => mb_substr($reference, 0, 20),
                'status' => 'pending',
                'amount' => $amount,
                'currency' => $currency,
                'khqr_payload' => null,
                'md5' => null,
                'transaction_hash' => null,
                'provider_response' => null,
                'payway_qr_string' => null,
                'payway_qr_image' => null,
                'payway_deeplink' => null,
                'payway_app_store' => null,
                'payway_play_store' => null,
                'expires_at' => now()->addMinutes($lifetime),
                'paid_at' => null,
            ]
        );

        try {
            $qr = $payWay->generateQr($payment, $order);
        } catch (RuntimeException $exception) {
            $inventory->release($order);
            abort(502, $exception->getMessage());
        }

        $payment->update([
            'payway_qr_string' => $qr['qrString'],
            'payway_qr_image' => $qr['qrImage'] ?? null,
            'payway_deeplink' => $qr['abapay_deeplink'] ?? null,
            'payway_app_store' => $qr['app_store'] ?? null,
            'payway_play_store' => $qr['play_store'] ?? null,
        ]);

        return $this->paymentResponse($payment->fresh(), 201);
    }

    public function show(Request $request, Payment $payment): JsonResponse
    {
        $this->ensurePaymentOwnership($request, $payment);

        return $this->paymentResponse($payment);
    }

    public function qr(Request $request, Payment $payment, BakongKhqr $khqr): Response
    {
        $this->ensurePaymentOwnership($request, $payment);
        abort_unless($payment->provider === 'bakong', 404);
        if (! $khqr->matchesConfiguredReceiver($payment->khqr_payload)) {
            $currency = strtoupper(config('services.bakong.currency', 'USD'));
            $orderTotal = (float) $payment->order()->value('total');
            $amount = $this->paymentAmount($orderTotal, $currency);
            $generated = $khqr->generate(
                $amount,
                $currency,
                'ORDER-'.$payment->order_id
            );
            $payment->update([
                'amount' => $amount,
                'currency' => $currency,
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
            'Content-Disposition' => 'inline; filename="khqr-order-'.$payment->order_id.'.svg"',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
        ]);
    }

    public function check(Request $request, Payment $payment): JsonResponse
    {
        $this->ensurePaymentOwnership($request, $payment);
        abort_unless($payment->provider === 'bakong', 404);
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

    public function checkPayWay(Request $request, Payment $payment, AbaPayWay $payWay): JsonResponse
    {
        $this->ensurePaymentOwnership($request, $payment);
        abort_unless($payment->provider === 'payway', 404);

        if ($payment->status === 'paid') {
            return $this->paymentResponse($payment);
        }

        try {
            $body = $payWay->checkTransaction($payment->provider_reference);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        $this->applyPayWayStatus($payment, $body);

        return $this->paymentResponse($payment->fresh());
    }

    public function payWayCallback(Request $request, Payment $payment, AbaPayWay $payWay): Response
    {
        abort_unless($payment->provider === 'payway', 404);
        $payload = $request->json()->all();
        abort_unless(
            $payWay->callbackSignatureIsValid($payload, $request->header('X-PayWay-HMAC-SHA512')),
            401,
            'Invalid ABA PayWay callback signature.'
        );
        abort_unless((string) ($payload['tran_id'] ?? '') === $payment->provider_reference, 409);

        try {
            $body = $payWay->checkTransaction($payment->provider_reference);
        } catch (RuntimeException $exception) {
            abort(502, $exception->getMessage());
        }
        $this->applyPayWayStatus($payment, $body);

        return response()->noContent();
    }

    private function applyPayWayStatus(Payment $payment, array $body): void
    {
        $transaction = $body['data'] ?? [];
        $approved = (string) ($body['status']['code'] ?? '') === '00'
            && strtoupper((string) ($transaction['payment_status'] ?? '')) === 'APPROVED';

        if (! $approved) {
            $payment->update(['provider_response' => $body]);

            return;
        }

        $amountMatches = abs((float) ($transaction['original_amount'] ?? -1) - (float) $payment->amount) < 0.001;
        $currencyMatches = strtoupper((string) ($transaction['payment_currency'] ?? $transaction['original_currency'] ?? '')) === $payment->currency;
        abort_unless($amountMatches && $currencyMatches, 409, 'ABA PayWay transaction details do not match this payment.');

        DB::transaction(function () use ($payment, $body, $transaction) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status !== 'paid') {
                $locked->update([
                    'status' => 'paid',
                    'transaction_hash' => $transaction['bank_ref'] ?? $transaction['apv'] ?? null,
                    'provider_response' => $body,
                    'paid_at' => now(),
                ]);
                $locked->order()->update(['status' => 'paid']);
            }
        });
    }

    private function validateTransaction(Payment $payment, array $transaction): void
    {
        $amountMatches = abs((float) ($transaction['amount'] ?? -1) - (float) $payment->amount) < 0.001;
        $currencyMatches = strtoupper((string) ($transaction['currency'] ?? '')) === $payment->currency;
        $receiver = $transaction['toAccountId'] ?? null;
        $receiverMatches = $receiver === null || $receiver === config('services.bakong.account_id');

        abort_unless($amountMatches && $currencyMatches && $receiverMatches, 409, 'Bakong transaction details do not match this payment.');
    }

    private function paymentAmount(float $orderTotal, string $paymentCurrency): string
    {
        $shopCurrency = strtoupper((string) config('services.bakong.shop_currency', 'USD'));
        if ($shopCurrency === $paymentCurrency) {
            return $paymentCurrency === 'KHR'
                ? (string) round($orderTotal)
                : number_format($orderTotal, 2, '.', '');
        }

        $rate = (float) config('services.bakong.usd_to_khr_rate', 4026);
        abort_unless($rate > 0, 503, 'BAKONG_USD_TO_KHR_RATE must be greater than zero.');
        if ($shopCurrency === 'USD' && $paymentCurrency === 'KHR') {
            return (string) round($orderTotal * $rate);
        }
        if ($shopCurrency === 'KHR' && $paymentCurrency === 'USD') {
            return number_format($orderTotal / $rate, 2, '.', '');
        }

        abort(503, 'SHOP_CURRENCY and BAKONG_CURRENCY must be USD or KHR.');
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

    private function paymentResponse(Payment $payment, int $status = 200, array $extra = []): JsonResponse
    {
        $data = [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'provider' => $payment->provider,
            'status' => $payment->status,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'reference' => $payment->provider_reference,
            'expires_at' => $payment->expires_at,
            'paid_at' => $payment->paid_at,
        ];
        if ($payment->provider === 'bakong') {
            $data += [
                'md5' => $payment->md5,
                'khqr' => $payment->khqr_payload,
                'qr_url' => route('payments.qr', $payment),
            ];
        } elseif ($payment->provider === 'payway' && $payment->payway_qr_string) {
            $data['environment'] = str_contains((string) config('services.payway.base_url'), 'sandbox')
                ? 'sandbox'
                : 'production';
            $data['qr'] = [
                'string' => $payment->payway_qr_string,
                'image' => $payment->payway_qr_image,
                'deeplink' => $payment->payway_deeplink,
                'app_store' => $payment->payway_app_store,
                'play_store' => $payment->payway_play_store,
            ];
        }

        return response()->json($data + $extra, $status);
    }
}
