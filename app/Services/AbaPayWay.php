<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

class AbaPayWay
{
    public function ensureConfigured(): void
    {
        if (! config('services.payway.enabled')) {
            throw new RuntimeException('ABA PayWay is not enabled. Set PAYWAY_ENABLED=true after adding your merchant credentials.');
        }

        if (! is_string(config('services.payway.merchant_id')) || config('services.payway.merchant_id') === ''
            || ! is_string(config('services.payway.api_key')) || config('services.payway.api_key') === '') {
            throw new RuntimeException('ABA PayWay merchant ID or API key is not configured.');
        }
    }

    public function checkout(Payment $payment, Order $order): array
    {
        $this->ensureConfigured();
        $order->loadMissing(['items.product', 'user']);

        $nameParts = preg_split('/\s+/u', trim((string) $order->user->name), 2) ?: [];
        $firstName = $this->safeName($nameParts[0] ?? 'Customer');
        $lastName = $this->safeName($nameParts[1] ?? 'Shopper');
        $callbackUrl = URL::temporarySignedRoute(
            'payway.callback',
            now()->addHours(2),
            ['payment' => $payment->id]
        );
        $continueUrl = $this->continueUrl($payment->id);
        $items = base64_encode(json_encode($order->items->map(fn ($item) => [
            'name' => Str::limit((string) ($item->product?->name ?? 'Product'), 60, ''),
            'quantity' => (string) $item->quantity,
            'price' => number_format((float) $item->price, 2, '.', ''),
        ])->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $fields = [
            'req_time' => now('UTC')->format('YmdHis'),
            'merchant_id' => (string) config('services.payway.merchant_id'),
            'tran_id' => $payment->provider_reference,
            'firstname' => $firstName,
            'lastname' => $lastName,
            'email' => (string) ($order->user->email ?? ''),
            'phone' => '',
            'type' => 'purchase',
            'payment_option' => (string) config('services.payway.payment_option', ''),
            'items' => $items,
            'amount' => $this->formatAmount($payment->amount, $payment->currency),
            'currency' => $payment->currency,
            'return_url' => base64_encode($callbackUrl),
            'cancel_url' => $continueUrl.'&payway_cancelled=1',
            'continue_success_url' => $continueUrl,
            'return_params' => (string) $payment->id,
        ];

        // PayWay requires this exact sequence; skipped optional values are empty strings.
        $hashValues = [
            $fields['req_time'], $fields['merchant_id'], $fields['tran_id'],
            $fields['amount'], $fields['items'], '', '', '',
            $fields['firstname'], $fields['lastname'], $fields['email'], $fields['phone'],
            $fields['type'], $fields['payment_option'], $fields['return_url'],
            $fields['cancel_url'], $fields['continue_success_url'], '',
            $fields['currency'], '', $fields['return_params'],
        ];
        $fields['hash'] = $this->sign(implode('', $hashValues));

        return [
            'url' => rtrim((string) config('services.payway.base_url'), '/').'/api/payment-gateway/v1/payments/purchase',
            'fields' => $fields,
        ];
    }

    public function generateQr(Payment $payment, Order $order): array
    {
        $this->ensureConfigured();
        $order->loadMissing(['items.product', 'user']);

        $nameParts = preg_split('/\s+/u', trim((string) $order->user->name), 2) ?: [];
        $lifetime = (int) config('services.payway.qr_lifetime', 15);
        if ($lifetime < 1) {
            throw new RuntimeException('PAYWAY_QR_LIFETIME must be at least 1 minute.');
        }

        $callbackUrl = URL::temporarySignedRoute(
            'payway.callback',
            now()->addMinutes($lifetime + 30),
            ['payment' => $payment->id]
        );
        $items = base64_encode(json_encode($order->items->map(fn ($item) => [
            'name' => Str::limit((string) ($item->product?->name ?? 'Product'), 60, ''),
            'quantity' => (int) $item->quantity,
            'price' => (float) $item->price,
        ])->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // PayWay signs the concatenated values in the same order as its QR API request.
        $fields = [
            'req_time' => now('UTC')->format('YmdHis'),
            'merchant_id' => (string) config('services.payway.merchant_id'),
            'tran_id' => $payment->provider_reference,
            'first_name' => $this->safeName($nameParts[0] ?? 'Customer'),
            'last_name' => $this->safeName($nameParts[1] ?? 'Shopper'),
            'email' => (string) ($order->user->email ?? ''),
            'phone' => '',
            'amount' => (float) $this->formatAmount($payment->amount, $payment->currency),
            'purchase_type' => 'purchase',
            'payment_option' => (string) config('services.payway.qr_payment_option', 'abapay_khqr'),
            'items' => $items,
            'currency' => $payment->currency,
            'callback_url' => base64_encode($callbackUrl),
            'return_deeplink' => null,
            'custom_fields' => null,
            'return_params' => (string) $payment->id,
            'payout' => null,
            'lifetime' => $lifetime,
            'qr_image_template' => (string) config('services.payway.qr_image_template', 'template3_color'),
        ];
        // ABA's QR API signs values in this canonical order (which differs from
        // the display order in its JSON request example).
        $hashOrder = [
            'req_time', 'merchant_id', 'tran_id', 'amount', 'items',
            'first_name', 'last_name', 'email', 'phone', 'purchase_type',
            'payment_option', 'callback_url', 'return_deeplink', 'currency',
            'custom_fields', 'return_params', 'payout', 'lifetime',
            'qr_image_template',
        ];
        $fields['hash'] = $this->sign(implode('', array_map(
            static fn (string $key) => (string) ($fields[$key] ?? ''),
            $hashOrder
        )));

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(15)
                ->retry(2, 250)
                ->post(rtrim((string) config('services.payway.base_url'), '/').'/api/payment-gateway/v1/payments/generate-qr', $fields);
        } catch (ConnectionException) {
            throw new RuntimeException('Unable to contact ABA PayWay.');
        }

        $body = $response->json() ?? [];
        $statusCode = (string) ($body['status']['code'] ?? '');
        if (! $response->successful() || $statusCode !== '0') {
            $message = (string) ($body['status']['message'] ?? 'ABA PayWay QR generation failed.');
            throw new RuntimeException($message !== '' ? $message : 'ABA PayWay QR generation failed.');
        }

        if (! is_string($body['qrString'] ?? null) || $body['qrString'] === '') {
            throw new RuntimeException('ABA PayWay returned an invalid QR response.');
        }

        return $body;
    }

    public function checkTransaction(string $transactionId): array
    {
        $this->ensureConfigured();
        $fields = [
            'req_time' => now('UTC')->format('YmdHis'),
            'merchant_id' => (string) config('services.payway.merchant_id'),
            'tran_id' => $transactionId,
        ];
        $fields['hash'] = $this->sign(implode('', $fields));

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->retry(2, 250)
                ->post(rtrim((string) config('services.payway.base_url'), '/').'/api/payment-gateway/v1/payments/check-transaction-2', $fields);
        } catch (ConnectionException) {
            throw new RuntimeException('Unable to contact ABA PayWay.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('ABA PayWay verification failed.');
        }

        return $response->json() ?? [];
    }

    public function callbackSignatureIsValid(array $payload, ?string $signature): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        ksort($payload);
        $valueString = '';
        foreach ($payload as $value) {
            $valueString .= is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string) $value;
        }

        return hash_equals($this->sign($valueString), $signature);
    }

    private function continueUrl(int $paymentId): string
    {
        $url = rtrim((string) config('services.payway.continue_url'), '?&');

        return $url.(str_contains($url, '?') ? '&' : '?').'payway_payment='.$paymentId;
    }

    private function safeName(string $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N} ]/u', '', $name) ?: 'Customer';

        return mb_substr($clean, 0, 20);
    }

    private function formatAmount(string $amount, string $currency): string
    {
        return $currency === 'KHR'
            ? (string) round((float) $amount)
            : number_format((float) $amount, 2, '.', '');
    }

    private function sign(string $value): string
    {
        return base64_encode(hash_hmac('sha512', $value, (string) config('services.payway.api_key'), true));
    }
}
