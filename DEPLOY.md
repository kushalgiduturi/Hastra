# Deploying Hastra

Hastra ships as a Docker stack: the app (PHP 8.2 + Apache), MariaDB, and Caddy,
which fetches and renews a free HTTPS certificate on its own. The same three
commands work on any Linux server.

> **Why a VPS and not shared hosting?** Shared hosts can't run Docker. The
> stack below needs a small virtual server (a VPS). 1 vCPU and 2 GB RAM are
> plenty: Hetzner, DigitalOcean, Linode or AWS Lightsail all cost about
> $5–12 a month.

> **Why Apache and not Nginx?** The app's file-access rules live in its
> `.htaccess` files. They keep the encryption keys (`config/*.key`), the admin
> scripts (`tools/`), backups and tests off the web. Nginx ignores
> `.htaccess`, so serving this project with Nginx would publish all of them.

---

## 1. Server and domain

1. Create an **Ubuntu 24.04** server and note its public IP address.
2. At your domain registrar, add two DNS records pointing at that IP:
   - `A`  `@`   → server IP
   - `A`  `www` → server IP
3. Log in over SSH and open the firewall for SSH and the web:
   ```bash
   sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
   ```
4. Install Docker (official script):
   ```bash
   curl -fsSL https://get.docker.com | sudo sh
   sudo usermod -aG docker $USER && newgrp docker
   ```

## 2. Get the code and configure it

```bash
git clone https://github.com/kushalgiduturi/Hastra.git
cd Hastra
cp .env.example .env
chmod 600 .env
nano .env
```

Fill in every value in `.env`:

| Setting | What to put |
| --- | --- |
| `HASTRA_DOMAIN` / `HASTRA_APP_URL` | your domain, e.g. `hastra.in` and `https://hastra.in/` |
| `HASTRA_DB_PASS`, `HASTRA_DB_ROOT_PASS` | two long random passwords (`openssl rand -base64 30`) |
| `HASTRA_MAIL_*` | an SMTP provider (Brevo, Mailgun, Amazon SES, or a Gmail *app password*). **Sign-in codes are emailed, so without working mail nobody can sign in.** |
| `HASTRA_SYSADMIN_EMAIL` | the email you'll register with; it becomes the primary sysadmin |
| `HASTRA_GOOGLE_*`, `HASTRA_RECAPTCHA_SECRET` | from Google (step 4) |
| `HASTRA_YOUTUBE_API_KEY` | optional: ranked videos in the Labs study hub |

`.env` holds secrets. It's git-ignored; never commit it or paste it anywhere.

### Using a managed database instead of the self-hosted `db` container

The stack ships with a self-hosted MariaDB container, but you can point it at
any managed MySQL/MariaDB instead — for example a free-tier
[Aiven](https://aiven.io) MySQL service:

1. In `.env`, set `HASTRA_DB_HOST`, `HASTRA_DB_PORT`, `HASTRA_DB_USER`,
   `HASTRA_DB_PASS` and `HASTRA_DB_NAME` to the values from your provider's
   dashboard (Aiven calls these Host, Port, User, Password, Database name).
2. Managed databases require TLS. Download the provider's CA certificate and
   save it as `config/db-ca.pem` in the repo (a CA cert is public, not a
   secret — it's fine to commit). Set `HASTRA_DB_SSL_CA=/var/www/html/config/db-ca.pem`
   in `.env`.
3. Remove the `db` service, its `volumes: hastra-db` entry, and the `app`
   service's `depends_on: db` block from `docker-compose.yml`, since nothing
   needs to be self-hosted anymore.
4. `docker compose up -d --build`. The entrypoint waits for the managed
   database over TLS, creates the schema on first start, and runs migrations
   exactly as it would for the local container.

If you regenerate or rotate the database password on the provider's side,
update `HASTRA_DB_PASS` in `.env` and run `docker compose up -d` again.

## 3. Start it

```bash
docker compose up -d --build
docker compose logs -f app      # Ctrl+C to stop following
```

On the first start the app waits for the database, creates the schema, runs
every migration and generates its encryption keys. Once you see Apache
starting, open `https://your-domain/`. The HTTPS certificate is issued
automatically in the first minute; DNS must already point at the server.

## 4. Point Google services at the new domain

- **Google sign-in:** in Google Cloud Console → APIs & Services → Credentials →
  your OAuth client, add:
  - Authorised redirect URI: `https://your-domain/auth/google_auth.php`
  - Authorised JavaScript origin: `https://your-domain`
- **reCAPTCHA:** in the reCAPTCHA admin console, open your site key and add
  your domain to its domain list. The site key in `auth/login.php` stays the
  same; the secret goes in `HASTRA_RECAPTCHA_SECRET`.
- **YouTube (optional):** restrict the API key to the *YouTube Data API v3*
  and to your server's IP address.

After editing `.env`, apply it with `docker compose up -d`.

## 5. First sign-in

Open `https://your-domain/signup` and register with `HASTRA_SYSADMIN_EMAIL`.
That account is the primary sysadmin.

## 6. Back up. The keys matter most.

The encryption keys live in the `hastra-keys` Docker volume. **If you lose
them, every encrypted field (names, emails, phone numbers, credentials)
becomes permanently unreadable.** Back them up off the server now and after
any key rotation:

```bash
# encryption keys
docker run --rm -v hastra_hastra-keys:/k -v "$PWD":/out alpine tar czf /out/hastra-keys.tgz -C /k .
# database
docker compose exec db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' > hastra-db.sql
```

Copy both files somewhere safe (not the same server). Keep `hastra-keys.tgz`
as private as a password.

## 7. Updating

```bash
cd Hastra && git pull && docker compose up -d --build
```

Migrations run automatically on every start and are safe to repeat.

## 8. Check the live site

- `https://your-domain/`: the temple scene, the HASTRA letters with the ten
  rings behind them, and the Labs showcase below the counters.
- `https://your-domain/signin`: the hand rises, the rings charge, the card
  breaks out of the palm. Sign in; the emailed code must arrive.
- `https://your-domain/labs/crypto#selftest`: run the self-test; every row
  must pass (this needs HTTPS, which Caddy provides).
- `https://your-domain/labs/syllabus`: try the demo syllabus.
- Toggle light and dark theme.
- These must all be refused: `https://your-domain/config/astra_db.key`,
  `/tools/backup_db.php`, `/tests/`, `/.env`.

## Trying the stack locally first

With Docker Desktop running (and XAMPP's Apache stopped, or using this port):

```bash
cp .env.example .env          # set HASTRA_APP_URL=http://localhost:8088/
docker compose -f docker-compose.yml -f docker/compose.localtest.yml up -d --build db app
```

Then open `http://localhost:8088/`.
