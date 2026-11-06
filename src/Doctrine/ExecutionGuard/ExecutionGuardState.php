<?php

declare(strict_types=1);

namespace App\Doctrine\ExecutionGuard;

use Symfony\Contracts\Service\ResetInterface;

final class ExecutionGuardState implements ResetInterface
{
    private ?string $activeEpoch = null;
    private bool $isPoisoned = false;

    public function startEpoch(string $epoch): void
    {
        $this->activeEpoch = $epoch;
        $this->isPoisoned = false;
    }

    public function poisonCurrentEpoch(): void
    {
        if ($this->activeEpoch !== null) {
            $this->isPoisoned = true;
        }
    }

    public function isPoisoned(): bool
    {
        return $this->isPoisoned;
    }

    public function getActiveEpoch(): ?string
    {
        return $this->activeEpoch;
    }

    public function reset(): void
    {
        $this->activeEpoch = null;
        $this->isPoisoned = false;
    }
}
