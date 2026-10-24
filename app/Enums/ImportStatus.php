<?php

namespace App\Enums;

/**
 * Where an import run is: uploaded (columns not yet matched), previewed
 * (dry run done; nothing written), queued and running (being written, in
 * one transaction), then completed or failed (nothing written).
 */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Previewed = 'previewed';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Previewed => 'Checked, not imported',
            self::Queued => 'Waiting to import',
            self::Running => 'Importing',
            self::Completed => 'Imported',
            self::Failed => 'Failed, nothing imported',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Failed => 'danger',
            self::Queued, self::Running => 'warning',
            default => 'gray',
        };
    }

    /** Still being written: the page keeps showing progress. */
    public function isActive(): bool
    {
        return in_array($this, [self::Queued, self::Running], true);
    }
}
