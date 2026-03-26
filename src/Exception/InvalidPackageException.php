<?php

declare(strict_types=1);

namespace Apermo\WpUpdateServer\Exception;

use RuntimeException;

/**
 * Thrown when a ZIP archive does not contain a valid WordPress plugin or theme.
 */
class InvalidPackageException extends RuntimeException {
}
