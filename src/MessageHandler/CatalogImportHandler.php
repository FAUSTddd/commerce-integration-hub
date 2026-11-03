<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\Repository\ImportRunRepository;
use App\Repository\ProductRepository;
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
        private readonly ProductRepository $productRepository,
        private readonly ?ImportRunRepository $importRunRepository = null,
        private readonly ?\App\Messenger\ImportRunContext $importRunContext = null,
    ) {}

    protected function import(ImportCatalogCommand $command): void
    {
        $filePath = $command->filePath;
        $filename = basename($filePath);
        $fileHash = is_file($filePath) ? (string) sha1_file($filePath) : 'unknown';

        $run = null;
        if ($this->importRunContext !== null && $this->importRunContext->getCurrentRunId() !== null) {
            $run = $this->importRunRepository?->find($this->importRunContext->getCurrentRunId());
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
            $this->entityManager->clear();
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
}
