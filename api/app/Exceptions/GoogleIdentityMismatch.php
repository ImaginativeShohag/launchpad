<?php

namespace App\Exceptions;

use RuntimeException;

/** The matched member is already linked to a different Google account. */
class GoogleIdentityMismatch extends RuntimeException {}
