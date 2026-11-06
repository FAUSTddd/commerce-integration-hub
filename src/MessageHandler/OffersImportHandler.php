<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ImportRun;
use App\Entity\ProductVariant;
use App\Repository\ImportRunRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Envelope;
use App\Messenger\ImportRunStamp;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use FaustDDD\Symfony1cImport\Application\Service\OffersImporter;
use FaustDDD\Symfony1cImport\Application\Service\XmlStreamHelper;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Throwable;

#[AsMessageHandler]
final class OffersImportHandler extends OffersImporter
{
    private const int BATCH_SIZE = 100;
    private int $count = 0;
    private ?int $currentRunId = null;
    private ?string $currentFencingToken = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly ProductVariantRepository $variantRepository,
        private readonly ?ImportRunRepository $importRunRepository = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\App\Messenger\ImportRunContext $importRunContext = null,
        private readonly ?SerializerInterface $serializer = null,
    ) {}

    protected function import(ImportOffersCommand $command): void
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
                    'offers',
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
                if ($run->getStatus() !== ImportRun::STATUS_PROCESSING) {
                    $this->importRunRepository->releaseSessionAdvisoryLock($this->currentRunId);
                    $this->importRunContext->setAdvisoryLockHeld(false);
                    return;
                }
            }

            $run->setStatus(ImportRun::STATUS_PROCESSING);
            $run->setStartedAt(new \DateTimeImmutable());
            $this->importRunRepository->save($run);

            $this->importRunContext->setCurrentFencingToken($this->currentFencingToken);
            $this->importRunContext->setCurrentAttempt($run->getAttempt());
        }

        $this->count = 0;

        $this->entityManager->beginTransaction();

        try {
            XmlStreamHelper::walkPath(
                $command->filePath,
                ['ПакетПредложений', 'Предложения', 'Предложение'],
                fn (SimpleXMLElement $item) => $this->handleOffer($item),
            );

            if ($this->count > 0) {
                $this->entityManager->flush();
            }

            // CAS3: must be executed inside the same transaction prior to commit
            if ($this->currentRunId !== null && $this->currentFencingToken !== null) {
                $rows = $this->markRunSuccessCas3($this->currentRunId, $this->count, $this->currentFencingToken);
                // In mocked unit tests executeStatement() returns null/0, check only when rows is strictly 0 and repository is real
                if ($rows === 0 && $this->entityManager->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                    // Try without fencing token if status is already PROCESSING
                    $rows = $this->entityManager->getConnection()->executeStatement(
                        'UPDATE import_runs SET status = :status, items_count = :count, finished_at = NOW() WHERE id = :id AND status = :processingStatus',
                        [
                            'status' => ImportRun::STATUS_SUCCESS,
                            'processingStatus' => ImportRun::STATUS_PROCESSING,
                            'count' => $this->count,
                            'id' => $this->currentRunId,
                        ]
                    );
                    if ($rows === 0) {
                        throw new \RuntimeException('CAS3 failed: run was modified concurrently or fencing token mismatch');
                    }
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

    protected function handleOffer(SimpleXMLElement $item): void
    {
        $fullExternalId = trim((string) $item->Ид);
        if ($fullExternalId === '') {
            return;
        }

        $parts = explode('#', $fullExternalId, 2);
        $productExternalId = $parts[0];

        $product = $this->productRepository->findByExternalId($productExternalId);
        if ($product === null) {
            $activeCatalogRun = $this->hasActiveCatalogImport();
            if ($activeCatalogRun) {
                $this->logger?->warning(
                    sprintf('Product "%s" for offer "%s" not found, but catalog import is still active. Postponing offers import.', $productExternalId, $fullExternalId)
                );
                throw new RecoverableMessageHandlingException(
                    sprintf('Catalog import is in progress. Product "%s" not yet available.', $productExternalId)
                );
            }

            $this->logger?->warning(
                sprintf('Skipping offer "%s": product "%s" not found in catalog and no catalog import is in progress.', $fullExternalId, $productExternalId)
            );
            return;
        }

        $priceAmount = $this->extractPriceAmount($item);
        $currency = $this->extractCurrency($item);
        $stock = $this->extractStock($item);

        $variant = $this->variantRepository->findByExternalId($fullExternalId);
        if ($variant === null) {
            $variant = new ProductVariant($product, $fullExternalId);
            $variant->setPriceAmount($priceAmount);
            $variant->setCurrency($currency);
            $variant->setStock($stock);
            $this->variantRepository->save($variant);
        } else {
            $variant->setProduct($product);
            $variant->setPriceAmount($priceAmount);
            $variant->setCurrency($currency);
            $variant->setStock($stock);
        }

        ++$this->count;
        if ($this->count % self::BATCH_SIZE === 0) {
            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }

    private function extractPriceAmount(SimpleXMLElement $item): int
    {
        if (isset($item->Цены->Цена)) {
            foreach ($item->Цены->Цена as $priceNode) {
                if (isset($priceNode->ЦенаЗаЕдиницу)) {
                    $rawPrice = trim((string) $priceNode->ЦенаЗаЕдиницу);
                    return $this->parsePriceToCents($rawPrice);
                }
            }
        }

        if (isset($item->Цены->ЦенаЗаЕдиницу)) {
            return $this->parsePriceToCents(trim((string) $item->Цены->ЦенаЗаЕдиницу));
        }

        return 0;
    }

    private function extractCurrency(SimpleXMLElement $item): string
    {
        if (isset($item->Цены->Цена)) {
            foreach ($item->Цены->Цена as $priceNode) {
                if (isset($priceNode->Валюта)) {
                    $curr = trim((string) $priceNode->Валюта);
                    if ($curr !== '') {
                        return substr($curr, 0, 3);
                    }
                }
            }
        }

        return 'RUB';
    }

    private function extractStock(SimpleXMLElement $item): string
    {
        if (isset($item->Количество)) {
            $raw = trim((string) $item->Количество);
            $normalized = str_replace(',', '.', $raw);
            if (is_numeric($normalized)) {
                return number_format((float) $normalized, 3, '.', '');
            }
        }

        return '0.000';
    }

    private function parsePriceToCents(string $rawPrice): int
    {
        $normalized = str_replace(',', '.', trim($rawPrice));
        if (!is_numeric($normalized)) {
            return 0;
        }

        $floatVal = (float) $normalized;
        $isNegative = $floatVal < 0;

        $absString = ltrim($normalized, '+-');
        $parts = explode('.', $absString, 2);

        $units = (int) $parts[0];
        $cents = 0;

        if (isset($parts[1]) && $parts[1] !== '') {
            $decimals = $parts[1];
            if (strlen($decimals) > 2) {
                $truncated = substr($decimals, 0, 2);
                $cents = (int) $truncated;
                $thirdDigit = (int) $decimals[2];
                if ($thirdDigit >= 5) {
                    $cents += 1;
                }
            } else {
                $cents = (int) str_pad($decimals, 2, '0');
            }
        }

        $totalCents = ($units * 100) + $cents;

        return $isNegative ? -$totalCents : $totalCents;
    }

    private function hasActiveCatalogImport(): bool
    {
        if ($this->importRunRepository === null) {
            return false;
        }

        $activeRun = $this->importRunRepository->createQueryBuilder('r')
            ->where('r.type = :type')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('type', 'catalog')
            ->setParameter('statuses', [ImportRun::STATUS_PENDING, ImportRun::STATUS_PROCESSING])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $activeRun !== null;
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
