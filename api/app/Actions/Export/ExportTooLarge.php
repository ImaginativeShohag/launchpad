<?php

namespace App\Actions\Export;

use RuntimeException;

/** The finished zip is over the 5 MB cap. The export fails with the code `too_large` and is not retried. */
final class ExportTooLarge extends RuntimeException {}
