# Deploying

Laravel Forge runs the API, its MySQL and its Redis. Vercel serves the React
app. Google Drive holds the media. Nothing else is needed.

---

## 1. The API, on Forge

Create a site pointing at the repository, with the web directory set to
`/backend/public`.

### Before anything else: PHP extensions

`composer install` fails loudly without these, which is the point — a missing
`gd` would otherwise surface as a fatal error on the first photo someone
uploads:

```
gd  fileinfo  mbstring  json  pdo_mysql  curl  openssl
```

`exif` is optional but strongly recommended: without it, portrait photographs
taken on a phone are shown on their side. Forge's default PHP build includes
all of these; verify with `php -m` if you are deploying elsewhere.

### Environment

The application root is `backend/`, so Laravel reads
`$FORGE_SITE_PATH/backend/.env` — **not** the `.env` Forge shows you in its
Environment tab, which sits one level up. Link them once, and keep the link in
the deploy script so a fresh clone never loses it:

```bash
ln -sfn $FORGE_SITE_PATH/.env $FORGE_SITE_PATH/backend/.env
```

Without it Laravel finds no environment at all and silently falls back to
`DB_CONNECTION=sqlite` — the site comes up, empty, writing to a database file
nobody meant to create.

Then fill in Forge's Environment tab from `backend/.env.example`. The values
that matter most:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.your-domain.com     # must be https, see below

DB_DATABASE=memories
DB_USERNAME=forge
DB_PASSWORD=…

REDIS_CLIENT=predis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

# Exactly the origin the app is served from. Comma-separate previews.
FRONTEND_URL=https://memories.your-domain.com

ARCHIVE_PUBLIC=true                      # false keeps it to the owner alone
```

`APP_URL` must start with `https://`. Every media URL in every API response is
generated from it, and behind Forge's TLS proxy an `http://` value produces
links the browser blocks as mixed content.

### Deploy script

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH

ln -sfn $FORGE_SITE_PATH/.env $FORGE_SITE_PATH/backend/.env

cd $FORGE_SITE_PATH/backend
composer install --no-dev --optimize-autoloader --no-interaction

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan event:cache

php artisan queue:restart

( flock -w 10 9 || exit 1
    echo 'Restarting FPM...'; sudo -S service $FORGE_PHP_FPM reload ) 9>/tmp/fpmlock
```

Keep the `git pull` and the FPM reload — Forge's default script has both, and a
hand-written replacement that drops them will happily report a successful
deploy while continuing to serve the previous release from OPcache.

**Config caching and first setup.** `config:cache` freezes the environment;
once `bootstrap/cache/config.php` exists Laravel stops reading `.env` at all.
That is what you want in production, but it means an environment value added
*after* a deploy — the Drive refresh token, most notably — is invisible until
the config cache is rebuilt. Hence the `config:clear` / `config:cache` around
the first-run block below.

### After the first deploy

```bash
cd $FORGE_SITE_PATH/backend

php artisan config:clear          # so these commands see the live environment

php artisan key:generate --force  # APP_KEY ships empty and must be generated
php artisan archive:owner --email=you@example.com
php artisan drive:authorize       # paste the refresh token into Forge's Environment

