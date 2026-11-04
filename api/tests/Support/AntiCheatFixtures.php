<?php

use App\Actions\AntiCheat\DetectAbuse;
use App\Actions\AntiCheat\DetectionResult;
use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\FlagResult;
use App\Actions\AntiCheat\FlagVotes;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\Rule;
use App\Actions\AntiCheat\Scan;
use App\Actions\Vote\HourlyBuckets;
use App\Enums\AuthEventType;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Product;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\Vote;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** The fixed clock of every anti-cheat test: windows are measured to the second. */
function antiCheatNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
}

/** Freezes the application clock at antiCheatNow() and returns it. */
function antiCheatFreeze(): CarbonImmutable
{
    test()->travelTo(antiCheatNow());

    return antiCheatNow();
}

/** A deterministic 64-hex "hash" for a device or IP number (the real ones are keyed HMACs; the rules only compare them). */
function antiCheatHash(string $label): string
{
    return hash('sha256', 'anti-cheat-test-'.$label);
}

/** A live product launched on the given UTC day (default: the fixed clock's day), with a fresh maker unless one is given. */
function antiCheatLiveProduct(?string $launchDate = null, array $attributes = []): Product
{
    $date = $launchDate ?? antiCheatNow()->toDateString();
    $factory = Product::factory()->live($date);

    return $attributes === [] ? $factory->create() : $factory->create($attributes);
}

/**
 * A vote row, inserted through the factory (the vote actions are not the subject here). `$user` and `$product` may be models,
 * ids, or null for a fresh one; `$attributes` override any column (`voted_at`, `device_hash`, `status`, ...).
 */
function antiCheatVote(User|string|null $user, Product|string|null $product, array $attributes = []): Vote
{
    $userId = $user instanceof User ? $user->id : ($user ?? User::factory()->create()->id);
    $productId = $product instanceof Product ? $product->id : ($product ?? antiCheatLiveProduct()->id);

    return Vote::factory()->create([
        'user_id' => $userId, 'product_id' => $productId, 'voted_at' => antiCheatNow(), ...$attributes,
    ]);
}

/** Several members who voted on one product from one device (and, when asked, one IP) at the fixed time. */
function antiCheatRing(Product $product, int $size, string $device = 'ring', ?string $ip = null, array $voteAttributes = [], array $userAttributes = []): array
{
    $users = [];
    for ($i = 0; $i < $size; $i++) {
        $user = User::factory()->create($userAttributes);
        antiCheatVote($user, $product, [
            'device_hash' => antiCheatHash('device-'.$device), 'ip_hash' => $ip === null ? antiCheatHash('ip-'.Str::ulid()) : antiCheatHash('ip-'.$ip), ...$voteAttributes,
        ]);
        $users[] = $user;
    }

    return $users;
}

/** An auth_events row (inserted directly: the model has no factory). */
function antiCheatAuthEvent(User|string|null $user, string|CarbonImmutable $createdAt, string $device = 'a', AuthEventType $event = AuthEventType::LoginSucceeded, array $attributes = []): string
{
    $id = (string) Str::ulid();
    DB::table('auth_events')->insert([
        'id' => $id, 'user_id' => $user instanceof User ? $user->id : $user, 'event' => $event->value, 'ip_hash' => antiCheatHash('auth-ip-'.$device),
        'device_hash' => antiCheatHash('device-'.$device), 'user_agent' => 'Test', 'created_at' => (string) $createdAt, ...$attributes,
    ]);

    return $id;
}

/**
 * Writes anti-cheat settings through the admin path (`Settings::set`), then reads a fresh RuleConfig.
 *
 * @param  array<string, int|string>  $overrides  setting key => value
 */
function antiCheatConfig(array $overrides = []): RuleConfig
{
    $admin = User::factory()->role(Role::Admin)->create();
    $settings = app(Settings::class);

    foreach ($overrides as $key => $value) {
        $settings->set(SettingKey::from($key), $value, $admin);
    }

    return RuleConfig::fromSettings($settings);
}

/** A hand-built finding for the writer tests. */
function antiCheatFinding(string $key, ?string $userId, ?string $productId, array $attributes = []): Finding
{
    return new Finding(
        $attributes['kind'] ?? RiskSignalKind::SharedDevice, $userId, $productId, $attributes['weight'] ?? 40,
        $attributes['details'] ?? ['rule' => 'shared_device', 'threshold' => 3, 'observed' => 3], $key,
    );
}

/** Every SQL statement run while the callback executes, in order (lower-cased, bindings dropped). */
function antiCheatQueries(Closure $callback): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });
    $callback();

    return $queries;
}

/**
 * Runs a rule once and returns its findings as a list.
 *
 * @return list<Finding>
 */
