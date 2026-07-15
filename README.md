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

## Docker

After configuring `.env`, build and start the API:

```powershell
docker compose up --build
```

The API will be available at `http://localhost:8000`. PostgreSQL data and Laravel storage are kept in named Docker volumes. Migrations and demo seeders run automatically when the local containers start.

pgAdmin is available at `http://localhost:5050`. The default development login is `admin@example.com` / `change-me`; change these values in `.env`. Register the database server in pgAdmin with:

- Host: `db`
- Port: `5432`
- Maintenance database: `telegram_shop`
- Username: `telegram_shop`
- Password: the `DB_PASSWORD` value from `.env`

On Render, attach a managed PostgreSQL database and set its internal connection URL as `DATABASE_URL` (or `DB_URL`). Apache automatically binds to Render's runtime `PORT` variable.
