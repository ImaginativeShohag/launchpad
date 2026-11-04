<?php

use App\Actions\Notification\NotificationDrafts;
use App\Enums\EmailType;
use App\Events\ExportReady;
use App\Listeners\RecordExportReadyNotification;
use App\Mail\MailFor;
use App\Mail\MailTargetGone;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/MailFixtures.php';

beforeEach(function () {
    Storage::fake('exports');
    $this->travelTo(dashNow());
});

/** [bell rows, outbox rows] */
function exportNotifRows(): array
{
    return [DB::table('notifications')->get()->map(fn ($r) => (array) $r)->all(), DB::table('email_messages')->get()->map(fn ($r) => (array) $r)->all()];
}

it('writes one bell row and one mail for the maker when an export finishes, through the real queued listener', function () {
    // Given a queued export of a maker's live product (sync queue, real listeners: no Event::fake)
    $maker = User::factory()->create();
    $product = Product::factory()->live(DASH_DAY)->create(['maker_id' => $maker->id, 'name' => 'Acme']);
    $export = exportFor($product, 'queued');

    // When the job runs
    exportGenerate($export);

    // Then one bell row and one outbox row exist for the maker, pointing at the dashboard and holding no file path or url
    [$bell, $mail] = exportNotifRows();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(1);
    expect($bell[0]['user_id'])->toBe($maker->id)->and($bell[0]['type'])->toBe('export_ready')->and($bell[0]['dedupe_key'])->toBe('export_ready:'.$export->id);
    $data = json_decode($bell[0]['data'], true);
    expect($data)->toEqual([
        'message_key' => 'notifications.export_ready', 'product_id' => $product->id, 'params' => ['product_name' => 'Acme', 'days' => 7], 'href' => '/me/products/'.$product->id.'/dashboard',
    ]);
    expect($mail[0]['type'])->toBe('export_ready')->and($mail[0]['idempotency_key'])->toBe('export_ready:'.$export->id.':'.$maker->id);
    expect(json_decode($mail[0]['payload'], true))->toEqual(['export_id' => $export->id, 'product_id' => $product->id]);
    foreach ([json_encode($data), $mail[0]['payload']] as $text) {
        expect($text)->not->toContain('.zip')->not->toContain('exports/')->not->toContain('download')->not->toContain('file_path');
    }
});

it('creates nothing more when the same event arrives twice or the listener re-runs', function () {
    // Given a finished export that was announced
    $export = exportFor(Product::factory()->live(DASH_DAY)->create(), 'queued');
    exportGenerate($export);

    // When the event is dispatched again and the listener called directly
    ExportReady::dispatch($export->id, $export->product_id, $export->user_id);
    app(RecordExportReadyNotification::class)->notify(new ExportReady($export->id, $export->product_id, $export->user_id));

    // Then there is still one bell row and one mail
    [$bell, $mail] = exportNotifRows();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(1);
});

it('keeps the bell row but sends no mail when the member switched the mail off', function () {
    // Given a maker who opted out of export mail
    $maker = User::factory()->create();
    DB::table('email_preferences')->insert(['user_id' => $maker->id, 'type' => 'export_ready', 'enabled' => false, 'updated_at' => now()]);
    $export = exportFor(Product::factory()->live(DASH_DAY)->create(['maker_id' => $maker->id]), 'queued');

    // When the job runs
    exportGenerate($export);

    // Then the bell row exists and no outbox row was written
    [$bell, $mail] = exportNotifRows();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(0);
});

it('suppresses a queued mail at send time when the member opts out afterwards', function () {
    // Given a finished export with its queued mail, and the member opting out before it is sent
    $maker = User::factory()->create();
    $export = exportFor(Product::factory()->live(DASH_DAY)->create(['maker_id' => $maker->id]), 'queued');
    exportGenerate($export);
    DB::table('email_preferences')->insert(['user_id' => $maker->id, 'type' => 'export_ready', 'enabled' => false, 'updated_at' => now()]);

    // When the outbox is sent
    $this->artisan('launchpad:send-emails')->assertExitCode(0);

    // Then the mail was suppressed, not sent
    expect(DB::table('email_messages')->value('status'))->toBe('suppressed');
    expect(outboxSent())->toHaveCount(0);
});

it('writes no mail for an unverified owner but keeps the bell row', function () {
    // Given an unverified maker
    $maker = User::factory()->unverified()->create();
    $export = exportFor(Product::factory()->live(DASH_DAY)->create(['maker_id' => $maker->id]), 'queued');

    // When the job runs
    exportGenerate($export);

    // Then only the bell row exists
    [$bell, $mail] = exportNotifRows();
    expect($bell)->toHaveCount(1)->and($mail)->toHaveCount(0);
});

