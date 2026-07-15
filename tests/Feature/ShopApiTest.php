<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
