<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled upkeep
|--------------------------------------------------------------------------
|
| Three jobs, all of them about keeping the catalogue and Drive in agreement.
| Needs Forge's scheduler enabled for the site.
|
*/

// Files whose deletion failed get another attempt; a Drive outage during a
// removal resolves itself without anyone having to notice.
Schedule::command('memories:retry-deletions')->hourly()->withoutOverlapping();

// Abandoned uploads and spent request keys.
Schedule::command('uploads:prune')->hourly()->withoutOverlapping();

// Keeps failed queue jobs from accumulating forever.
Schedule::command('queue:prune-failed --hours=336')->daily();

// Sign-in tokens that have passed their expiry.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Keeps the cached image renditions under their ceiling. They regenerate on
// demand, so this is only ever trading a slower request for a disk that does
// not fill up.
Schedule::command('derivatives:prune')->weekly()->withoutOverlapping();
