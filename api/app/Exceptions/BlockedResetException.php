<?php

namespace App\Exceptions;

use RuntimeException;

/** Thrown inside the reset callback for a suspended or deleted account so the transaction rolls back. */
final class BlockedResetException extends RuntimeException {}
