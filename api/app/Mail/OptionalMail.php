<?php

namespace App\Mail;

use App\Enums\EmailType;
use App\Support\UnsubscribeLink;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Base of the six outbox mails: a fixed subject, an HTML and a text view, the footer unsubscribe link and the one-click
 * `List-Unsubscribe` headers (RFC 8058). The three auth mails are Laravel notifications and carry none of this.
 * Subclasses pass ids and already-cleaned names only; views print them with `{{ }}`.
 */
abstract class OptionalMail extends Mailable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        protected readonly string $userId,
        protected readonly EmailType $emailType,
        protected readonly string $mailSubject,
        protected readonly string $viewName,
        protected readonly array $data,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        // The view names are built from the constants the six subclasses pass (never from input), so they are view-strings.
        /** @var view-string $view */
        $view = 'mail.'.(string) $this->viewName; // @phpstan-ignore varTag.nativeType
        /** @var view-string $text */
        $text = 'mail.text.'.(string) $this->viewName; // @phpstan-ignore varTag.nativeType

        return new Content(
            view: $view,
            text: $text,
            with: [...$this->data, 'unsubscribeUrl' => UnsubscribeLink::web($this->userId, $this->emailType)],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.UnsubscribeLink::api($this->userId, $this->emailType).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
