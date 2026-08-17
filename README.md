# Memories

A private place where our memories live.

Photographs and videos, held in Google Drive, shown as a timeline you can walk
back through. There is no dashboard and no management screen — the timeline is
the application, and adding or removing a memory happens from within it.

```
backend/    Laravel API — the only thing that talks to Google Drive
frontend/   React app — the timeline, the viewer, the upload flow
```

## How it fits together

```
  browser ──▶ React (Vercel) ──▶ Laravel API (Forge) ──▶ Google Drive
                                      │
                                      ├─▶ MySQL   what memories exist
                                      └─▶ Redis   timeline cache, queue
```

**MySQL is the source of truth for the archive; Drive is the source of truth
for the bytes.** A row records where a file lives, what it is, and what state
it is in. Nothing else in the system holds a copy.

The browser never sees a Drive file id, let alone a credential. Every image and
every video is proxied through the API, which is also what lets a private
archive stay private.

### Getting a file into Drive

A phone video can be gigabytes, so it does not arrive in one request:

1. The browser opens an **upload session** and sends the file in 4 MiB chunks,
   each written straight to its offset in a temp file. A dropped connection
   costs one chunk, and the server reports which pieces it is still missing.
2. On completion the server reads the assembled file — the **MIME type is
   decided from the bytes**, never from what the browser claimed — and records
   its dimensions and a blur-up placeholder.
3. Creating the memory streams each file to Drive with a resumable upload, so
   peak memory is one chunk regardless of file size.
4. Only once every byte is in Drive are the database rows written.

If any of that fails, the files already uploaded are **taken back out of
Drive**, and no memory is created. There is no half-saved state to explain.

### Getting a file out again

Removing a memory has to survive a third-party API that can fail halfway, so
each file carries a deletion state:

```
active ──▶ deleting ──▶ deleted
                    ╲
                     ▶ delete_failed ──(retried hourly)──▶ deleting
```

The memory leaves the timeline immediately. The Drive deletion happens on the
queue, and a failure is recorded rather than forgotten — `memories:doctor`
reports anything that has given up.

## Running it locally

**Needs** PHP 8.3+, Composer, Node 20+, MySQL 8+, Redis.

MySQL 8 or later specifically: the timeline groups by year and limits eager-loaded
media per memory, which compiles to window functions.

```bash
# --- backend -------------------------------------------------------------
cd backend
composer install
cp .env.example .env && php artisan key:generate

mysql -u root -e "CREATE DATABASE memories; CREATE DATABASE memories_test;"
php artisan migrate

# The one account that may change anything. There is no sign-up screen.
php artisan archive:owner --email=you@example.com

php artisan serve                     # http://localhost:8000
php artisan queue:work                # in another shell — Drive deletions

# --- frontend ------------------------------------------------------------
cd ../frontend
npm install
cp .env.example .env.local            # point VITE_API_URL at the API
npm run dev                           # http://localhost:5173
```

`FRONTEND_URL` in the backend `.env` must list the exact origin the app is
served from, or the browser will refuse every request. Comma-separate several.

Until Drive is connected the archive runs and the timeline works; the upload
flow says so up front rather than failing at the last step.

## Connecting Google Drive

The app creates and owns its own folder tree, so the narrow `drive.file` scope
is enough — it can see the files it made and nothing else in your Drive.

```
Memory Archive/
├── Images/2026/…
└── Videos/2026/…
```

1. In the [Google Cloud Console](https://console.cloud.google.com/), create a
   project and enable the **Google Drive API**.
2. Configure the **OAuth consent screen**: User type *External*, and add your
   own Google account under *Test users*.
3. Set the publishing status to **In production**. This matters more than it
   sounds — while an app sits in *Testing*, Google expires its refresh tokens
   after **seven days**, so the archive would disconnect itself every week.
   `drive.file` is not a sensitive scope, so this needs no review process.
4. Under **Credentials**, create an **OAuth client ID** of type *Web
   application*, and add `http://localhost:8000/drive/callback` as an
   authorised redirect URI.
5. Put the client id and secret in `.env`.
6. Run the consent flow once and paste the refresh token it prints into `.env`:

```bash
php artisan drive:authorize
```

The browser will land on a page that fails to load. That is expected — nothing
is listening on that address. The part that matters is `?code=…` in the address
bar; copy that value and paste it back into the command.

Files then belong to that Google account and use its storage.

> **Why OAuth and not a service account?** A bare service account has no Drive
> storage of its own, so every upload fails with a quota error. Service accounts
> work only against a Workspace shared drive or with domain-wide delegation —
> `GOOGLE_DRIVE_AUTH=service_account` supports that if you have it.

Check the connection at any time:

```bash
php artisan memories:doctor
```

## Tests

```bash
cd backend  && php artisan test      # 76 tests
cd frontend && npx vitest run        # 32 tests
```

They cover the failure paths, not just the happy one: a Drive upload that dies
mid-batch, a Drive that refuses to delete, a replayed save, a file lying about
its type, an upload missing a chunk, and a cached response that must match the
one that filled the cache.

## Commands

| Command | What it does |
| --- | --- |
| `archive:owner` | Create or re-password the archive owner |
| `drive:authorize` | One-time Google consent; prints a refresh token |
| `memories:doctor` | Storage connection, quota, and anything inconsistent |
| `memories:retry-deletions` | Re-queue Drive deletions that failed (hourly) |
| `uploads:prune` | Sweep abandoned uploads and spent request keys (hourly) |
| `derivatives:prune` | Trim the cached renditions back under their ceiling (weekly) |

Drive folder ids are cached for a month; `php artisan cache:clear` re-discovers
them if the folders are moved by hand.

## Deploying

See [DEPLOYMENT.md](DEPLOYMENT.md) — Laravel Forge for the API, Vercel for the
app, and the settings that are easy to get wrong.
