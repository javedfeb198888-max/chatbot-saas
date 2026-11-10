# Chatbot SaaS

Multi-client AI chatbot: each client gets a login, a trainable bot, a website widget and calendar booking.

Stack: Laravel 13 (PHP 8.3), PostgreSQL 16 + pgvector.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Create the databases (PostgreSQL with the pgvector extension installed):

```sql
CREATE USER chatbot WITH PASSWORD 'choose-a-password' CREATEDB;
CREATE DATABASE chatbot_saas OWNER chatbot;
CREATE DATABASE chatbot_saas_test OWNER chatbot;
-- run in each database as a superuser:
CREATE EXTENSION IF NOT EXISTS vector;
```

Put the password in `.env` (`DB_PASSWORD`), set `SUPER_ADMIN_EMAIL` and `SUPER_ADMIN_PASSWORD`, then:

```bash
php artisan migrate
php artisan db:seed     # creates the super admin
php artisan test
```

## How client data is kept apart

- Every table that holds client data has a `client_id` column.
- Models use the `BelongsToClient` trait, which filters every query to the current client and stamps `client_id` on new rows.
- `App\Support\CurrentClient` holds the current client. Web requests start with no access; the `SetCurrentClient` middleware sets the client from the logged-in user, or opens all clients for the super admin.
- `tests/Feature/ClientIsolationTest.php` proves these rules. Keep it passing.

## Build status

- [x] Stage 1a: schema, models, client isolation, tests
- [ ] Stage 1b: login screens, super admin panel, client invite
- [ ] Stage 2: training (sources, crawler, embeddings)
- [ ] Stage 3: chat API and widget
- [ ] Stage 4: booking
- [ ] Stage 5: client dashboard
- [ ] Stage 6: pilot