function antiCheatFindings(Rule $rule, Scan $scan, ?RuleConfig $config = null): array
{
    return array_values(iterator_to_array($rule->detect($config ?? antiCheatConfig(), $scan), false));
}

/** The product scan of the fixed clock. */
function antiCheatProductScan(Product ...$products): Scan
{
    return Scan::products(antiCheatNow(), array_map(fn (Product $p) => $p->id, $products));
}

/** The user scan of the fixed clock. */
function antiCheatUserScan(User ...$users): Scan
{
    return Scan::users(antiCheatNow(), array_map(fn (User $u) => $u->id, $users));
}

/** The sorted dedupe keys of every stored signal. */
function antiCheatKeys(): array
{
    return RiskSignal::query()->orderBy('dedupe_key')->pluck('dedupe_key')->all();
}

/** One run at the fixed instant. */
function antiCheatDetect(array $args = []): DetectionResult
{
    return app(DetectAbuse::class)->handle(antiCheatNow(), ...$args);
}

/** A fixture with every client case once: a ring, a script, a fresh account and a throwaway address. */
function antiCheatCases(): array
{
    $old = ['created_at' => antiCheatNow()->subDays(10)];
    $product = antiCheatLiveProduct();
    $ring = antiCheatRing($product, 4, userAttributes: $old);

    $burster = User::factory()->create($old);
    for ($i = 0; $i < 8; $i++) {
        antiCheatVote($burster, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subSeconds(60 + $i)]);
    }

    $fresh = User::factory()->create(['created_at' => antiCheatNow()->subMinutes(10)]);
    antiCheatVote($fresh, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subMinutes(5)]);

    $throwaway = User::factory()->create([...$old, 'email' => 'x'.strtolower((string) Str::ulid()).'@mailinator.com']);
    antiCheatVote($throwaway, antiCheatLiveProduct());

    return compact('product', 'ring', 'burster', 'fresh', 'throwaway');
}

/** The stored risk score of a member. */
function antiCheatScoreOf(User|string $user): int
{
    return (int) DB::table('users')->where('id', $user instanceof User ? $user->id : $user)->value('risk_score');
}

/** A stored signal about a member (factory), by kind, weight and age in days, optionally already reviewed. */
function antiCheatSignal(User $user, RiskSignalKind $kind, int $weight, int $ageDays = 0, ?RiskOutcome $outcome = null, array $attributes = []): RiskSignal
{
    $factory = RiskSignal::factory()->kind($kind, $weight)->detectedAt(antiCheatNow()->subDays($ageDays));

    if ($outcome !== null) {
        $factory = $factory->reviewed($outcome);
    }

    return $factory->create(['user_id' => $user->id, 'product_id' => null, ...$attributes]);
}

/** Sets a member's stored score directly (the way a previous run left it). */
function antiCheatSetScore(User $user, int $score): User
{
    DB::table('users')->where('id', $user->id)->update(['risk_score' => $score]);

    return $user;
}

/** The stored status of a vote. */
function antiCheatVoteStatus(Vote|string $vote): string
{
    return (string) DB::table('votes')->where('id', $vote instanceof Vote ? $vote->id : $vote)->value('status');
}

/** One flagging pass at the fixed instant. */
function antiCheatFlag(array $args = []): FlagResult
{
    return app(FlagVotes::class)->handle(antiCheatNow(), ...$args);
}

/** A member with a stored score and one vote on a fresh live product launched on the given day (default: the fixed day). */
function antiCheatScoredVoter(int $score, ?string $launchDate = null, array $voteAttributes = [], array $userAttributes = []): Vote
{
    $user = User::factory()->create($userAttributes);
    antiCheatSetScore($user, $score);

    return antiCheatVote($user, antiCheatLiveProduct($launchDate), $voteAttributes);
}

/** A keyset cursor as the client would send it (base64url JSON), for tamper tests. */
function antiCheatCursor(mixed $payload): string
{
    return rtrim(strtr(base64_encode(is_string($payload) ? $payload : json_encode($payload)), '+/', '-_'), '=');
}

/** GET a staff anti-cheat URI with a query (array, or a raw query string kept as written). */
function antiCheatGet(string $path, array|string $query = ''): TestResponse
{
    $qs = is_array($query) ? http_build_query($query) : $query;

    return test()->getJson('/api/v1/staff/'.$path.($qs === '' ? '' : '?'.$qs));
}

/** The ids of a list response, in order. */
function antiCheatIds(TestResponse $response): array
{
    return array_column($response->json('data'), 'id');
}

/** Walks a cursor list page by page and returns every id in order (fails on a repeated page or an endless chain). */
function antiCheatWalk(string $path, array $query, int $perPage = 2): array
{
    $ids = [];
    $query = [...$query, 'per_page' => $perPage];

    for ($i = 0; $i < 60; $i++) {
        $response = antiCheatGet($path, $query)->assertOk();
        $ids = [...$ids, ...antiCheatIds($response)];
        $next = $response->json('meta.next_cursor');

        if ($next === null) {
            return $ids;
        }

        $query['cursor'] = $next;
    }

    throw new RuntimeException('The cursor chain did not end.');
}

