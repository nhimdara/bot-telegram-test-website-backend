<?php

namespace App\Services;

use InvalidArgumentException;

class BakongKhqr
{
    public function matchesConfiguredAccountType(string $payload): bool
    {
        $accountType = strtolower((string) config('services.bakong.account_type', 'individual'));
        $expectedTag = $accountType === 'merchant' ? '30' : '29';

        return substr($payload, 12, 2) === $expectedTag;
    }

    public function generate(string $amount, string $currency, string $billNumber): array
    {
        $accountId = $this->requiredConfig('account_id', 32);
        $merchantName = $this->requiredConfig('merchant_name', 25);
        $merchantCity = $this->value(config('services.bakong.merchant_city', 'Phnom Penh'), 15, 'Merchant city');
        $currency = strtoupper($currency);
        $accountType = strtolower((string) config('services.bakong.account_type', 'individual'));

        if (! in_array($currency, ['USD', 'KHR'], true)) {
            throw new InvalidArgumentException('Bakong currency must be USD or KHR.');
        }
        if (! in_array($accountType, ['individual', 'merchant'], true)) {
            throw new InvalidArgumentException('BAKONG_ACCOUNT_TYPE must be individual or merchant.');
        }

        $merchantId = config('services.bakong.merchant_id');
        $acquiringBank = config('services.bakong.acquiring_bank');
        if ($accountType === 'merchant' && (! $merchantId || ! $acquiringBank)) {
            throw new InvalidArgumentException('BAKONG_MERCHANT_ID and BAKONG_ACQUIRING_BANK must be configured together.');
        }
        $accountInfo = $this->tag('00', $accountId);
        $accountTag = '29';

        if ($accountType === 'merchant') {
            $accountTag = '30';
            $accountInfo .= $this->tag('01', $this->value($merchantId, 32, 'Merchant ID'));
            $accountInfo .= $this->tag('02', $this->value($acquiringBank, 32, 'Acquiring bank'));
        }

        $additionalData = $this->tag('01', $this->value($billNumber, 25, 'Bill number'));
        if ($storeLabel = config('services.bakong.store_label')) {
            $additionalData .= $this->tag('03', $this->value($storeLabel, 25, 'Store label'));
        }
        if ($terminalLabel = config('services.bakong.terminal_label')) {
            $additionalData .= $this->tag('07', $this->value($terminalLabel, 25, 'Terminal label'));
        }

        $formattedAmount = $currency === 'KHR'
            ? (string) round((float) $amount)
            : number_format((float) $amount, 2, '.', '');

        $payload = $this->tag('00', '01')
            .$this->tag('01', '12')
            .$this->tag($accountTag, $accountInfo)
            .$this->tag('52', config('services.bakong.mcc', '5999'))
            .$this->tag('53', $currency === 'KHR' ? '116' : '840')
            .$this->tag('54', $formattedAmount)
            .$this->tag('58', 'KH')
            .$this->tag('59', $merchantName)
            .$this->tag('60', $merchantCity)
            .$this->tag('62', $additionalData)
            .$this->tag('99', $this->tag('00', (string) now()->getTimestampMs()))
            .'6304';

        $payload .= $this->crc16($payload);

        return ['payload' => $payload, 'md5' => md5($payload)];
    }

    private function tag(string $tag, string $value): string
    {
        $length = strlen($value);
        if ($length > 99) {
            throw new InvalidArgumentException("KHQR tag {$tag} is too long.");
        }

        return $tag.str_pad((string) $length, 2, '0', STR_PAD_LEFT).$value;
    }

    private function requiredConfig(string $key, int $maxLength): string
    {
        $value = config("services.bakong.{$key}");
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('BAKONG_'.strtoupper($key).' is not configured.');
        }

        return $this->value($value, $maxLength, str_replace('_', ' ', ucfirst($key)));
    }

    private function value(string $value, int $maxLength, string $label): string
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$label} must contain 1-{$maxLength} bytes.");
        }

        return $value;
    }

    private function crc16(string $value): string
    {
        $crc = 0xFFFF;
        foreach (unpack('C*', $value) as $byte) {
            $crc ^= $byte << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
