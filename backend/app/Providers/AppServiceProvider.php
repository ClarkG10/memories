<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\GoogleDrive\GoogleDriveClientFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            GoogleDriveClientFactory::class,
            fn (): GoogleDriveClientFactory => new GoogleDriveClientFactory(config('googledrive')),
        );
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        /*
         | Every media URL in an API response is generated here, and those
         | responses are cached in Redis and served to everyone.
         |
         | Pinning the root to APP_URL rather than letting it come from the
         | request is what makes that safe. The app trusts proxy headers so it
         | can see the real client address, and X-Forwarded-Host is one of
         | them: without this, a single request carrying a forged host would
         | bake somebody else's origin into the cached timeline, and for the
         | next fifteen minutes every visitor would load the archive's
         | photographs from an attacker's server.
         |
         | It also fixes the scheme, which behind a TLS-terminating proxy would
         | otherwise be the plain http that nginx forwards — and every
         | photograph would be blocked as mixed content.
         */
        $root = (string) config('app.url');

        if ($root !== '') {
            URL::forceRootUrl($root);
        }

        if (str_starts_with($root, 'https://')) {
            URL::forceScheme('https');
        }
    }

    private function configureRateLimiting(): void
    {
        // Browsing is cheap and cached; be generous so scrolling never trips it.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(180)->by(
            $request->user()?->id ?: $request->ip()
        ));

        /*
         | Media gets its own, much larger bucket, because one screen of the
         | timeline is legitimately dozens of requests and a single video is
         | many more once the player starts seeking.
         |
         | It still needs a ceiling: every one of these either reads from disk
         | or, on a cold cache, pulls the original out of Drive. Without a
         | limit a public archive is an open bandwidth amplifier and a way to
         | burn somebody else's Drive quota.
         */
        RateLimiter::for('media', fn (Request $request) => Limit::perMinute(900)->by(
            $request->user()?->id ?: $request->ip()
        ));

        // Chunks arrive in bursts, one request per few megabytes.
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(600)->by(
            $request->user()?->id ?: $request->ip()
        ));

        // Writes are rare and human-paced.
        RateLimiter::for('writes', fn (Request $request) => Limit::perMinute(60)->by(
            $request->user()?->id ?: $request->ip()
        ));

        /*
         | Password guessing gets a much smaller budget.
         |
         | The per-address limb is keyed on a normalised, hashed value rather
         | than the raw input: otherwise " Owner@Example.com " and
         | "owner@example.com" are different buckets, and an attacker gets a
         | fresh allowance for every way they can spell the same address. The
         | per-IP limb is the one that cannot be varied from the request body.
         */
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.sha1(Str::lower(trim((string) $request->input('email'))))),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
    }
}