/** A live product launching `$offset` days from the fixed clock's day (negative = a frozen day). */
function antiCheatLaunching(int $offset = 0): Product
{
    return antiCheatLiveProduct(antiCheatNow()->addDays($offset)->toDateString());
}

/**
 * A vote that really counts: the row, the product counter and the hourly bucket of its `voted_at` (the way CastVote leaves them),
 * so a void has something to take back. A `status`/`removed_at` override decides whether the counters include it.
 */
function antiCheatCountedVote(User $user, Product $product, array $attributes = []): Vote
{
    $vote = antiCheatVote($user, $product, ['voted_at' => antiCheatNow(), ...$attributes]);

    if ($vote->removed_at === null && $vote->status->counts()) {
        DB::table('products')->where('id', $product->id)->increment('votes_count');
        HourlyBuckets::add($product->id, $vote->voted_at, 1);
    }

    return $vote;
}

/** POST a staff anti-cheat write. */
function antiCheatPost(string $path, mixed $body = []): TestResponse
{
    return test()->postJson('/api/v1/staff/'.$path, $body);
}

/** Every statement run while the callback executes, in order, WITH its bindings and the transaction depth when it ran. */
function antiCheatTrace(Closure $callback): array
{
    $trace = [];
    DB::listen(function ($query) use (&$trace) {
        $trace[] = ['sql' => strtolower($query->sql), 'bindings' => $query->bindings, 'level' => DB::transactionLevel()];
    });
    $callback();

    return $trace;
}

/** A stored signal with explicit times: detected `$detectedAgo` and, when `$outcome` is given, reviewed `$reviewedAgo` (both as seconds before the fixed clock (2026-10-21 12:00 UTC, which is also the retention tests' instant)). */
function antiCheatPurgeSignal(int|float $detectedAgoDays, ?RiskOutcome $outcome = null, int|float|null $reviewedAgoDays = null, RiskSignalKind $kind = RiskSignalKind::SharedDevice, ?User $user = null): string
{
    $detected = antiCheatNow()->subSeconds((int) round($detectedAgoDays * 86400));
    $attributes = ['kind' => $kind->value, 'user_id' => ($user ?? User::factory()->create())->id, 'product_id' => null, 'detected_at' => $detected];

    if ($outcome !== null) {
        $attributes += ['reviewed_by' => User::factory()->role(Role::Reviewer)->create()->id, 'reviewed_at' => antiCheatNow()->subSeconds((int) round(($reviewedAgoDays ?? 0) * 86400)), 'outcome' => $outcome->value];
    }

    return RiskSignal::factory()->kind($kind, 40)->create($attributes)->id;
}

/** @param  list<string>  $ids */
function antiCheatPurgeSurvivors(array $ids): array
{
    return collect($ids)->filter(fn ($id) => DB::table('risk_signals')->where('id', $id)->exists())->values()->all();
}

/** Whatever must never appear in anything staff read here (or anyone else reads at all). */
function antiCheatPrivacyLeaks(string $body): array
{
    $leaks = [];
    $patterns = [
        'a 64-character hash' => '/[0-9a-f]{64}/i',
        'an e-mail address' => '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/',
        'an IPv4 address' => '/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/',
        'an IPv6 address' => '/(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}|(?:[0-9a-f]{1,4}:){1,6}:[0-9a-f]{0,4}/i',
        'a user agent' => '/Mozilla|PrivacyUA|device_hash|ip_hash|user_agent/i',
    ];

    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $body) === 1) {
            $leaks[] = $name;
        }
    }

    return $leaks;
}

/**
 * Makes the default queue a connection whose push throws while `['down']` is true and runs the job synchronously after.
 * Returns the switch.
 */
function antiCheatFailingQueue(): ArrayObject
{
    $broken = new ArrayObject(['down' => true]);
    Queue::extend('failing', fn () => new class($broken) implements ConnectorInterface
    {
        public function __construct(private readonly ArrayObject $broken) {}

        public function connect(array $config)
        {
            $queue = new class($this->broken) extends SyncQueue
            {
                public function __construct(private readonly ArrayObject $broken) {}

                public function push($job, $data = '', $queue = null)
                {
                    if ($this->broken['down']) {
                        throw new RuntimeException('queue down');
                    }

                    return parent::push($job, $data, $queue);
                }
            };
            $queue->setContainer(app());

            return $queue;
        }
    });
    config(['queue.connections.failing' => ['driver' => 'failing'], 'queue.default' => 'failing']);

    return $broken;
}