php artisan config:cache          # required, or Drive stays "not connected"
php artisan memories:doctor       # confirms Drive, quota, and consistency
```

The `config:cache` at the end is not optional. Skip it and `memories:doctor`
will keep reporting "Not connected" and `/api/archive` will keep returning
`storage_connected: false` with a perfectly valid refresh token sitting in the
environment, because the app is still reading the cache from before you pasted
it in.

Generate `APP_KEY` **once**. Changing it later invalidates every encrypted
value; it does not affect the media, which is not encrypted at rest by this
application.

### Redis eviction policy

Give the cache its own database (`REDIS_CACHE_DB=1`, as in `.env.example`) so
`php artisan cache:clear` cannot take the queue with it.

If you set a `maxmemory` limit, prefer:

```
maxmemory-policy volatile-lru
```

Every cached entry carries a TTL, so `volatile-lru` evicts exactly the right
things. The archive is safe under `allkeys-lru` too — a lost generation counter
restarts on a fresh, unused number rather than reviving stale entries — but
`volatile-lru` avoids the churn entirely.

### Queue worker

Add a Forge queue worker on the `redis` connection. Drive deletions run there;
without it, removed memories leave the timeline but their files stay in Drive
until the hourly sweep is run by hand.

- **Processes:** 1 is plenty
- **Timeout:** 300 — a large file can take a while to delete
- **Tries:** 3

### Scheduler

Enable Forge's scheduler for the site. It runs the hourly deletion retry, the
upload sweep, and failed-job pruning (`backend/routes/console.php`).

### nginx and PHP limits

Uploads arrive in 4 MiB chunks, which fits inside stock limits — nothing has to
be changed for the app to work. Raising them means fewer round trips on large
videos:

```nginx
# Forge → site → Edit Files → nginx Configuration
client_max_body_size 32M;
```

```ini
; php.ini
upload_max_filesize = 32M
post_max_size = 32M
```

If you raise these, raise `UPLOAD_CHUNK_BYTES` to match — but keep it *below*
`post_max_size`, since PHP refuses a body larger than that before any code runs.

### Disk

Two directories under `backend/storage/app/private` grow on their own:

- `uploads/` — files mid-transfer, swept hourly by `uploads:prune`
- `derivatives/` — resized copies, a cache in front of Drive

`derivatives/` is bounded by `MEDIA_DERIVATIVE_CACHE_BYTES` (8 GB by default)
and trimmed weekly by `derivatives:prune`, least-recently-used first. It is
pure cache: anything removed is regenerated on the next request, so the only
cost of a smaller ceiling is an occasional slower image. Set it to something
your disk can comfortably hold — a full disk takes the whole site down, which
is far worse than a regenerated thumbnail.

To see where it stands without changing anything:

```bash
php artisan derivatives:prune --dry-run
```

### Backups

Drive holds the photographs and videos. **MySQL holds everything else** — every
title, date, description, location, and which files belong together as one
memory. Lose the database and you are left with a Drive folder of files: the
media survives, the archive does not.

So back the database up, off the box:

- Enable Forge's scheduled database backups to an off-server destination, daily.
- Keep at least 30 days, so a problem noticed late is still recoverable.
- Restore is the ordinary `mysql memories < dump.sql`, then
  `php artisan cache:clear`.

There is one consolation if it ever comes to it: files in Drive are named
`YYYY-MM-DD Title NN.jpg`, so date, title and ordering can be reconstructed by
hand from the folder itself. Descriptions and locations cannot.

`derivatives/` and `uploads/` need no backup at all — one is a cache, the other
is in-flight work.

---

## 2. The app, on Vercel

Import the repository and set:

| Setting | Value |
| --- | --- |
| Root directory | `frontend` |
| Framework preset | Vite |
| Build command | `npm run build` |
| Output directory | `dist` |

One environment variable:

```
VITE_API_URL=https://api.your-domain.com
```

Anything prefixed `VITE_` is compiled into the JavaScript bundle and is public.
No Google credential, database password or Laravel secret belongs there — those
live only in the Forge environment.

`frontend/vercel.json` already handles the rest. Since JSON cannot carry
comments — and Vercel rejects any unrecognised key outright — what each part is
for is recorded here instead:

- **`rewrites`** sends everything except `/assets/*` to `index.html`. A memory
  opens at its own address (`/m/<id>`); without this, reloading or sharing that
  link asks Vercel for a file that does not exist and gets a 404.
- **`headers`** caches hashed assets for a year, and sets `nosniff`,
  `DENY` framing, a referrer policy, and `noindex` — a private archive has no
  business in search results.

---

## 3. Checks worth doing once

```bash
# The API answers, and knows whether Drive is connected.
curl -s https://api.your-domain.com/api/archive | jq

# CORS lets exactly the app through, and nobody else.
curl -sI -H 'Origin: https://memories.your-domain.com' \
  https://api.your-domain.com/api/archive | grep -i access-control-allow-origin
```

Then, in the app: add a memory with two photos and a video, reload the
timeline, open it, and remove it. Afterwards `php artisan memories:doctor`
should report no stuck deletions — that confirms the queue worker is running.

---

## Things that go wrong

**A private archive shows no photographs (403 on every image).**
`APP_URL` does not exactly match the origin the browser is using. A private
archive signs its media URLs, and a signature is bound to the host it was
generated for — `https://api.example.com` and `https://www.api.example.com`
are different URLs as far as the signature is concerned. Make `APP_URL`
byte-identical to the public origin, then `php artisan config:cache`.

**Photographs do not appear, everything else works.**
`APP_URL` is `http://` while the site is served over https, so the media URLs
are mixed content. Fix `APP_URL`, then `php artisan config:cache`.

**Every request fails in the browser but works from curl.**
`FRONTEND_URL` does not exactly match the app's origin, scheme and all.

**Uploads fail at the very first chunk.**
`UPLOAD_CHUNK_BYTES` is larger than `post_max_size`. PHP discards the body
before Laravel sees it.

**`drive:authorize` fails with "Error 403: access_denied" / "has not completed
the Google verification process".**
The consent screen is in *Testing* and the account you signed in with is not on
its test-user list. That exact wording only appears in Testing — a published
app shows a different, bypassable "Google hasn't verified this app" screen.

Immediate unblock: Google Cloud Console → **Google Auth Platform → Audience →
Test users → Add users** → your Gmail address. Effective within seconds.

Durable fix: on the same page, **Publish app**. `drive.file` is a non-sensitive
scope so there is no review, but the button does nothing until *Branding* has
an app name, a user support email and a developer contact email. If it still
says *Testing* afterwards, check you are in the same Google Cloud **project**
that owns the OAuth client in `GOOGLE_DRIVE_CLIENT_ID` — editing the consent
screen of a different project is the usual reason a publish appears not to
stick.

**Drive disconnects roughly every seven days.**
The OAuth consent screen is still in *Testing*. Google expires refresh tokens
for apps in that state after a week. Set the publishing status to *In
production* in the Google Cloud Console and run `php artisan drive:authorize`
once more.

**Uploads fail with a storage quota error.**
A service account with no Drive of its own. Use `GOOGLE_DRIVE_AUTH=oauth`, or
point the service account at a Workspace shared drive.

**Removed memories stay in Drive.**
The queue worker is not running. `php artisan memories:retry-deletions` clears
the backlog once the worker is up.

**`memories:doctor` says Drive is not connected, but the token is right there.**
The config cache predates the token. `php artisan config:cache` and try again.
Any environment change on Forge needs a redeploy, or that command by hand,
before the application can see it.

**The site comes up completely empty on a fresh server.**
`backend/.env` is missing, so Laravel fell back to SQLite. Check the symlink
from the deploy script.

**The timeline is stale after an edit.**
Only possible if `CACHE_STORE` is pointed somewhere the app does not invalidate.
Every write bumps a version counter that retires the whole cached generation, so
a correctly configured Redis cannot serve a stale timeline.
