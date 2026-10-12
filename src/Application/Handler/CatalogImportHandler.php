<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Domain\Model\ImportRun;
use App\Domain\Model\Product;
use App\Domain\Repository\ImportRunRepositoryInterface;
use App\Domain\Repository\ProductRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Service\CatalogImporter;
use SimpleXMLElement;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class CatalogImportHandler extends CatalogImporter
{
    private const int BATCH_SIZE = 100;
    private int $count = 0;
    private ?int $currentRunId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ?ImportRunRepositoryInterface $importRunRepository = null,
    ) {}

    protected function import(ImportCatalogCommand $command): void
    {
        $filePath = $command->filePath;
        $filename = basename($filePath);
        $fileHash = is_file($filePath) ? (string) sha1_file($filePath) : 'unknown';

        $run = $this->importRunRepository?->findLatestPendingOrProcessing('catalog', $filename);
        if ($run === null && $this->importRunRepository !== null) {
            $run = new ImportRun('catalog', $filename, $fileHash);
        }

        if ($run !== null) {
            $run->setStatus(ImportRun::STATUS_PROCESSING);
            $run->setStartedAt(new \DateTimeImmutable());
            $this->importRunRepository?->save($run);
            $this->currentRunId = $run->getId();
        }

        $this->count = 0;
        $totalImported = 0;

        $this->entityManager->beginTransaction();

        try {
            parent::import($command);

            if ($this->count > 0) {
                $this->entityManager->flush();
                $totalImported += $this->count;
            }

            $this->entityManager->commit();

            if ($this->currentRunId !== null) {
                $this->markRunSuccess($this->currentRunId, $totalImported);
            }
        } catch (Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            if ($this->currentRunId !== null) {
                $this->markRunFailed($this->currentRunId, $e->getMessage());
            }

            throw $e;
        } finally {
            $this->entityManager->clear();
            $this->count = 0;
            $this->currentRunId = null;
        }
    }

    protected function handleProduct(SimpleXMLElement $item): void
    {
        $externalId = trim((string) $item->Ид);
        if ($externalId === '') {
            return;
        }

        $name = trim((string) $item->Наименование);
        $sku = isset($item->Артикул) ? trim((string) $item->Артикул) : null;
        if ($sku === '') {
            $sku = null;
        }

        $product = $this->productRepository->findByExternalId($externalId);
        if ($product === null) {
            $product = new Product($externalId, $name, $sku);
            $this->productRepository->save($product);
        } else {
            $product->setName($name);
            $product->setSku($sku);
        }

        ++$this->count;
        if ($this->count % self::BATCH_SIZE === 0) {
            $this->entityManager->flush();
            $this->entityManager->commit();
            $this->entityManager->clear();

            $this->entityManager->beginTransaction();
        }
    }

    private function markRunSuccess(int $runId, int $itemsCount): void
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE import_runs SET status = :status, items_count = :count, finished_at = NOW() WHERE id = :id',
                [
                    'status' => ImportRun::STATUS_SUCCESS,
                    'count' => $itemsCount,
                    'id' => $runId,
                ]
            );
        } catch (Throwable) {
        }
    }

    private function markRunFailed(int $runId, string $errorMessage): void
    {
        try {
            $this->entityManager->getConnection()->executeStatement(
                'UPDATE import_runs SET status = :status, error_message = :error, finished_at = NOW() WHERE id = :id',
                [
                    'status' => ImportRun::STATUS_FAILED,
                    'error' => mb_substr($errorMessage, 0, 2000),
                    'id' => $runId,
                ]
            );
        } catch (Throwable) {
        }
    }
}
