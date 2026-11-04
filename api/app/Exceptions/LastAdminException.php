<?php

namespace App\Exceptions;

use RuntimeException;

/** Deleting this account would leave the product without an admin. */
class LastAdminException extends RuntimeException {}
