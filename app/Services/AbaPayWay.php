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
