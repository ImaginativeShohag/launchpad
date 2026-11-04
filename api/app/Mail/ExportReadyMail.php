<?php

namespace App\Mail;

use App\Enums\EmailType;

/** The export is ready. Links to the maker's dashboard, never to the file: the download needs a signed-in session. */
final class ExportReadyMail extends OptionalMail
{
    public function __construct(string $userId, string $productId, string $name)
    {
        parent::__construct($userId, EmailType::ExportReady, "Your export of {$name} is ready", 'export-ready', [
            'heading' => "Your export of {$name} is ready", 'name' => $name,
            'url' => MailFor::webUrl('/me/products/'.$productId.'/dashboard'),
        ]);
    }
}
