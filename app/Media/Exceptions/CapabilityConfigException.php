<?php

namespace App\Media\Exceptions;

use RuntimeException;

/**
 * Raised when a stored/published capability is broken or an operation is unsupported.
 * NEVER caught to silently fall back to legacy — a broken published config is an
 * actionable admin error.
 */
class CapabilityConfigException extends RuntimeException {}
