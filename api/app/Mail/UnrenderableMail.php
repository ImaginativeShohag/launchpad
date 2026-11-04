<?php

namespace App\Mail;

use RuntimeException;

/** The outbox row cannot be turned into a mail (unknown type, missing or mistyped payload key). A bug, not a transient fault: the row fails at once. */
class UnrenderableMail extends RuntimeException {}
