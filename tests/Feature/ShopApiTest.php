<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\Payment;
use App\Models\Product;
use App\Models\UploadedImage;
use App\Models\User;
use App\Services\BakongKhqr;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ShopApiTest extends TestCase
{
    use RefreshDatabase;

    private const BOT_TOKEN = '123456:test-token';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.bot_token', self::BOT_TOKEN);
        config()->set('services.telegram.auth_max_age', 86400);
        config()->set('services.bakong', [
            'api_url' => 'https://api-bakong.example',
            'token' => 'test-bakong-token',
            'account_id' => 'shop@bank',
            'merchant_name' => 'Telegram Shop',
            'merchant_city' => 'Phnom Penh',
            'merchant_id' => null,
            'acquiring_bank' => null,
            'store_label' => 'Online Shop',
            'terminal_label' => 'Web',
            'mcc' => '5999',
            'currency' => 'USD',
            'qr_expiry_minutes' => 15,
        ]);
    }

    public function test_user_can_authenticate_with_valid_telegram_init_data(): void
    {
        $response = $this->postJson('/api/auth/telegram', [
            'init_data' => $this->telegramInitData(12345, 'Alice', 'Smith'),
        ]);

        $response->assertOk()
            ->assertJsonPath('user.telegram_id', '12345')
            ->assertJsonPath('user.name', 'Alice Smith')
            ->assertJsonStructure(['token', 'user']);
        $this->assertDatabaseHas('carts', ['user_id' => $response->json('user.id')]);
    }

    public function test_invalid_telegram_signature_is_rejected(): void
    {
        $this->postJson('/api/auth/telegram', [
            'init_data' => 'auth_date='.time().'&user=%7B%22id%22%3A123%7D&hash=bad',
        ])->assertUnauthorized();
    }

    public function test_catalog_list_and_detail_endpoints_return_seeded_data(): void
    {
        $this->seed([CategorySeeder::class, ProductSeeder::class]);

        $category = $this->getJson('/api/categories')->assertOk()
            ->assertJsonStructure([['id', 'name', 'slug', 'products_count']])
            ->json('0');
        $this->getJson('/api/categories/'.$category['id'])->assertOk()
            ->assertJsonStructure(['id', 'products']);

        $product = $this->getJson('/api/products?search=Starter')->assertOk()
            ->assertJsonCount(1)
            ->assertJsonStructure([['id', 'name', 'price', 'category']])
            ->json('0');
        $this->getJson('/api/products/'.$product['id'])->assertOk()
            ->assertJsonPath('id', $product['id']);
    }

    public function test_authenticated_user_can_manage_cart(): void
    {
        $this->seed([CategorySeeder::class, ProductSeeder::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $product = Product::query()->firstOrFail();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated()
            ->assertJsonPath('items.0.quantity', 2);
        $item = CartItem::query()->firstOrFail();

        $this->patchJson('/api/cart/items/'.$item->id, ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('items.0.quantity', 3);
        $this->getJson('/api/cart')->assertOk()->assertJsonStructure(['id', 'items', 'total']);
        $this->deleteJson('/api/cart/items/'.$item->id)->assertOk()->assertJsonCount(0, 'items');

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1])->assertCreated();
        $this->deleteJson('/api/cart')->assertNoContent();
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_user_can_create_view_and_cancel_an_order(): void
    {
        $this->seed([CategorySeeder::class, ProductSeeder::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $product = Product::query()->firstOrFail();
        $originalStock = $product->stock;

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertCreated();
        $order = $this->postJson('/api/orders', [
            'address' => 'Main Street 10',
            'notes' => 'Leave at the gate',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['id', 'total', 'items'])
            ->json();

        $this->assertSame($originalStock - 2, $product->fresh()->stock);
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/orders/'.$order['id'])->assertOk()->assertJsonPath('id', $order['id']);
        $this->postJson('/api/orders/'.$order['id'].'/cancel')->assertOk()
            ->assertJsonPath('status', 'cancelled');
        $this->assertSame($originalStock, $product->fresh()->stock);
    }

    public function test_users_cannot_access_each_others_cart_items_or_orders(): void
    {
        $this->seed([CategorySeeder::class, ProductSeeder::class]);
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $product = Product::query()->firstOrFail();
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1]);
        $item = CartItem::query()->firstOrFail();
        $order = $this->postJson('/api/orders', ['address' => 'Address'])->json();

        Sanctum::actingAs(User::factory()->create());
        $this->patchJson('/api/cart/items/'.$item->id, ['quantity' => 1])->assertNotFound();
        $this->getJson('/api/orders/'.$order['id'])->assertNotFound();
        $this->postJson('/api/orders/'.$order['id'].'/cancel')->assertNotFound();
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();

        $user = User::factory()->create(['telegram_id' => '77']);
        Sanctum::actingAs($user);
        $this->getJson('/api/profile')->assertOk()->assertJsonPath('telegram_id', '77');
    }

    public function test_user_can_create_bakong_qr_and_verify_matching_payment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $order = $user->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 12.50,
        ]);

        $paymentResponse = $this->postJson('/api/orders/'.$order->id.'/payment')
            ->assertCreated()
            ->assertJsonPath('amount', '12.50')
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('status', 'pending')
            ->assertJsonStructure(['id', 'md5', 'khqr', 'qr_url', 'expires_at']);
        $payment = $paymentResponse->json();
        $this->assertStringContainsString('99340013', $payment['khqr']);

        $this->get('/api/payments/'.$payment['id'].'/qr')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertSee('<svg', false);

        Http::fake([
            'https://api-bakong.example/v1/check_transaction_by_md5' => Http::response([
                'responseCode' => 0,
                'responseMessage' => 'Success',
                'data' => [
                    'hash' => str_repeat('a', 64),
                    'toAccountId' => 'shop@bank',
                    'currency' => 'USD',
                    'amount' => 12.50,
                ],
            ]),
        ]);

        $this->postJson('/api/payments/'.$payment['id'].'/check')
            ->assertOk()
            ->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/payments/'.$payment['id'])->assertNotFound();
    }

    public function test_user_can_open_payway_checkout_and_verify_an_approved_payment(): void
    {
        config()->set('services.payway', [
            'enabled' => true,
            'merchant_id' => 'test-merchant',
            'api_key' => 'test-api-key',
            'base_url' => 'https://checkout-sandbox.payway.test',
            'currency' => 'USD',
            'payment_option' => '',
            'continue_url' => 'https://shop.example.test/',
        ]);
        $user = User::factory()->create(['name' => 'Dara Nhim']);
        Sanctum::actingAs($user);
        $order = $user->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 15.25,
        ]);

        $payment = $this->postJson('/api/orders/'.$order->id.'/payway-payment')
            ->assertCreated()
            ->assertJsonPath('provider', 'payway')
            ->assertJsonPath('amount', '15.25')
            ->assertJsonPath('checkout.fields.merchant_id', 'test-merchant')
            ->assertJsonPath('checkout.fields.amount', '15.25')
            ->assertJsonStructure(['id', 'reference', 'checkout' => ['url', 'fields' => ['hash', 'tran_id']]])
            ->json();

        $this->assertArrayNotHasKey('khqr', $payment);
        $this->assertDatabaseHas('payments', [
            'id' => $payment['id'],
            'provider' => 'payway',
            'md5' => null,
        ]);

        Http::fake([
            'https://checkout-sandbox.payway.test/api/payment-gateway/v1/payments/check-transaction-2' => Http::response([
                'data' => [
                    'payment_status' => 'APPROVED',
                    'original_amount' => 15.25,
                    'payment_currency' => 'USD',
                    'apv' => '123456',
                ],
                'status' => ['code' => '00', 'message' => 'Success'],
            ]),
        ]);

        $this->postJson('/api/payments/'.$payment['id'].'/payway-check')
            ->assertOk()
            ->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'paid']);
    }

    public function test_payway_checkout_requires_merchant_credentials(): void
    {
        config()->set('services.payway.enabled', false);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $order = $user->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 5,
        ]);

        $this->postJson('/api/orders/'.$order->id.'/payway-payment')
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'ABA PayWay is not enabled. Set PAYWAY_ENABLED=true after adding your merchant credentials.');
    }

    public function test_individual_account_type_uses_solo_merchant_tag_even_when_merchant_fields_exist(): void
    {
        config()->set('services.bakong.account_type', 'individual');
        config()->set('services.bakong.merchant_id', 'shop@bank');
        config()->set('services.bakong.acquiring_bank', 'ABC');

        $generated = app(BakongKhqr::class)->generate('1.00', 'USD', 'ORDER-1');

        $this->assertStringStartsWith('00020101021229', $generated['payload']);
        $this->assertSame('29', substr($generated['payload'], 12, 2));
    }

    public function test_user_can_renew_an_expired_bakong_qr_without_creating_another_payment(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $order = $user->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 8.25,
        ]);

        $original = $this->postJson('/api/orders/'.$order->id.'/payment')->assertCreated()->json();
        Payment::query()->findOrFail($original['id'])->update(['expires_at' => now()->subMinute()]);
        $this->travel(1)->seconds();

        $renewed = $this->postJson('/api/orders/'.$order->id.'/payment')
            ->assertCreated()
            ->assertJsonPath('id', $original['id'])
            ->assertJsonPath('status', 'pending')
            ->json();

        $this->assertNotSame($original['md5'], $renewed['md5']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertTrue(Payment::query()->findOrFail($original['id'])->expires_at->isFuture());
    }

    public function test_payment_is_regenerated_when_the_khqr_account_type_changes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $order = $user->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 2.50,
        ]);
        config()->set('services.bakong.account_type', 'merchant');
        config()->set('services.bakong.merchant_id', 'merchant-123');
        config()->set('services.bakong.acquiring_bank', 'Test Bank');
        $merchantPayment = $this->postJson('/api/orders/'.$order->id.'/payment')->assertCreated()->json();
        $this->assertSame('30', substr($merchantPayment['khqr'], 12, 2));

        config()->set('services.bakong.account_type', 'individual');
        $individualPayment = $this->postJson('/api/orders/'.$order->id.'/payment')
            ->assertCreated()
            ->assertJsonPath('id', $merchantPayment['id'])
            ->json();

        $this->assertSame('29', substr($individualPayment['khqr'], 12, 2));
        $this->assertNotSame($merchantPayment['md5'], $individualPayment['md5']);

        config()->set('services.bakong.account_id', 'another-shop@bank');
        $newReceiverPayment = $this->postJson('/api/orders/'.$order->id.'/payment')
            ->assertCreated()
            ->assertJsonPath('id', $merchantPayment['id'])
            ->json();
        $this->assertStringContainsString('another-shop@bank', $newReceiverPayment['khqr']);
        $this->assertNotSame($individualPayment['md5'], $newReceiverPayment['md5']);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_qr_image_endpoint_self_heals_a_stored_payment_with_the_old_account_type(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $order = $user->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 3.00,
        ]);
        config()->set('services.bakong.account_type', 'merchant');
        config()->set('services.bakong.merchant_id', 'merchant-123');
        config()->set('services.bakong.acquiring_bank', 'Test Bank');
        $payment = $this->postJson('/api/orders/'.$order->id.'/payment')->assertCreated()->json();

        config()->set('services.bakong.account_type', 'individual');
        $this->get('/api/payments/'.$payment['id'].'/qr')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml');

        $stored = Payment::query()->findOrFail($payment['id']);
        $this->assertSame('29', substr($stored->khqr_payload, 12, 2));
        $this->assertNotSame($payment['md5'], $stored->md5);
    }

    public function test_usd_shop_total_is_converted_when_bakong_receiver_uses_khr(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $order = $user->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 2.50,
        ]);
        config()->set('services.bakong.shop_currency', 'USD');
        config()->set('services.bakong.currency', 'KHR');
        config()->set('services.bakong.usd_to_khr_rate', 4000);

        $payment = $this->postJson('/api/orders/'.$order->id.'/payment')
            ->assertCreated()
            ->assertJsonPath('currency', 'KHR')
            ->assertJsonPath('amount', '10000.00')
            ->json();

        $this->assertStringContainsString('5303116', $payment['khqr']);
        $this->assertStringContainsString('540510000', $payment['khqr']);
    }

    public function test_only_admin_can_manage_categories_and_products(): void
    {
        $customer = User::factory()->create(['is_admin' => false]);
        Sanctum::actingAs($customer);
        $this->postJson('/api/admin/categories', ['name' => 'Forbidden'])->assertForbidden();

        $admin = User::factory()->create(['is_admin' => true]);
        Sanctum::actingAs($admin);

        $category = $this->postJson('/api/admin/categories', ['name' => 'Books'])
            ->assertCreated()
            ->assertJsonPath('slug', 'books')
            ->json();

        $product = $this->postJson('/api/admin/products', [
            'category_id' => $category['id'],
            'name' => 'Laravel Handbook',
            'description' => 'A practical guide.',
            'image_url' => 'https://example.com/book.jpg',
            'price' => 24.99,
            'stock' => 12,
        ])->assertCreated()
            ->assertJsonPath('slug', 'laravel-handbook')
            ->json();

        $this->patchJson('/api/admin/products/'.$product['id'], [
            'price' => 19.99,
            'stock' => 20,
        ])->assertOk()
            ->assertJsonPath('price', '19.99')
            ->assertJsonPath('stock', 20);

        $this->getJson('/api/admin/products')->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total']);
        $this->deleteJson('/api/admin/products/'.$product['id'])->assertNoContent();
        $this->deleteJson('/api/admin/categories/'.$category['id'])->assertNoContent();
    }

    public function test_admin_can_manage_users_orders_and_view_payments_and_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create(['is_admin' => false, 'telegram_id' => '9988']);
        $order = $customer->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'paid',
            'total' => 15.00,
        ]);
        $payment = $order->payment()->create([
            'provider' => 'bakong',
            'status' => 'paid',
            'amount' => 15.00,
            'currency' => 'USD',
            'khqr_payload' => 'test-khqr',
            'md5' => md5('test-khqr'),
            'expires_at' => now()->addMinutes(15),
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('counts.users', 2)
            ->assertJsonPath('counts.orders', 1)
            ->assertJsonPath('revenue', 15);
        $this->getJson('/api/admin/users')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'is_admin', 'orders_count']]]);
        $this->patchJson('/api/admin/users/'.$customer->id, ['is_admin' => true])
            ->assertOk()->assertJsonPath('is_admin', true);
        $this->getJson('/api/admin/orders')->assertOk()
            ->assertJsonPath('data.0.id', $order->id);
        $this->patchJson('/api/admin/orders/'.$order->id, ['status' => 'processing'])
            ->assertOk()->assertJsonPath('status', 'processing');
        $this->getJson('/api/admin/payments')->assertOk()
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonMissing(['khqr_payload', 'provider_response']);
    }

    public function test_admin_cannot_fake_payment_or_remove_own_role(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create(['is_admin' => false]);
        $order = $customer->orders()->create([
            'address' => 'Phnom Penh',
            'status' => 'pending',
            'total' => 5.00,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson('/api/admin/orders/'.$order->id, ['status' => 'paid'])
            ->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$admin->id, ['is_admin' => false])
            ->assertUnprocessable();
        $this->postJson('/api/admin/payments', ['status' => 'paid'])->assertMethodNotAllowed();
    }

    public function test_database_admin_role_persists_across_telegram_sign_in(): void
    {
        $initData = $this->telegramInitData(445566, 'Shop', 'Admin');
        User::factory()->create([
            'telegram_id' => '445566',
            'email' => '445566@telegram.local',
            'is_admin' => true,
        ]);

        $this->postJson('/api/auth/telegram', ['init_data' => $initData])
            ->assertOk()
            ->assertJsonPath('user.is_admin', true);
        $this->assertDatabaseHas('users', ['telegram_id' => '445566', 'is_admin' => true]);
    }

    public function test_admin_can_upload_a_product_image_stored_in_the_database(): void
    {
        $this->seed([CategorySeeder::class]);
        Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
        $category = Category::query()->firstOrFail();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        $product = $this->post('/api/admin/products', [
            'category_id' => $category->id,
            'name' => 'Uploaded Image Product',
            'price' => 9.99,
            'stock' => 3,
            'image' => UploadedFile::fake()->createWithContent('product.png', $png),
        ])->assertCreated()
            ->assertJsonStructure(['id', 'image_url', 'uploaded_image_id'])
            ->json();

        $this->assertDatabaseHas('uploaded_images', ['id' => $product['uploaded_image_id'], 'mime_type' => 'image/png']);
        $storedImage = UploadedImage::query()->findOrFail($product['uploaded_image_id']);
        $storedData = is_resource($storedImage->data) ? stream_get_contents($storedImage->data) : $storedImage->data;
        $this->assertTrue(mb_check_encoding($storedData, 'UTF-8'));
        $this->assertSame($png, base64_decode($storedData, true));
        $path = parse_url($product['image_url'], PHP_URL_PATH);
        $this->get($path)->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function telegramInitData(int $id, string $firstName, string $lastName): string
    {
        $fields = [
            'auth_date' => (string) time(),
            'query_id' => 'AAExample',
            'user' => json_encode([
                'id' => $id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => strtolower($firstName),
            ], JSON_UNESCAPED_SLASHES),
        ];
        ksort($fields);
        $checkString = collect($fields)->map(fn ($value, $key) => $key.'='.$value)->implode("\n");
        $secretKey = hash_hmac('sha256', self::BOT_TOKEN, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $checkString, $secretKey);

        return http_build_query($fields);
    }
}
