<?php

declare(strict_types=1);

namespace App\Messenger;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Request/Worker scoped context holding the active ImportRun ID for the message being handled.
 */
final class ImportRunContext implements ResetInterface
{
    private ?int $currentRunId = null;

    public function setCurrentRunId(?int $runId): void
    {
        $this->currentRunId = $runId;
    }

    public function getCurrentRunId(): ?int
    {
        return $this->currentRunId;
    }

    public function reset(): void
    {
        $this->currentRunId = null;
    }
}
