<?php

namespace App\Mail;

use RuntimeException;

/** What the mail is about no longer exists or is no longer visible (a taken-down product, an all-hidden digest). Not a bug: the row is suppressed. */
class MailTargetGone extends RuntimeException {}