it('writes nothing for an export that is no longer ready', function (string $state) {
    // Given an export in a state other than ready
    // (with the dates a finished export had, so only the status can tell them apart)
    $export = exportFor(Product::factory()->live(DASH_DAY)->create(), $state, ['completed_at' => now(), 'expires_at' => now()->addDay()]);

    // When the listener runs for it
    app(RecordExportReadyNotification::class)->notify(new ExportReady($export->id, $export->product_id, $export->user_id));

    // Then nothing is written
    expect(exportNotifRows())->toBe([[], []]);
})->with(['queued', 'processing', 'failed', 'expired']);

it('writes nothing for an export that does not exist', function () {
    // Given an unknown export id
    // When the listener runs
    app(RecordExportReadyNotification::class)->notify(new ExportReady(exportUnknownId(), exportUnknownId(), exportUnknownId()));

    // Then nothing is written
    expect(exportNotifRows())->toBe([[], []]);
});

it('writes nothing for a deleted or suspended owner', function (string $column) {
    // Given a ready export whose owner was deleted or suspended
    $export = exportFor(Product::factory()->live(DASH_DAY)->create(), 'ready');
    DB::table('users')->where('id', $export->user_id)->update([$column => now()]);

    // When the listener runs
    app(RecordExportReadyNotification::class)->notify(new ExportReady($export->id, $export->product_id, $export->user_id));

    // Then nothing is written
    expect(exportNotifRows())->toBe([[], []]);
})->with(['deleted_at', 'suspended_at']);

it('is reached exactly once per event, as a queued listener whose method is not handle', function () {
    // Given a faked queue
    Queue::fake();

    // When the event is dispatched
    event(new ExportReady('e', 'p', 'u'));

    // Then one queued listener job was pushed, calling `notify`
    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === RecordExportReadyNotification::class && $job->method === 'notify');
    $reflection = new ReflectionClass(RecordExportReadyNotification::class);
    $instance = new RecordExportReadyNotification;
    expect($reflection->hasMethod('handle'))->toBeFalse()->and($instance)->toBeInstanceOf(ShouldQueue::class)
        ->and($instance->tries)->toBe(5)->and($instance->backoff())->toBe([10, 60, 300, 900])->and($instance->timeout)->toBeLessThan(90);
});

it('renders a mail that links to the dashboard and never to a file', function () {
    // Given a queued export mail for a maker
    $maker = User::factory()->create();
    $product = Product::factory()->live(DASH_DAY)->create(['maker_id' => $maker->id, 'name' => 'Acme']);
    $export = exportFor($product, 'ready');
    $message = outboxMessage(EmailType::ExportReady, ['export_id' => $export->id, 'product_id' => $product->id], $maker);

    // When it is built and captured
    $email = outboxCapture(MailFor::make($message));

    // Then the subject, the dashboard link and the opt-out footer are there, with no file path or download link
    expect($email->getSubject())->toBe('Your export of Acme is ready');
    foreach ([(string) $email->getHtmlBody(), (string) $email->getTextBody()] as $body) {
        expect($body)->toContain('/me/products/'.$product->id.'/dashboard')->toContain('/unsubscribe?')->not->toContain('.zip')->not->toContain('/download');
    }
});

it('refuses to build the mail when the export is no longer ready or is not the member\'s', function (string $case) {
    // Given a mail row for an export in that situation
    $product = Product::factory()->live(DASH_DAY)->create();
    $export = exportFor($product, 'ready');
    $user = User::query()->findOrFail($export->user_id);
    if ($case === 'expired') {
        DB::table('exports')->where('id', $export->id)->update(['status' => 'expired']);
    } else {
        $user = User::factory()->create();
    }

    // When it is built
    // Then the target is gone
    expect(fn () => MailFor::make(outboxMessage(EmailType::ExportReady, ['export_id' => $export->id, 'product_id' => $product->id], $user)))->toThrow(MailTargetGone::class);
})->with(['expired', 'someone else']);

it('builds the draft from ids read at listener time and only for a ready export', function () {
    // Given a ready and a failed export
    $ready = exportFor(Product::factory()->live(DASH_DAY)->create(), 'ready');
    $failed = exportFor(Product::factory()->live(DASH_DAY)->create(), 'failed');

    // When the drafts are built
    // Then only the ready one yields a draft
    expect(NotificationDrafts::exportReady($ready->id))->not->toBeNull()->and(NotificationDrafts::exportReady($failed->id))->toBeNull()->and(NotificationDrafts::exportReady(exportUnknownId()))->toBeNull();
});
