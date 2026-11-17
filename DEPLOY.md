# Deploying ReserveVision

ReserveVision is **deployable, not hosted** ([ADR 0007](docs/adr/0007-deployable-not-hosted.md)): nothing runs anywhere by default, but every PR proves that the production stack builds, boots, migrates and serves pages (the `Production` workflow). This guide covers running it on a single Docker host.

## What runs

[compose.prod.yaml](compose.prod.yaml) runs one image ([Dockerfile](Dockerfile)) as four services, plus the infrastructure around them:

| Service | What it does |
| --- | --- |
| `caddy` | Reverse proxy on ports 80 and 443. Gets and renews the HTTPS certificate for `DOMAIN` by itself. Serves public photos at `/media/photos/*` from the photos bucket |
| `app` | FrankenPHP (PHP 8.4, OPcache) on port 8000, reachable only from Caddy |
| `ssr` | Inertia SSR server (`node bootstrap/ssr/app.js`); the app calls it at `http://ssr:13714` |
| `worker` | `php artisan queue:work --max-time=3600` (restarts hourly) |
| `scheduler` | `php artisan schedule:work` |
| `db` | MySQL 8.4 with the `app` and `migrator` users ([ADR 0008](docs/adr/0008-least-privilege-database-users.md)), volume `db-data` |
| `minio` | S3-compatible storage, volume `minio-data`. Buckets: photos (public read) and proofs (private) |
| `minio-setup` | One-shot: creates the buckets, their access policies and the app's own MinIO user, then exits |

Only Caddy publishes ports. The database, MinIO and the app are on the internal Compose network.

## Host requirements

- Linux on x86-64 or arm64, with Docker Engine 24+ and Docker Compose v2.24+.
- At least 2 vCPUs, 2 GB RAM and 20 GB of disk. The idle stack uses about 1 GB of RAM; building the image needs about 1.5 GB more for a few minutes.
- `git`, `curl` and `openssl`.
- Inbound TCP 80 and 443 (and UDP 443 for HTTP/3) open to the internet. Nothing else needs to be open.
- A domain name you control.

## DNS and HTTPS

1. Create an `A` record (and `AAAA` for IPv6) for your domain, for example `reservevision.example.com`, pointing at the server.
2. Wait until `dig +short reservevision.example.com` returns the server's address.
3. Set `DOMAIN=reservevision.example.com` and `ACME_EMAIL=` (your address) in `.env.production`.

On its first start Caddy requests a certificate from Let's Encrypt (falling back to ZeroSSL), renews it automatically and redirects HTTP to HTTPS. Certificates live in the `caddy-data` volume; don't delete it, or Caddy will request new ones and may hit rate limits. If issuance fails, `docker compose -f compose.prod.yaml --env-file .env.production logs caddy` says why (usually DNS not pointing at the host yet, or port 80 blocked).

The app sends HSTS (`max-age` one year, `includeSubDomains`). Once a browser has seen it, that domain and all its subdomains are HTTPS-only for a year.

## First deploy

```bash
git clone https://github.com/bienthehuman/reserve-vision.git /srv/reservevision
cd /srv/reservevision
cp .env.production.example .env.production
chmod 600 .env.production
```

Fill in every empty value in `.env.production`; each variable is explained in [.env.production.example](.env.production.example). Generate the secrets with:

```bash
echo "base64:$(openssl rand -base64 32)"   # APP_KEY
openssl rand -hex 24                       # each password and AWS_SECRET_ACCESS_KEY
```

Keep a copy of `.env.production` somewhere safe (a password manager). Without `APP_KEY` the encrypted data and sessions can't be read.

Then deploy a release tag:

```bash
./deploy.sh v0.1.0
```

