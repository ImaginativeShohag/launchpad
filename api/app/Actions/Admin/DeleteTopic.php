<?php

namespace App\Actions\Admin;

use App\Exceptions\ProductRuleViolation;
use App\Models\Topic;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;

class DeleteTopic
{
    /** @throws ProductRuleViolation when any product still uses the topic */
    public function handle(User $actor, Topic $topic): void
    {
        DB::transaction(function () use ($actor, $topic): void {
            $locked = Topic::query()->whereKey($topic->getKey())->lockForUpdate()->firstOrFail();

            if (DB::table('product_topics')->where('topic_id', $locked->getKey())->exists()) {
                throw ProductRuleViolation::topicInUse();
            }

            // The audit row comes first: it takes FOR KEY SHARE on the actor's user row, and a user row is always
            // locked before follow rows (DeleteAccount holds the member FOR UPDATE and then its follows). Deleting
            // the follows first and then waiting for the actor row could deadlock with that deletion.
            Audit::record('topic.deleted', $actor, $locked, ['topic_id' => $locked->getKey(), 'slug' => $locked->slug]);

            // Plain foreign key: followers of an unused topic go with it (a topic still in use is the 409 above).
            DB::table('topic_follows')->where('topic_id', $locked->getKey())->delete();
            $locked->delete();
        });
    }
}
