# Telegram Shop Backend

Laravel 12 JSON API for a Telegram Mini App shop. It includes verified Telegram authentication, Sanctum bearer tokens, a product catalog, per-user carts, and stock-aware order creation and cancellation.

## Setup

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Set `TELEGRAM_BOT_TOKEN` in `.env` to the token received from BotFather. The frontend must send Telegram's untouched `window.Telegram.WebApp.initData` value:

```json
POST /api/auth/telegram
{
  "init_data": "query_id=...&user=...&auth_date=...&hash=..."
}
```

Use the returned token on protected endpoints with `Authorization: Bearer <token>` and `Accept: application/json`.

## API

Public endpoints:

- `POST /api/auth/telegram`
- `GET /api/categories` and `GET /api/categories/{id}`
- `GET /api/products` and `GET /api/products/{id}`
- Product filters: `GET /api/products?category_id=1&search=phone`

Authenticated endpoints:

- `GET /api/cart`
- `POST /api/cart/items`
- `PATCH /api/cart/items/{id}`
- `DELETE /api/cart/items/{id}` and `DELETE /api/cart`
- `POST /api/orders`
- `GET /api/orders` and `GET /api/orders/{id}`
- `POST /api/orders/{id}/cancel`
- `GET /api/profile`

Run the test suite with `php artisan test`.
