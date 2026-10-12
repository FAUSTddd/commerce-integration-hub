<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ImportRun;
use App\Entity\ProductVariant;
use App\Repository\ImportRunRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use FaustDDD\Symfony1cImport\Application\Command\ImportOffersCommand;
use FaustDDD\Symfony1cImport\Application\Service\OffersImporter;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler]
final class OffersImportHandler extends OffersImporter
{
    private const int BATCH_SIZE = 100;
    private int $count = 0;
    private ?int $currentRunId = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly ProductVariantRepository $variantRepository,
        private readonly ?ImportRunRepository $importRunRepository = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    protected function import(ImportOffersCommand $command): void
    {
        $filePath = $command->filePath;
        $filename = basename($filePath);
        $fileHash = is_file($filePath) ? (string) sha1_file($filePath) : 'unknown';

        $run = $this->importRunRepository?->findLatestPendingOrProcessing('offers', $filename);
        if ($run === null && $this->importRunRepository !== null) {
            $run = new ImportRun('offers', $filename, $fileHash);
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
            $this->logger?->warning(
                sprintf('Skipping offer "%s": product "%s" not found in catalog.', $fullExternalId, $productExternalId)
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
            $this->entityManager->commit();
            $this->entityManager->clear();

            $this->entityManager->beginTransaction();
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
