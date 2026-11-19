<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';       // saved by us, not yet accepted by Pixelforge
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** Statuses only ever move to a higher rank, so late or duplicate events can't undo anything. */
    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isTerminal(): bool
    {
        return $this->rank() === 3;
    }

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}
