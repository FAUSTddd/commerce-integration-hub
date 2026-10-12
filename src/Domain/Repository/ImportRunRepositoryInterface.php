<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\ImportRun;

interface ImportRunRepositoryInterface
{
    public function save(ImportRun $importRun): void;

    public function findActiveRun(string $type, string $filename): ?ImportRun;

    public function findLatestPendingOrProcessing(string $type, string $filename): ?ImportRun;
}
