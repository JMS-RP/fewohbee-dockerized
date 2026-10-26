# fewohbee-dockerized

This docker compose setup is part of the [fewohbee guesthouse administration tool](https://github.com/developeregrem/fewohbee). It provides all necessary services to run fewohbee out of the box.

## Services

| Service | Image | Description |
|---------|-------|-------------|
| `web` | `ghcr.io/developeregrem/fewohbee-nginx` | nginx web server (app assets baked in) |
| `php` | `ghcr.io/developeregrem/fewohbee-phpfpm` | PHP 8 FPM with the app pre-built |
| `cron` | `ghcr.io/developeregrem/fewohbee-cli` | PHP CLI + crond for scheduled tasks |
| `db` | [mariadb](https://hub.docker.com/_/mariadb) | Database |
| `redis` | [redis](https://hub.docker.com/_/redis) | In-memory cache |
| `acme` | `developeregrem/fewohbee-acme` | SSL certificate management (Let's Encrypt or self-signed) |

The images are versioned via the `FEWOHBEE_VERSION` variable in `.env` (default: `latest`). Pin to a specific release (`4.6.0`), branch (`branch-feature-x`) or debug build (`4.6.0-debug`).

### Castle House booking form

The customized PHP image also supplies private, session-authenticated cancellation controls at `/reservation/castle-house/release`. They require `ROLE_RESERVATIONS` and an action-specific CSRF token. Guest identity is sent only in POST bodies, and the response never includes guest details. Each cancellation matches one released room, arrival/departure, booking origin and booker identity; new website requests carry a `[CHB:...]` reference in their remark. Ambiguous older matches are refused. Cancellation changes the existing reservation to FewohBee's non-blocking cancellation status through its usual status service, retaining guest, invoice and booking history. It does not delete the booking or cancel other rooms in its group.

For referenced website requests, matching uses the reference and booker email together with room, dates and origin. Public booking reuses existing CRM customers by email and preserves their curated names, so a different submitted guest name does not prevent cancelling the correct referenced stay. Unreferenced older requests still require both the full name and email. Wrong references/emails, changed stays and ambiguous duplicates remain rejected; matching never changes the customer's name.

The website's month view reads `/reservation/castle-house/calendar?month=YYYY-MM` through the existing authenticated account. This GET-only endpoint requires `ROLE_RESERVATIONS_RO` and returns only released room labels and per-night booleans. It uses FewohBee's canonical occupied-night calculation, including blocking reservations, room blocks, exclusive checkout and the configured booking horizon. It works independently of the optional native calendar display. Shared-occupancy rooms are excluded, and no guest names, booking references or private closure reasons are returned. Rebuild the customized PHP image before deploying the website month view; no API token or database migration is required.

Publishing a new website room provisions its missing calendar entry through `/reservation/castle-house/rooms`, using the existing authenticated reservation account and a separate CSRF token from its GET-only `/form` route. The POST resolves the property from the website's existing released rooms, creates one physical two-person room and category, adds owner-entered usual/weekend rates for one and two occupants, and extends only the selected online-room list. Prices apply per room; the property's existing tax/account treatment is reused. Existing room prices and guest reservations are not changed. A stable room key and a transaction lock on the online-booking configuration make retries and concurrent requests reuse the same room. Ambiguous, inactive or changed matches are rejected. Rebuild/redeploy this PHP service before deploying the website's automatic room-publication update. `tests/room-setup.php` checks actual database persistence and transaction rollback in an isolated SQLite database, plus idempotence, prices, property scope and CSRF/role guards.

Rebuild and redeploy the **FewohBee PHP service first**, then the website. The website queues the owner’s Free action, keeps its hold until cancellation is verified, and retries interrupted operations without another click. Older entries freed only on the website have a **Finish freeing in both systems** action. If the updated private controls are unavailable, the website keeps the booking held and shows the required deployment step. The PHP image rebuilds its authoritative Composer class map and clears its baked Symfony production cache so the new routes and services are registered on startup.

Castle House's website collects the guest's name, email, optional phone and country, without requesting Address, Postcode or Town. The `php` service builds `Dockerfile.castle-house` from the same versioned upstream PHP image and makes only those three public-booking fields optional on the server. Country, name and email validation remain intact; missing postal details are stored as null, not invented values. Admin customer data and existing addresses are preserved.

The customization is enabled in both Compose variants and rebuilt during normal deployment. `install.sh` and `update-docker.sh` also build it. Build the PHP image before recreating it when updating manually:

```sh
docker compose build --pull php
docker compose up -d --force-recreate php
```

The build checks the exact upstream validation block and fails before deployment if upstream changes require reviewing the customization. It also lints the modified PHP file. `.env` files and Git metadata are excluded from the image build context. No database schema change is needed. If deploying through a platform that only pulls prebuilt images, build and publish this customized image first and select it for the PHP service; the stock upstream image still requires postal addresses.

## Configuration

All settings are stored in a single `.env` file. Use `.env.dist` as the reference template.

## Setup

### Option A – Setup container (recommended, all platforms)

Works on Linux, macOS and Windows — requires only Docker.

```sh
# Clone the repository first
git clone https://github.com/developeregrem/fewohbee-dockerized.git
cd fewohbee-dockerized

# Linux / macOS
docker run --rm -it -v $(pwd):/config developeregrem/fewohbee-setup

# Windows PowerShell
docker run --rm -it -v ${PWD}:/config developeregrem/fewohbee-setup
```

The container asks a few questions (hostname, SSL mode, host ports, language), generates passwords and writes `.env`.

### Option B – install.sh (Linux only)

A Bash script that checks the default host ports and additionally sets up optional cron jobs for database backups and automatic updates:

```sh
git clone https://github.com/developeregrem/fewohbee-dockerized.git
cd fewohbee-dockerized
chmod +x install.sh
sudo ./install.sh
```

## Starting the application

### Standard mode (with SSL)

For servers with direct internet access. Manages SSL certificates automatically via the `acme` container (self-signed or Let's Encrypt).

Configure the exposed HTTP and HTTPS ports via `LISTEN_PORT` (default: `80`) and `HTTPS_LISTEN_PORT` (default: `443`) in `.env`.

```sh
docker compose up -d
```

### Reverse proxy mode (no internal SSL)

For deployments behind an external reverse proxy (Traefik, Nginx Proxy Manager, Caddy, etc.) that handles SSL termination. No `acme` container — the web container serves plain HTTP.

Set `COMPOSE_FILE=docker-compose.no-ssl.yml` in `.env` (done automatically by the setup scripts when choosing `reverse-proxy`) and then:

```sh
docker compose up -d
```

Configure the exposed HTTP port via `LISTEN_PORT` in `.env` (default: `80`). `HTTPS_LISTEN_PORT` is not used in this mode because TLS is terminated by the reverse proxy.

## First-run initialisation

The full stack reaches a healthy state in roughly 30–60 seconds on a typical server (db takes ~10–15 s to initialize, php-fpm boots and runs Doctrine migrations afterwards). The app code is pre-built into the image — no runtime clone. Wait until the `php` container is reported as `healthy`:

```sh
docker compose ps
```

Then run once to create the first admin user, load base templates and optionally sample data:

```sh
docker compose exec --user www-data php /bin/sh -c "php bin/console app:first-run"
```

## Connect Castle House when the old login is unknown

You can create a separate calendar account from the hosting server without recovering or changing an existing user's password. After rebuilding this customized PHP image, run this in the FewohBee repository directory:

```sh
docker compose exec --user www-data php php bin/console app:create-user
```

If you are already in the FewohBee **PHP container terminal** (for example through Coolify), use `php bin/console app:create-user` directly. Choose a new username such as `castlehouse-calendar`, a strong password, the account's name and contact email, and only the `ROLE_RESERVATIONS` role. The password prompt is hidden in the customized image. FewohBee validates and hashes the password; no existing accounts or reservations are changed. Do not rerun `app:first-run` to recover a login.

Save the username and password securely on the **website application** as `FEWOHBEE_SYNC_USERNAME` and `FEWOHBEE_SYNC_PASSWORD`, then redeploy that application. Keep the password out of source control and chat. This account can also be used to inspect reservations in FewohBee. Compare older website-only Freed records with actual FewohBee bookings before using **Finish freeing in both systems**. Cancel only the records the owner selects, preserving other guest bookings.

The same credentials must be supplied securely to the Codex cloud environment if Codex is to inspect or repair PMS records. Git access and public availability access do not grant private PMS or hosting access.

## Updates

```sh
chmod +x update-docker.sh
./update-docker.sh
```

The script pulls the current Compose files and images, restarts the stack and automatically adds new variables from `.env.dist` to the local `.env`. New variables should be reviewed and adjusted after the update.

### Updating from a legacy install

When upgrading from an older `developeregrem/fewohbee-*` Docker Hub setup (with the runtime git clone), the new compose file includes a one-shot `uploads-migration` init container. It runs automatically on **every** `docker compose up` — no matter whether you trigger it through `update-docker.sh`, Portainer's stack redeploy, or plain `docker compose` on Windows. It copies your user uploads from the legacy `feb-data` volume into the new `uploads-export` and `uploads-roomcat` volumes once, then exits. The legacy `feb-data` volume is kept intact for rollback — you can remove it manually after verifying everything works:

```sh
docker volume rm fewohbee-dockerized_feb-data
```

## Debug builds

Each release has a corresponding `-debug` image with xdebug and dev dependencies. The image carries its own `APP_ENV=dev` setting — you only need to switch the tag:

```sh
# in .env
FEWOHBEE_VERSION=4.6.0-debug
```

Then `docker compose up -d --force-recreate php`.

## Documentation

Full setup and configuration documentation:
[Docker-Setup](https://github.com/developeregrem/fewohbee/wiki/Docker-Setup) and [Portainer-Setup](https://github.com/developeregrem/fewohbee/wiki/Portainer)
