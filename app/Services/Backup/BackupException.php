<?php

namespace App\Services\Backup;

use RuntimeException;

/** A backup, verify or restore step that failed; the message says which and why. */
class BackupException extends RuntimeException {}
