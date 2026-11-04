<?php

namespace App\Providers;

use App\Events\CommentCreated;
use App\Events\ExportReady;
use App\Events\ProductApproved;
use App\Events\ProductChangesRequested;
use App\Events\ProductRejected;
use App\Events\ProductWentLive;
use App\Events\RankingUpdated;
use App\Events\VoteCast;
use App\Events\VoteRemoved;
use App\Events\VoteVoided;
use App\Http\Middleware\DeviceId;
use App\Listeners\BroadcastRankUpdate;
use App\Listeners\BroadcastVoteCount;
use App\Listeners\NotifyNewComment;
use App\Listeners\NotifyProductLive;
use App\Listeners\NotifyReviewOutcome;
use App\Listeners\RecordExportReadyNotification;
use App\Support\DeviceHash;
use App\Support\Google\GoogleIdentityProvider;
use App\Support\Google\SocialiteGoogleIdentityProvider;
use App\Support\LoginThrottle;
use App\Support\ViewCounters;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoogleIdentityProvider::class, SocialiteGoogleIdentityProvider::class);
        // One instance, so a test can bind a subclass that fails (the Redis outage case).
        $this->app->singleton(ViewCounters::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configurePasswords();
        $this->configureRateLimiting();
        $this->configureBroadcasting();
        $this->configureEvents();
    }

    /** Registered by hand: `broadcast` is not a discoverable `handle` method, so each event reaches the listener once. */
    private function configureEvents(): void
    {
        foreach ([VoteCast::class, VoteRemoved::class, VoteVoided::class] as $event) {
            Event::listen($event, [BroadcastVoteCount::class, 'broadcast']);
        }

        Event::listen(RankingUpdated::class, [BroadcastRankUpdate::class, 'broadcast']);

        // The notification producers (queued, durable: see the listeners). Their method is `notify`, never `handle`.
        foreach ([ProductApproved::class, ProductRejected::class, ProductChangesRequested::class] as $event) {
            Event::listen($event, [NotifyReviewOutcome::class, 'notify']);
        }
        Event::listen(ProductWentLive::class, [NotifyProductLive::class, 'notify']);
        Event::listen(CommentCreated::class, [NotifyNewComment::class, 'notify']);
        Event::listen(ExportReady::class, [RecordExportReadyNotification::class, 'notify']);
    }

    /** The /api/v1/broadcasting/auth route lives in routes/api.php (it validates input first); only the channels load here. */
    private function configureBroadcasting(): void
    {
        // An empty Reverb secret would sign channel tokens anyone could forge; refuse to serve in production without one.
        if ($this->app->isProduction() && ! $this->app->runningInConsole() && config('broadcasting.default') === 'reverb'
            && (blank(config('broadcasting.connections.reverb.key')) || blank(config('broadcasting.connections.reverb.secret')))) {
            throw new \RuntimeException('REVERB_APP_KEY and REVERB_APP_SECRET must be set in production.');
        }

        require base_path('routes/channels.php');
    }

    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configurePasswords(): void
    {
        // The breach check calls haveibeenpwned (k-anonymity); skip it in the test suite.
        Password::defaults(fn () => $this->app->runningUnitTests()
            ? Password::min(12)
            : Password::min(12)->uncompromised());
    }

    private function configureRateLimiting(): void
    {
        $email = fn (Request $r): string => is_string($e = $r->input('email')) ? Str::lower(trim($e)) : '';
        $who = fn (Request $r): string => (string) ($r->user()?->getAuthIdentifier() ?? $r->ip());

        // `throttleApi()` runs before `auth:sanctum`, so $r->user() is empty here for real requests. A bearer
        // token is keyed by its hash (never the raw token, which would sit in the cache) so mobile clients behind
        // one carrier NAT do not share a bucket. A random token is a fresh bucket each time, so the per-IP
        // backstop caps one address no matter how many tokens it rotates (300/min is roomy for shared NAT).
        RateLimiter::for('api', function (Request $r) use ($who) {
            $token = $r->bearerToken();

            return is_string($token) && $token !== ''
                ? [
                    Limit::perMinute(60)->by('api-token:'.hash_hmac('sha256', $token, (string) config('app.key'))),
                    Limit::perMinute(300)->by('api-ip:'.$r->ip()),
                ]
                : Limit::perMinute(60)->by($who($r));
        });

        // Search runs on every pause in typing and is the costliest public read: 45/min per viewer (an HMAC of the bearer
        // token, never the raw token, or the IP), below the group's 60 so it can bite, plus a per-IP backstop (90/min, so a junk
        // token cannot lift a guest above about twice the guest ceiling) against rotating junk tokens. The key never contains the query.
        RateLimiter::for('search', function (Request $r) {
            $token = $r->bearerToken();

            return is_string($token) && $token !== ''
                ? [
                    Limit::perMinute(45)->by('search-token:'.hash_hmac('sha256', $token, (string) config('app.key'))),
                    // Its own key: a guest's 45/min IP bucket and this backstop never share a counter.
                    Limit::perMinute(90)->by('search-ip-backstop:'.$r->ip()),
                ]
                : Limit::perMinute(45)->by('search-ip:'.$r->ip());
        });

        // The docs routes build the whole OpenAPI spec on a cold cache: 30/min per IP, below the group's 60.
        RateLimiter::for('api-docs', fn (Request $r) => Limit::perMinute(30)->by('api-docs:'.$r->ip()));

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(LoginThrottle::minuteKey($r)),
            Limit::perHour(20)->by('login-hour:'.$email($r)),
            // Credential stuffing across many emails from one address. Counts every attempt, so offices or
            // carrier NAT sharing one IP share these 30/min; real users rarely sign in that often.
            Limit::perMinute(30)->by('login-ip:'.$r->ip()),
        ]);

        // Not the `login` limiter: its hourly bucket is keyed by the email input, which Google requests
        // do not have, so every Google sign-in would share one global bucket and 20 requests could lock everyone out.
        RateLimiter::for('google', fn (Request $r) => [
            Limit::perMinute(20)->by('google:'.$r->ip()),
            Limit::perHour(120)->by('google-hour:'.$r->ip()),
        ]);

        RateLimiter::for('register', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));

        RateLimiter::for('password-forgot', fn (Request $r) => [
            Limit::perMinute(3)->by($r->ip().'|'.$email($r)),
            Limit::perHour(20)->by('forgot-ip:'.$r->ip()),
        ]);

        RateLimiter::for('verification-resend', fn (Request $r) => [
            Limit::perMinute(1)->by('resend:'.$who($r)),
            Limit::perHour(6)->by('resend-hour:'.$who($r)),
        ]);

        RateLimiter::for('password-change', fn (Request $r) => [
            Limit::perMinute(5)->by($who($r)),
            Limit::perHour(20)->by($who($r)),
        ]);

        RateLimiter::for('account-delete', fn (Request $r) => [
            Limit::perMinute(5)->by($who($r)),
            Limit::perHour(20)->by($who($r)),
        ]);

        // Every mutating admin endpoint shares this per-admin bucket.
        RateLimiter::for('admin-write', fn (Request $r) => [
            Limit::perMinute(30)->by('admin-write:'.$who($r)),
            Limit::perHour(200)->by('admin-write-hour:'.$who($r)),
        ]);

        RateLimiter::for('avatar', fn (Request $r) => Limit::perMinute(10)->by($who($r)));

        // Draft saves and edits. Below the 60/min `api` bucket, or it could never bite (L6). The hour keys differ
        // from the minute keys because Laravel stores a bucket per key.
        RateLimiter::for('product-write', fn (Request $r) => [
            Limit::perMinute(30)->by('product-write:'.$who($r)),
            Limit::perHour(300)->by('product-write-hour:'.$who($r)),
        ]);

        // Votes are cheap and idempotent but high-volume; per member, below the 60/min `api` bucket so it can bite.
        RateLimiter::for('vote', fn (Request $r) => [
            Limit::perMinute(30)->by('vote:'.$who($r)),
            Limit::perHour(300)->by('vote-hour:'.$who($r)),
        ]);

        // The view beacon is public and cheap, so it is limited per device (the HMAC of the id the client presented) and falls
        // back to the IP when it presented none (a cookie-less script gets a fresh id every time, so the per-IP bucket is the
        // real defence). 30/min is below the api bucket's 60 so it can bite. Never keyed on the body or the product id.
        RateLimiter::for('view', function (Request $r) {
            // Hashed keys (never a raw address in the cache); an IPv6 address counts as its /64.
            $ip = DeviceHash::viewIp($r) ?? 'none';
            $device = DeviceHash::hmac(DeviceId::presented($r)) ?? $ip;

            return [
                Limit::perMinute(30)->by('view:'.$device),
                Limit::perHour(600)->by('view-hour:'.$device),
                Limit::perMinute(120)->by('view-ip:'.$ip),
            ];
        });

        // The dashboard reads (maker and staff share the member's bucket): 30/min, below the api bucket's 60 so it can bite.
        RateLimiter::for('dashboard', fn (Request $r) => Limit::perMinute(30)->by('dashboard:'.$who($r)));

        // Starting an export is heavy: 3 an hour and 10 a day per member (the bucket never depends on the product id, so a
        // stranger cannot burn the maker's allowance). Polling the list or one export: 30/min, below the api bucket's 60.
        RateLimiter::for('export-start', fn (Request $r) => [
            Limit::perHour(3)->by('export-start-hour:'.$who($r)),
            Limit::perDay(10)->by('export-start-day:'.$who($r)),
        ]);
        RateLimiter::for('export-poll', fn (Request $r) => Limit::perMinute(30)->by('export-poll:'.$who($r)));
        // Downloads stream a file: 20 an hour per member.
        RateLimiter::for('export-download', fn (Request $r) => Limit::perHour(20)->by('export-download:'.$who($r)));

        // Comments: a write brake per member, below the 60/min `api` bucket so it can bite. Edit and delete share one bucket.
        RateLimiter::for('comment-create', fn (Request $r) => [
            Limit::perMinute(6)->by('comment-create:'.$who($r)),
            Limit::perHour(60)->by('comment-create-hour:'.$who($r)),
        ]);

        RateLimiter::for('comment-modify', fn (Request $r) => [
            Limit::perMinute(10)->by('comment-modify:'.$who($r)),
            Limit::perHour(60)->by('comment-modify-hour:'.$who($r)),
        ]);

        // Follows and unfollows share one bucket per member (idempotent and cheap, but a harassment vector); below 60/min so it can bite.
        RateLimiter::for('follow', fn (Request $r) => [
            Limit::perMinute(20)->by('follow:'.$who($r)),
            Limit::perHour(200)->by('follow-hour:'.$who($r)),
        ]);

        // Reports: a brake per member, below the 60/min `api` bucket.
        RateLimiter::for('report', fn (Request $r) => [
            Limit::perMinute(5)->by('report:'.$who($r)),
            Limit::perHour(30)->by('report-hour:'.$who($r)),
        ]);

        RateLimiter::for('product-media', fn (Request $r) => [
            Limit::perMinute(10)->by('product-media:'.$who($r)),
            Limit::perHour(60)->by('product-media-hour:'.$who($r)),
        ]);

        RateLimiter::for('product-submit', fn (Request $r) => [
            Limit::perMinute(5)->by('product-submit:'.$who($r)),
            Limit::perHour(20)->by('product-submit-hour:'.$who($r)),
        ]);

        // Clients re-sign on every (re)connect and per channel, so this is generous but per member and below the 60/min `api` bucket, which also applies.
        RateLimiter::for('broadcast-auth', fn (Request $r) => Limit::perMinute(30)->by($who($r)));

        // Mark-as-read from several tabs; per member, below the 60/min `api` bucket so it can bite.
        RateLimiter::for('notifications-read', fn (Request $r) => [
            Limit::perMinute(30)->by('notifications-read:'.$who($r)),
            Limit::perHour(300)->by('notifications-read-hour:'.$who($r)),
        ]);

        // Preference toggles from the settings page; per member, below the 60/min `api` bucket so it can bite.
        RateLimiter::for('email-prefs', fn (Request $r) => [
            Limit::perMinute(20)->by('email-prefs:'.$who($r)),
            Limit::perHour(100)->by('email-prefs-hour:'.$who($r)),
        ]);

        // The public one-click unsubscribe, per IP (there is no member). Runs before the signature check, so forged links count too.
        RateLimiter::for('unsubscribe', fn (Request $r) => [
            Limit::perMinute(20)->by('unsubscribe:'.$r->ip()),
            Limit::perHour(100)->by('unsubscribe-hour:'.$r->ip()),
        ]);

        RateLimiter::for('email-change', fn (Request $r) => Limit::perHour(3)->by($who($r)));
    }
}