[deploy.sh](deploy.sh) checks the env file, checks out the tag, builds the image, starts MySQL and MinIO (the first start creates the database users and buckets), migrates as `migrator`, imports the places (`php artisan places:import`, as `app`: every province, city and barangay of the PSA's PSGC list, from `database/data/psgc/` in the image; about 2 seconds, and a no-op when nothing changed), starts everything, runs `php artisan optimize` in each PHP container, runs `php artisan app:doctor` and finally requests `https://<DOMAIN>/up`. If any step fails it stops, says which step, and prints the rollback command.

Nothing is seeded in production: `db:seed` there loads only the amenity catalogue, and `DemoSeeder` refuses to run unless `APP_DEMO=true` is set. Leave it `false` on a real site. For a trial server you may set it and run `docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan db:seed --force`, which creates the [demo logins](README.md#try-it) (password `password`, so never on a public site with real users). With `APP_DEMO=true` the login page also lists those accounts.

### Verifying landlord accounts

Landlords can't publish until the operator verifies their account (there is no admin web role; see [ACCESS.md](docs/ACCESS.md)). On the server:

```bash
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan landlord:verify --list         # who is waiting
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan landlord:verify <slug>          # verify
docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan landlord:verify <slug> --revoke # revoke and unpublish every property of the account
```

It runs as the `app` database user and exits non-zero for an unknown slug.

### Checking a running deployment

```bash
alias rv='docker compose -f compose.prod.yaml --env-file .env.production'
rv ps                                  # every service "healthy" (minio-setup "exited (0)")
rv exec app php artisan app:doctor     # database users, queue table, storage, images, mail
rv logs -f --tail=100 app worker       # logs (JSON from Caddy, Laravel to stderr)
```

`app:doctor` prints a table and exits non-zero if anything fails:

| Check | Passes when |
| --- | --- |
| Database connection | The app connects as the `app` user |
| App user has no DDL | `CREATE TABLE` is denied to `app` (error 1142) |
| Queue table | The `jobs` and `failed_jobs` tables can be read |
| Storage: photos / proofs | A temporary file can be written, read back and deleted on each disk |
| Image processing | PHP has GD with JPEG and WebP, and `exif`: a small JPEG goes through the same resize as an uploaded photo |
| Mail configuration | A valid `MAIL_FROM_ADDRESS`, and `MAIL_HOST` and `MAIL_PORT` for SMTP. In production, the `log` and `array` mailers fail it |

### Storage for photos

Each property photo is stored twice in the photos bucket, both as WebP: a thumbnail 480 px wide and a full size of at most 1600 px on the longer side. The original upload (up to 10 MB) is kept only until the worker has resized it, in the **proofs** bucket under `uploads/tmp/`, and is then deleted.

| | Typical | Upper end |
| --- | --- | --- |
| Thumbnail | 20–40 kB | 80 kB |
| Full size | 150–350 kB | 700 kB |
| One property with all 12 photos | about 4 MB | about 9 MB |

These are estimates for camera photos at WebP quality 80, not measurements from a live site; the demo set is real photographs (7.1): about 75 kB a pair on average, 38 MB for the 500 or so photos of the seeded demo, and a cold `db:seed` spends about 14 s resizing them. A thousand full properties is then 4 to 9 GB, so the 20 GB disk above is enough to start, and `minio-data` is the volume to watch. The worker needs memory for the decoded original: up to 160 MB for the largest accepted photo (40 megapixels), inside PHP's 256 MB limit. Photos are served with `Cache-Control: public, max-age=31536000, immutable`; a replaced photo gets a new URL.

`AWS_PHOTOS_URL` can stay empty with MinIO: photo URLs are then `/media/photos/…` on the site's own address, which Caddy serves from the bucket.

### Storage for payment proofs

Renters' proofs of payment (slice 5.3) go to the **proofs** bucket under `proofs/<random>.<ext>`, exactly as uploaded: JPEG, PNG, WebP or PDF, at most 5 MB each, usually a 100–500 kB phone screenshot. Nothing is resized or deleted: a proof is evidence, and a reservation can collect up to three (two rejected, one accepted). Budget about 1 MB per reservation; a thousand reservations is about 1 GB.

The bucket is **never public, and nothing in front of the app serves it**: Caddy has no route to it. Every view goes through the app: `/proofs/{id}` checks who is asking and redirects to a link signed for that user and valid 5 minutes, and that link streams the file through PHP after checking again ([ACCESS.md](docs/ACCESS.md#private-files)). The app only needs the bucket credentials it already has. Proofs are personal data: keep the bucket's backups encrypted ([Backups](#backups)).

## Updates

Releases are git tags. To deploy a newer one:

```bash
./deploy.sh v0.2.0
```

The script refuses to run if tracked files have local changes, so the code that runs is always exactly the tag. Back up the database before an update that includes migrations (see [Backups](#backups)). Old images stay on the host (`reservevision:<tag>`); clean them up now and then with `docker image prune`.

### A newer PSGC publication

The places ship with the code, so a newer PSA publication arrives as a release like any other: a developer converts it ([database/data/psgc/README.md](database/data/psgc/README.md), "Updating to a newer publication"), the PR shows the changed rows, and the next `./deploy.sh <tag>` imports it. The import adds new places and renames changed ones; it never changes a city's slug (listing URLs keep working) and never deletes a place (a property may point at it). It prints the places that left the list, and how many properties still have no linked city or barangay; their owners see the checklist item "A complete address" until they choose one. To run it by hand: `docker compose -f compose.prod.yaml --env-file .env.production exec app php artisan places:import`.

## Rollback

Deploy the previous tag again. `deploy.sh` prints the exact command at the end of every deploy and when a deploy fails:

```bash
./deploy.sh v0.1.0
```

This rolls back the code, not the database: migrations that the newer release ran stay applied. Migrations are therefore written so that the previous release still works with the new schema (add columns and tables first, remove them one release later). If a release has to be undone together with its schema, restore the backup taken before it (below), then deploy the previous tag.

## Backups

The database holds everything except uploaded files, which live in MinIO.

### Nightly database dump

A script, for example `/usr/local/bin/reservevision-backup`:

```bash
#!/usr/bin/env bash
set -euo pipefail
cd /srv/reservevision
backups=/var/backups/reservevision
mkdir -p "$backups"

# A consistent snapshot without locking tables (InnoDB). Root's password comes from the db container.
docker compose -f compose.prod.yaml --env-file .env.production exec -T db sh -c \
    'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --no-tablespaces "$RV_DB_DATABASE"' \
    | gzip > "$backups/db-$(date +%F).sql.gz.tmp"
mv "$backups/db-$(date +%F).sql.gz.tmp" "$backups/db-$(date +%F).sql.gz"

# Uploaded files: a copy of both buckets, with mc from the minio-setup image.
# As root (--user 0:0) so it can write to the host directory.
docker compose -f compose.prod.yaml --env-file .env.production run --rm --no-deps --user 0:0 \
    -v "$backups/files:/backup" --entrypoint sh minio-setup -c \
    'mc alias set rv http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null \
     && mc mirror --quiet --overwrite --remove "rv/$AWS_PHOTOS_BUCKET" "/backup/$AWS_PHOTOS_BUCKET" \
     && mc mirror --quiet --overwrite --remove "rv/$AWS_PROOFS_BUCKET" "/backup/$AWS_PROOFS_BUCKET"'

# Keep 14 days of dumps.
find "$backups" -name 'db-*.sql.gz' -mtime +14 -delete
```

and a cron entry for 02:30 every night:

```cron
30 2 * * * root /usr/local/bin/reservevision-backup >> /var/log/reservevision-backup.log 2>&1
```

Copy `/var/backups/reservevision` off the server too (for example with `rclone` or `restic`). A backup on the same disk doesn't survive losing the server. Payment proofs are personal data, so store the copies encrypted.

### Restoring

1. Stop everything that writes, keep the database running:

    ```bash
    rv stop app worker scheduler
    ```

2. Load the dump as root (the dump contains `CREATE TABLE`, which `app` can't run):

    ```bash
    gunzip -c /var/backups/reservevision/db-2026-11-14.sql.gz \
        | rv exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$RV_DB_DATABASE"'
    ```

3. Restore the files if needed:

    ```bash
    rv run --rm --no-deps --user 0:0 -v /var/backups/reservevision/files:/backup:ro --entrypoint sh minio-setup -c \
        'mc alias set rv http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null \
         && mc mirror --overwrite "/backup/$AWS_PHOTOS_BUCKET" "rv/$AWS_PHOTOS_BUCKET" \
         && mc mirror --overwrite "/backup/$AWS_PROOFS_BUCKET" "rv/$AWS_PROOFS_BUCKET"'
    ```

4. Deploy the tag that matches the backup, which also runs `app:doctor`:

    ```bash
    ./deploy.sh v0.1.0
    ```

Practise a restore on a throwaway host (see [Trial run](#trial-run-on-your-own-machine)) before you need it.

### Changing database passwords later

The `db` container creates the users only on its first start, with an empty `db-data` volume. To change a password afterwards, run [database/sql/create-users.sql](database/sql/create-users.sql) again as root with the new values, update `.env.production`, and redeploy:

```bash
{ printf "SET @db_name='reservevision', @app_password='%s', @migrator_password='%s';\n" "$NEW_APP" "$NEW_MIGRATOR"
  cat database/sql/create-users.sql; } | rv exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD"'
```

## Using an external S3 service

MinIO is there so the stack runs on one host without an account anywhere. To use AWS S3, Cloudflare R2, Backblaze B2, DigitalOcean Spaces or similar instead:

1. Create two buckets: one for photos with **public read of objects only** (no public listing), one for proofs with **all public access blocked**.
2. Create credentials limited to those two buckets (get, put, delete objects; list bucket).
3. In `.env.production`: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, the two bucket names, `AWS_ENDPOINT` (empty for AWS, the provider's endpoint otherwise), `AWS_USE_PATH_STYLE_ENDPOINT=false` (unless the provider says otherwise) and `AWS_PHOTOS_URL` (the public URL of the photos bucket or a CDN in front of it).
4. Turn off the MinIO services with an override file, for example `compose.s3.yaml`:

    ```yaml
    services:
      minio: { profiles: [minio] }
      minio-setup: { profiles: [minio] }
      app:
        depends_on: !override
          db: { condition: service_healthy }
          ssr: { condition: service_healthy }
      worker:
        depends_on: !override
          db: { condition: service_healthy }
      scheduler:
        depends_on: !override
          db: { condition: service_healthy }
    ```

    and deploy with `COMPOSE_FILE=compose.prod.yaml:compose.s3.yaml ./deploy.sh <tag>` (put `COMPOSE_FILE` in your shell profile so `rv` uses it too).

5. Photos are then served from another origin, so the CSP's `img-src` must allow it. That setting doesn't exist yet (it is in the backlog): until it does, keep photos on the app's origin by leaving `AWS_PHOTOS_URL` empty and pointing Caddy's `/media/photos/*` block at the bucket.

`app:doctor` checks both buckets on every deploy.

## Picking an SMTP provider

Any provider with SMTP works. Set `MAIL_HOST`, `MAIL_PORT`, `MAIL_SCHEME`, `MAIL_USERNAME`, `MAIL_PASSWORD` and `MAIL_FROM_ADDRESS` from its settings page. Things to compare:

- **Deliverability setup**: the provider must let you verify your sending domain with SPF and DKIM records. Add a DMARC record (`p=none` to start). Without these, mail lands in spam.
- **Ports**: some hosts block outbound port 25. Use 587 with `MAIL_SCHEME=smtp` (STARTTLS) or 465 with `MAIL_SCHEME=smtps` (TLS from the start).
- **Volume and price**: this app sends transactional mail only (reservation updates, password resets), so a free or entry tier is usually enough. Amazon SES, Postmark, Mailgun, Brevo, Resend and SMTP2GO all offer SMTP; check current pricing yourself.
- **Region and data**: the mail contains renters' names and reservation details. Prefer a provider whose data processing terms you can accept under the Data Privacy Act.

The app sends a mail for every reservation and viewing change (slice 6.3) and a reminder for proofs unanswered after 24 and 48 hours. **The `scheduler` service must be running**: without it holds are never tidied, stays never end and no reminder goes out (`rv ps` should list it; `rv exec scheduler php artisan schedule:list` shows the three jobs). The `worker` sends the mail, so it must run too.

After changing mail settings, redeploy (or run `rv up -d` and `rv exec app php artisan optimize`), then send yourself a test:

```bash
rv exec app php artisan tinker --execute "Mail::raw('ReserveVision test', fn (\$m) => \$m->to('you@example.com')->subject('Test'));"
```

To see the status mails work end to end, make a reservation as a test renter, then `rv logs worker` shows each mail being sent (a failure names the SMTP error and the job is retried), and the recipient's inbox has it within seconds. `rv exec app php artisan reservations:expire-holds` and `reservations:remind-proofs` can be run by hand and print their summary line.

## Trial run on your own machine

The CI workflow does exactly this, and you can too (needs only Docker):

```bash
bin/ci-production-env 8280        # throwaway .env.production: random secrets, Caddy on plain HTTP
./deploy.sh --current             # build and start the checked-out code
bin/smoke-production http://localhost:8280
E2E_BASE_URL=http://localhost:8280 E2E_SKIP_DEV_PAGES=1 npm run e2e
docker compose -f compose.prod.yaml --env-file .env.production down -v   # remove it again
rm .env.production
```

With `DOMAIN=:80` Caddy serves plain HTTP and requests no certificate. The app still runs with `APP_ENV=production`, so it sends HSTS, which browsers ignore over plain HTTP. `upgrade-insecure-requests` is sent on HTTPS requests only: over plain HTTP Chromium does apply it, and it upgrades Inertia's redirect follow-ups to an `https://` that isn't there.

## Not covered by CI

The `Production` workflow boots this stack on every PR, but some things only a real server can show. Check these by hand on the first real deployment and after changing them:

| Not covered | Why | How to check |
| --- | --- | --- |
| Real TLS issuance and renewal | CI has no public DNS name, so Caddy runs on plain HTTP (`DOMAIN=:80`) | `curl -I https://<DOMAIN>/up` shows `HTTP/2 200`; `rv logs caddy` shows "certificate obtained successfully". Renewal is automatic about 30 days before expiry |
| HTTP to HTTPS redirect, HTTP/3 | Same | `curl -I http://<DOMAIN>/` returns `308` to `https://` |
| Real email delivery | CI's SMTP is a Mailpit (`compose.ci.yaml`): every mail is delivered and read by T12, but SPF, DKIM and a real provider are not exercised | The tinker test above; check the message's SPF/DKIM results in the recipient's "show original" |
| External S3 providers | CI uses MinIO | `app:doctor` after switching |
| `deploy.sh <tag>` (checking out a tag) | CI deploys the checkout with `--current` | First tag deploy on the server |
| Backups and restores | Need a schedule and a second machine | A restore drill on a throwaway host |
| Codespaces | Needs GitHub's hosts | Open the README badge; the dev container itself is verified locally with the Dev Containers CLI |
