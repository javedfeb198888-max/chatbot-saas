# Deploying to a VPS

These steps are for Ubuntu 24.04 with nginx and PHP-FPM. If you use CloudPanel,
create the site and the PHP 8.3 pool in the panel and use these steps for
everything else. The CloudPanel screens themselves are not covered here.

Replace `app.example.com` with your own domain everywhere.

## 1. What the server needs

- PHP 8.3 with: `pdo_pgsql`, `pgsql`, `mbstring`, `xml`, `curl`, `zip`, `intl`, `bcmath`
- Composer 2
- PostgreSQL 16 with the pgvector extension
- nginx with HTTPS (the widget is loaded on clients' HTTPS sites, so the app must be HTTPS too)
- Supervisor, to keep the queue worker running

```bash
sudo apt update
sudo apt install -y postgresql-16 postgresql-16-pgvector supervisor
```

## 2. Database

```bash
sudo -u postgres psql
```

```sql
CREATE USER chatbot WITH PASSWORD 'a-long-random-password';
CREATE DATABASE chatbot_saas OWNER chatbot;
\c chatbot_saas
CREATE EXTENSION IF NOT EXISTS vector;
\q
```

The extension must be created by the `postgres` superuser, as above. The app's
own database user cannot create it.

## 3. Code

```bash
cd /home/YOUR-SITE-USER/htdocs
git clone https://github.com/javedfeb198888-max/chatbot-saas.git app.example.com
cd app.example.com
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Point the site's document root at the `public` folder.

## 4. Settings in `.env`

```dotenv
APP_NAME="Your Product Name"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=chatbot_saas
DB_USERNAME=chatbot
DB_PASSWORD=a-long-random-password

QUEUE_CONNECTION=database
SESSION_DRIVER=database
CACHE_STORE=database

# First admin login (used once by db:seed)
SUPER_ADMIN_NAME="Your Name"
SUPER_ADMIN_EMAIL=you@example.com
SUPER_ADMIN_PASSWORD=a-strong-password

# AI
OPENAI_API_KEY=sk-...            # embeddings (training and search)
ANTHROPIC_API_KEY=sk-ant-...     # chat replies
CHATBOT_CHAT_PROVIDER=anthropic
CHATBOT_CHAT_MODEL=claude-haiku-5-5

# Email (client invites and password resets)
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

`APP_URL` matters: it is used in the widget code shown to clients and in invite
emails. `APP_DEBUG` must be `false` in production.

## 5. First run

```bash
php artisan migrate --force
php artisan db:seed --force        # creates the super admin
php artisan filament:assets
php artisan optimize
```

Make `storage` and `bootstrap/cache` writable by the PHP-FPM user.

After the super admin exists, remove `SUPER_ADMIN_PASSWORD` from `.env`.

## 6. nginx: let chat replies stream

Chat replies are sent as a stream. nginx must not hold them back. In the PHP
location block of the site:

```nginx
location ~ \.php$ {
    # ... your existing fastcgi settings ...
    fastcgi_buffering off;
    fastcgi_read_timeout 120s;
}
```

Also make sure gzip is not applied to `text/event-stream` (it is not in nginx's
default `gzip_types`).

## 7. Queue worker (training runs here)

`/etc/supervisor/conf.d/chatbot-worker.conf`:

```ini
[program:chatbot-worker]
command=php /home/YOUR-SITE-USER/htdocs/app.example.com/artisan queue:work --sleep=2 --tries=2 --timeout=120 --max-time=3600
user=YOUR-SITE-USER
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=130
redirect_stderr=true
stdout_logfile=/home/YOUR-SITE-USER/logs/chatbot-worker.log
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl status chatbot-worker
```

Without the worker, training content stays on "Pending".

## 8. Backups

The server is yours, so backups are too. A daily dump, copied off the server:

```bash
pg_dump -U chatbot -h 127.0.0.1 chatbot_saas | gzip > /backups/chatbot_$(date +%F).sql.gz
```

## 9. Updating later

```bash
cd /home/YOUR-SITE-USER/htdocs/app.example.com
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan filament:assets
php artisan optimize
sudo supervisorctl restart chatbot-worker
```

## 10. First test after deploy

1. Sign in at `https://app.example.com/admin` with the super admin.
2. Create a client. Check that the invite email arrives and the link opens.
3. Sign in as the client at `/app`. Add one page under Training and wait for "Ready".
4. Open Settings > Bot, copy the widget code and paste it into a test page.
5. Ask the widget a question that the page answers. The reply should appear word by word.
6. Enter the Cal.com details, press "Test calendar", then ask the bot to book a time.
7. Check Inbox > Conversations, Leads and Bookings.

## Not tested before deploy

These need real keys or a real server, so they were only tested with stand-ins:

- Real embeddings from OpenAI and real chat replies from Anthropic.
- Creating a booking on Cal.com (reading free slots was tested against the live API).
- Sending email through SMTP.
- Streaming through nginx and PHP-FPM (tested with PHP's built-in server).
