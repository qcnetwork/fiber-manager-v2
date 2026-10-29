<?php

namespace App\Services\Transfer;

use RuntimeException;

/**
 * A problem with the uploaded file as a whole (unreadable, wrong format), shown to the user as-is.
 */
class ImportException extends RuntimeException {}
