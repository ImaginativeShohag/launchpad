<?php

use App\Actions\Email\QueueDigest;
use App\Actions\Ranking\Leaderboard;
use App\Models\Product;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A QueueDigest whose chunk size is chosen by the test and which calls $after(kind, period) after every committed chunk
 * of an unfinished run (to stop a run between chunks: throw, or move the clock).
 */
function digestAction(int $chunk = 500, ?Closure $after = null): QueueDigest
{
    return new class(app(Settings::class), app(Leaderboard::class), $chunk, $after) extends QueueDigest
    {
        public function __construct(Settings $settings, Leaderboard $leaderboard, int $chunk, private readonly ?Closure $hook)
        {
            parent::__construct($settings, $leaderboard, $chunk);
        }

        protected function afterChunk(string $kind, string $period): void
        {
            if ($this->hook !== null) {
                ($this->hook)($kind, $period);
            }
        }
    };
}

/** A live product that launched on $day. */
function digestProduct(string $day, array $attributes = []): Product
{
    return Product::factory()->live($day)->create($attributes);
}

/** Freezes $day: one daily_rankings row per product, ranks as given (default 1..n in list order). @param list<Product> $products */
function digestFreeze(string $day, array $products, ?array $ranks = null): void
{
    foreach (array_values($products) as $i => $product) {
        DB::table('daily_rankings')->insert([
            'launch_date' => $day, 'product_id' => $product->id, 'rank' => $ranks[$i] ?? $i + 1, 'votes_count' => 10 - $i, 'score' => 10.0 - $i, 'frozen_at' => $day.' 23:59:00+00',
        ]);
    }
}

/** Takes a product down without touching its launch day. */
function digestTakeDown(Product $product): void
{
    DB::table('products')->where('id', $product->id)->update(['status' => 'taken_down', 'taken_down_at' => now(), 'takedown_reason' => 'test']);
}

/**
 * Raw bulk insert of $count members, ids ascending. Returns the ids in id order.
 *
 * @param  array<string, mixed>  $overrides
 * @return list<string>
 */
function digestUsers(int $count, array $overrides = []): array
{
    $ids = [];
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $id = strtolower((string) Str::ulid());
        $ids[] = $id;
        $rows[] = array_merge([
            'id' => $id, 'handle' => 'd'.substr($id, -20), 'name' => 'Digest User', 'email' => 'd'.substr($id, -20).'@example.com',
            'email_verified_at' => '2026-01-01 00:00:00+00', 'role' => 'member', 'risk_score' => 0, 'created_at' => '2026-01-01 00:00:00+00', 'updated_at' => '2026-01-01 00:00:00+00',
        ], $overrides);
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('users')->insert($chunk);
    }
    sort($ids);

    return $ids;
}

/** @return list<array<string, mixed>> the outbox rows of one type, ordered by user */
function digestMail(string $type): array
{
    return DB::table('email_messages')->where('type', $type)->orderBy('user_id')->get()->map(fn ($r) => (array) $r)->all();
}

/** @return array<string, mixed>|null the digest_runs row */
function digestRun(string $kind, string $period): ?array
{
    $row = DB::table('digest_runs')->where('kind', $kind)->where('period_date', $period)->first();

    return $row === null ? null : (array) $row;
}
