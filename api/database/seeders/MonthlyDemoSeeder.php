<?php

namespace Database\Seeders;

use App\Actions\Ranking\FreezeDay;
use App\Actions\Ranking\HotScore;
use App\Enums\ProductStatus;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use App\Models\Vote;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class MonthlyDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data is only available locally or in tests.');
        }

        $now = CarbonImmutable::now('UTC');
        $month = CarbonImmutable::parse(CarbonImmutable::now('Asia/Dhaka')->format('Y-m-01'), 'UTC');

        DB::transaction(function () use ($now, $month): void {
            $this->call([SettingSeeder::class, TopicSeeder::class]);
            $topics = Topic::query()->orderBy('position')->get()->all();
            $score = HotScore::fromSettings(app(Settings::class));
            $members = collect(range(1, 24))->map(fn (int $number): User => User::query()->where('email', "monthly-demo-{$number}@example.test")->first()
                ?? User::factory()->withoutPassword()->create([
                    'email' => "monthly-demo-{$number}@example.test",
                    'handle' => "monthly-demo-{$number}",
                    'created_at' => $month->subMonth(),
                    'updated_at' => $month->subMonth(),
                    'email_verified_at' => $month->subMonth(),
                ]))->all();

            foreach (range(1, $month->daysInMonth) as $day) {
                $date = $month->setDay($day);
                $live = $date->lessThanOrEqualTo($now->startOfDay());
                $at = $date->isSameDay($now) ? $now : $date->setHour(12);

                foreach (range(1, 10) as $slot) {
                    $slug = 'monthly-demo-'.$date->format('Y-m-d').'-'.$slot;
                    if (Product::withTrashed()->where('slug', $slug)->exists()) {
                        continue;
                    }

                    $topic = $topics[($day + $slot - 2) % count($topics)];
                    $maker = $members[($day + $slot - 2) % count($members)];
                    $voters = collect($members)->reject(fn (User $user): bool => $user->id === $maker->id)->take($slot * 6);
                    $votes = $live ? $voters->count() : 0;
                    $product = Product::factory()->create([
                        'maker_id' => $maker->id,
                        'slug' => $slug,
                        'name' => $topic->name.' '.['Studio', 'Companion', 'Toolkit'][($slot - 1) % 3].' '.$day,
                        'tagline' => 'A simpler way to explore '.strtolower($topic->name).'.',
                        'description' => 'A sample launch for makers and curious teams. Organize your work, share ideas, and make steady progress with a focused set of everyday tools.',
                        'website_url' => 'https://example.com',
                        'status' => $live ? ProductStatus::Live : ProductStatus::Scheduled,
                        'launch_date' => $date->toDateString(),
                        'submitted_at' => $month->subDays(2),
                        'approved_at' => $month->subDay(),
                        'launched_at' => $live ? $date : null,
                        'created_at' => $month->subDays(3),
                        'updated_at' => $now,
                        'votes_count' => $votes,
                        'comments_count' => $live ? 2 : 0,
                        'hot_score' => $live ? $score->score([$at->hour => $votes], $date->isSameDay($now) ? $now->hour : 23) : 0,
                    ]);
                    $product->topics()->attach($topic->id);

                    if (! $live) {
                        continue;
                    }

                    foreach ($voters as $voter) {
                        Vote::factory()->create([
                            'product_id' => $product->id, 'user_id' => $voter->id,
                            'voted_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                        ]);
                    }

                    foreach ($voters->take(2) as $voter) {
                        Comment::factory()->createdAt($at)->create([
                            'product_id' => $product->id, 'user_id' => $voter->id,
                            'body' => 'This looks useful! How are you planning to develop it further?',
                        ]);
                    }

                    DB::table('product_hourly_stats')->insert([
                        'product_id' => $product->id, 'hour_start' => $at->startOfHour(),
                        'votes' => $votes, 'views' => $votes * 8,
                    ]);
                }
            }
        });

        // Existing frozen ranks stay final; the normal backfill appends new demo launches.
        $freeze = app(FreezeDay::class);
        $freeze->handle($now, $month->toDateString());
        if ($freeze->failed > 0) {
            throw new LogicException('Demo launches were saved, but ranking backfill failed. Rerun the seeder.');
        }
    }
}
