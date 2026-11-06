<?php

declare(strict_types=1);

namespace App\Messenger\Exception;

use RuntimeException;

final class StaleSessionAttemptException extends RuntimeException
{
    public static function dueToPoisonedConnection(): self
    {
        return new self('Current attempt session is poisoned due to connection loss. Reconnect and subsequent queries are rejected to prevent split-brain.');
    }
}
