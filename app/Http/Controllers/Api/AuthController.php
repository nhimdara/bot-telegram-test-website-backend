<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function telegram(Request $request)
    {
        $data = $request->validate([
            'init_data' => ['required', 'string'],
        ]);

        $telegramUser = $this->validateInitData($data['init_data']);
        $telegramId = (string) $telegramUser['id'];
        $name = trim(implode(' ', array_filter([
            $telegramUser['first_name'] ?? null,
            $telegramUser['last_name'] ?? null,
        ]))) ?: ($telegramUser['username'] ?? 'Telegram User');

        $user = User::query()->firstOrNew(['telegram_id' => $telegramId]);
        $user->fill([
            'name' => Str::limit($name, 255, ''),
            'email' => $telegramId.'@telegram.local',
            'is_admin' => in_array($telegramId, config('services.telegram.admin_ids', []), true),
        ]);
        if (! $user->exists) {
            $user->password = Hash::make(Str::random(64));
        }
        $user->save();

        Cart::firstOrCreate(['user_id' => $user->id]);
        $user->tokens()->where('name', 'telegram-shop')->delete();
        $token = $user->createToken('telegram-shop')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->only(['id', 'telegram_id', 'name', 'email', 'is_admin']),
        ]);
    }

    public function profile(Request $request)
    {
        return response()->json($request->user()->only([
            'id', 'telegram_id', 'name', 'email', 'is_admin', 'created_at',
        ]));
    }

    private function validateInitData(string $initData): array
    {
        parse_str($initData, $fields);
        $hash = $fields['hash'] ?? null;
        $botToken = config('services.telegram.bot_token');

        abort_unless(is_string($hash) && is_string($botToken) && $botToken !== '', 401, 'Invalid Telegram authentication data.');

        unset($fields['hash']);
        ksort($fields);
        $checkString = collect($fields)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode("\n");
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expectedHash = hash_hmac('sha256', $checkString, $secretKey);

        abort_unless(hash_equals($expectedHash, $hash), 401, 'Invalid Telegram authentication data.');

        $authDate = filter_var($fields['auth_date'] ?? null, FILTER_VALIDATE_INT);
        $maxAge = config('services.telegram.auth_max_age', 86400);
        abort_unless($authDate && $authDate <= time() + 30 && time() - $authDate <= $maxAge, 401, 'Telegram authentication data has expired.');

        $telegramUser = json_decode($fields['user'] ?? '', true);
        abort_unless(is_array($telegramUser) && isset($telegramUser['id']), 401, 'Telegram user data is missing.');

        return $telegramUser;
    }
}
