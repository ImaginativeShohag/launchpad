<?php

namespace App\Exceptions;

use RuntimeException;

/** Google could not be reached (transport failure or 5xx); the caller may retry later. */
class GoogleUnavailable extends RuntimeException {}
