<?php

namespace App\Mail;

use App\Enums\EmailType;

/** One mail per (recipient, product, kind, UTC hour). It never contains the comment: only that there is one. */
final class NewCommentMail extends OptionalMail
{
    public function __construct(string $userId, string $slug, string $name, string $kind)
    {
        $reply = $kind === 'reply';
        // The reply subject is fixed text: no product name in it. The name appears only in the quoted context line.
        $subject = $reply ? 'New reply to your comment' : 'New comment on '.$name;

        parent::__construct($userId, EmailType::NewComment, $subject, 'new-comment', [
            'heading' => $subject,
            'lead' => $reply ? 'Someone replied to your comment.' : 'Someone commented on your product.',
            'context' => 'Product: "'.$name.'"',
            'url' => MailFor::webUrl('/products/'.$slug),
        ]);
    }
}
