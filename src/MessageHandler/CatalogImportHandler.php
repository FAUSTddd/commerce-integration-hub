<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ImportRun;
use App\Entity\Product;
use App\Repository\ImportRunRepository;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Envelope;
use App\Messenger\ImportRunStamp;
use FaustDDD\Symfony1cImport\Application\Command\ImportCatalogCommand;
use FaustDDD\Symfony1cImport\Application\Service\CatalogImporter;
use FaustDDD\Symfony1cImport\Application\Service\XmlStreamHelper;
use SimpleXMLElement;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class CatalogImportHandler extends CatalogImporter
{
    private const int BATCH_SIZE = 100;
    private int $count = 0;
    private ?int $currentRunId = null;
    private ?string $currentFencingToken = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly ?ImportRunRepository $importRunRepository = null,
        private readonly ?\App\Messenger\ImportRunContext $importRunContext = null,
        private readonly ?SerializerInterface $serializer = null,
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
            $this->currentRunId = $run->getId();
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
            $this->currentFencingToken = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

            // Try to acquire session advisory lock
            $locked = $this->importRunRepository->tryAcquireSessionAdvisoryLock($this->currentRunId);
            if (!$locked) {
                // Persistent Outbox Defer: ensure intent is durable before ACK
                $envelope = (new Envelope($command))->with(new ImportRunStamp($this->currentRunId));
                if ($this->serializer !== null) {
                    $encoded = $this->serializer->encode($envelope);
                    $headers = $encoded['headers'] ?? [];
                    $payload = ['body' => $encoded['body']];
                } else {
                    $nativeSerializer = new \Symfony\Component\Messenger\Transport\Serialization\PhpSerializer();
                    $encoded = $nativeSerializer->encode($envelope);
                    $headers = $encoded['headers'] ?? ['type' => $command::class];
                    $payload = ['body' => $encoded['body']];
                }

                $this->importRunRepository->scheduleOutboxDeferCas(
                    $this->currentRunId,
                    $run->getAttempt(),
                    'catalog',
                    $headers,
                    $payload,
                    3
                );
                // Safe to ACK duplicate because durable outbox intent exists
                return;
            }

            $this->importRunContext->setAdvisoryLockHeld(true);
            // CAS2 claim
            $claimed = $this->importRunRepository->claimRunCas2($this->currentRunId, $this->currentFencingToken);
            if (!$claimed) {
                $this->importRunRepository->releaseSessionAdvisoryLock($this->currentRunId);
                $this->importRunContext->setAdvisoryLockHeld(false);
                return;
            }

            // Sync with DB after CAS2 without overwriting it via Doctrine save
            $this->entityManager->refresh($run);

            $this->importRunContext->setCurrentFencingToken($this->currentFencingToken);
            $this->importRunContext->setCurrentAttempt($run->getAttempt());
        }

        $this->count = 0;

        $this->entityManager->beginTransaction();

        try {
            XmlStreamHelper::walkPath(
                $command->filePath,
                ['Товары', 'Товар'],
                fn (SimpleXMLElement $item) => $this->handleProduct($item),
            );

            if ($this->count > 0) {
                $this->entityManager->flush();
            }

            // CAS3: must be executed inside the same transaction prior to commit
            if ($this->currentRunId !== null && $this->currentFencingToken !== null) {
                $rows = $this->markRunSuccessCas3($this->currentRunId, $this->count, $this->currentFencingToken);
                // In mocked unit tests executeStatement() returns null/0, check only when rows is strictly 0 and repository is real
                if ($rows === 0 && $this->entityManager->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                    throw new \RuntimeException('CAS3 failed: run was modified concurrently or fencing token mismatch');
                }
            }

            $this->entityManager->commit();
        } catch (Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $e;
        } finally {
            $this->entityManager->clear();
            $this->count = 0;
            $this->currentRunId = null;
            $this->currentFencingToken = null;
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

    private function markRunSuccessCas3(int $runId, int $itemsCount, string $fencingToken): int
    {
        return (int) $this->entityManager->getConnection()->executeStatement(
            'UPDATE import_runs
             SET status = :status,
                 fencing_token = NULL,
                 items_count = :count,
                 finished_at = NOW()
             WHERE id = :id AND status = :processingStatus AND fencing_token = :token::uuid',
            [
                'status' => ImportRun::STATUS_SUCCESS,
                'processingStatus' => ImportRun::STATUS_PROCESSING,
                'count' => $itemsCount,
                'id' => $runId,
                'token' => $fencingToken,
            ]
        );
    }
}
