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
    private ?int $currentOutboxId = null;
    private ?string $currentFencingToken = null;
    private ?int $currentAttempt = null;
    private bool $advisoryLockHeld = false;
    private bool $handledSuccessfully = false;

    public function setCurrentRunId(?int $runId): void
    {
        $this->currentRunId = $runId;
    }

    public function getCurrentRunId(): ?int
    {
        return $this->currentRunId;
    }

    public function setCurrentOutboxId(?int $outboxId): void
    {
        $this->currentOutboxId = $outboxId;
    }

    public function getCurrentOutboxId(): ?int
    {
        return $this->currentOutboxId;
    }

    public function setCurrentFencingToken(?string $token): void
    {
        $this->currentFencingToken = $token;
    }

    public function getCurrentFencingToken(): ?string
    {
        return $this->currentFencingToken;
    }

    public function setCurrentAttempt(?int $attempt): void
    {
        $this->currentAttempt = $attempt;
    }

    public function getCurrentAttempt(): ?int
    {
        return $this->currentAttempt;
    }

    public function setAdvisoryLockHeld(bool $held): void
    {
        $this->advisoryLockHeld = $held;
    }

    public function isAdvisoryLockHeld(): bool
    {
        return $this->advisoryLockHeld;
    }

    public function setHandledSuccessfully(bool $success): void
    {
        $this->handledSuccessfully = $success;
    }

    public function isHandledSuccessfully(): bool
    {
        return $this->handledSuccessfully;
    }

    public function reset(): void
    {
        $this->currentRunId = null;
        $this->currentOutboxId = null;
        $this->currentFencingToken = null;
        $this->currentAttempt = null;
        $this->advisoryLockHeld = false;
        $this->handledSuccessfully = false;
    }
}
