# Chatbot SaaS

Multi-client AI chatbot: each client gets a login, a trainable bot, a website widget and calendar booking.

Stack: Laravel 13 (PHP 8.3), Filament 5, PostgreSQL 16 + pgvector.

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

## Signing in

| Who | URL | Notes |
| --- | --- | --- |
| Platform owner (super admin) | `/admin` | Created by `php artisan db:seed` from `.env` |
| Client users | `/app` | Created from the admin panel; they get an invite email |

Run the app locally with `php artisan serve`.

With `MAIL_MAILER=log` (the default), invite emails are not sent. The email text,
including the set-password link, is written to `storage/logs/laravel.log`.
Set real SMTP details in `.env` before inviting real clients.

Access rules live in `app/Policies`. Filament enforces them on every page and action.

## Training

Clients add training content at `/app/training` (web pages, sitemaps, pasted text)
and `/app/questions` (question and answer pairs). Each item becomes text chunks with
embeddings in the `chunks` table.

- Training runs on the queue. Keep a worker running: `php artisan queue:work`.
- Set `OPENAI_API_KEY` in `.env`. Embeddings use `text-embedding-3-small` (1536 dimensions).
- The crawler only fetches public http/https sites. Private and local addresses are
  refused, on the first request and on every redirect (`app/Training/UrlGuard.php`).
- Pages whose text only appears after JavaScript runs are not supported yet.
- Limits and chunk sizes are in `config/chatbot.php`.

## Chat and widget

A client pastes one script tag into their site (shown under Settings > Bot):

```html
<script async src="https://YOUR-APP/widget.js" data-bot="BOT_PUBLIC_KEY"></script>
```

- `public/widget.js` is plain JavaScript in a Shadow DOM, so the site's CSS and the widget's CSS cannot affect each other.
- The widget calls `/api/widget/{public_key}/...` (`routes/widget.php`). The server finds the bot by its public key, checks the calling website against the bot's allowed websites, and locks the request to that bot's client.
- Each question is answered from the bot's own training chunks only, and the reply is streamed.
- Limits: per-visitor and per-bot rate limits, and each client's monthly message limit. Usage and estimated cost are stored in `usage_daily`.

## Booking

Clients connect Google or Outlook inside Cal.com and enter their Cal.com username
and event type under Settings > Bot. The bot then has tools to read free slots and
book. The server checks a slot is still free before booking and never books the
same slot twice in one conversation. Without a calendar, the bot collects contact
details instead.

## Deploying

See [docs/DEPLOY.md](docs/DEPLOY.md). It also lists what could not be tested before a real deploy.

## How client data is kept apart

- Every table that holds client data has a `client_id` column.
- Models use the `BelongsToClient` trait, which filters every query to the current client and stamps `client_id` on new rows.
- `App\Support\CurrentClient` holds the current client. Web requests start with no access; the `SetCurrentClient` middleware sets the client from the logged-in user, or opens all clients for the super admin.
- Both panels run `SetCurrentClient` on page loads and on Livewire update requests.
- `User` is the one model that is not client-scoped (login must find any user). The Team page filters by client itself.
- `tests/Feature/ClientIsolationTest.php`, `PanelAccessTest.php`, `LivewireIsolationTest.php`, `TrainingTest.php`, `TrainingPanelTest.php`, `WidgetApiTest.php`, `BookingToolsTest.php` and `DashboardTest.php` prove these rules. Keep them passing.

## Build status

- [x] Stage 1a: schema, models, client isolation, tests
- [x] Stage 1b: login, super admin panel (clients), client panel (bot settings, team), invite emails
- [x] Stage 2: training (web pages, sitemaps, text, Q&A, embeddings)
- [x] Stage 3: chat API and widget
- [x] Stage 4: booking and lead capture
- [x] Stage 5: client dashboard (conversations, leads, bookings, usage)
- [ ] Stage 6: deploy and pilot with real API keys
