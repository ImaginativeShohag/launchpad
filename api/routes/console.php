<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every minute: a launch goes live within a minute of 00:00 UTC, and a missed run is caught up by the next.
// `withoutOverlapping(5)` stops a slow run from stacking up without letting a crashed run block the next day;
// `onOneServer()` needs a shared cache (Redis in production) when more than one server runs the scheduler.
Schedule::command('launchpad:publish-launches')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Safety net for the deleted-member vote clean-up (see the command's docblock).
Schedule::command('launchpad:sweep-deleted-member-votes')->hourly()->withoutOverlapping(10)->onOneServer();

// Safety net for the deleted-member comment clean-up (see the command's docblock). Half past the hour, so it does not
// start together with the vote sweep.
Schedule::command('launchpad:sweep-deleted-member-comments')->hourlyAt(30)->withoutOverlapping(10)->onOneServer();

// Every minute: today's order follows the votes within a minute (counts themselves are instant). Same overlap and
// one-server protection as the go-live job.
Schedule::command('launchpad:recompute-hot-scores')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Every minute: a finished launch day is frozen within a minute of midnight UTC, and a missed run is caught up by the
// next. The freeze waits for in-flight votes (it locks the day's products without skip locked).
Schedule::command('launchpad:freeze-day')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Every 10 minutes: history of today's order (it cannot be rebuilt later). Re-running inside a window is a no-op.
Schedule::command('launchpad:snapshot-ranks')->everyTenMinutes()->withoutOverlapping(15)->onOneServer();

// Daily at a quiet hour: the retention purge (vote hashes, auth events, rank snapshots). Chunked and capped per run, so
// a backlog is worked off over several nights; the 60 minute lock outlasts any normal run without blocking tomorrow's.
Schedule::command('launchpad:purge-retention')->dailyAt('03:15')->withoutOverlapping(60)->onOneServer();

// Every minute: send the due mail of the outbox. A provider outage loses nothing (rows wait in the database and retry
// with backoff); the 5 minute lock outlasts a normal 50 second run and a crashed run cannot block tomorrow's.
Schedule::command('launchpad:send-emails')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Hourly at :45 (the vote sweep runs at :00 and the comment sweep at :30): the second net under the queued notification
// listeners. For the last 48 hours it re-derives the same rows with the same keys, so a lost job only delays a notification.
Schedule::command('launchpad:reconcile-notifications')->hourlyAt(45)->withoutOverlapping(30)->onOneServer();

// Every 10 minutes: from the daily send time (digest.daily_send_at_utc) on the day before a launch, remind its maker. A
// product's reminder key holds its launch date, so a re-run inside the window creates nothing; the 15 minute lock outlasts a run.
Schedule::command('launchpad:queue-launch-reminders')->everyTenMinutes()->withoutOverlapping(15)->onOneServer()->runInBackground();

// Every 10 minutes: queue the daily top five (every morning) and the weekly best (Mondays) once their send time has
// passed. A run is resumable (cursor in digest_runs) and unique per (kind, period), so a re-run is a cheap no-op; the 60
// minute lock outlasts any normal run without blocking the next day's.
Schedule::command('launchpad:queue-digests')->everyTenMinutes()->withoutOverlapping(60)->onOneServer()->runInBackground();

// Every minute: write the page views counted in Redis into product_hourly_stats and product_referrers_daily (one batched
// transaction). A failed batch stays in Redis and is retried by the next run; the 5 minute lock outlasts a normal run and a
// crashed run cannot block the next minute.
Schedule::command('launchpad:flush-views')->everyMinute()->withoutOverlapping(5)->onOneServer();

// Every 5 minutes: the safety net of the export queue. An export still queued after 10 minutes lost its dispatch and is
// dispatched again (the job is unique); one processing for 10 minutes lost its worker and is failed as stalled. The 10
// minute lock outlasts a run and a crashed run cannot block the next.
Schedule::command('launchpad:sweep-exports')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Every 5 minutes: look for fake accounts, shared devices and scripted voting on yesterday's and today's launch days and
// record a risk signal per finding. Five minutes, so staff can act on a launch day before 00:00 UTC freezes it. The scan is
// stateless and every insert is deduplicated, so a re-run or a missed run is harmless; the 10 minute lock outlasts a normal
// run and a crashed run cannot block the next one.
Schedule::command('launchpad:detect-abuse')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer()->runInBackground();
