<?php

declare(strict_types=1);

namespace App\Tests\Integration\Admin;

use App\Admin\Database\ReadOnlyTransactionRunner;
use App\Admin\Repository\AdminProductQueryService;
use App\Entity\Product;
use App\Entity\ProductVariant;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AdminProductQueryServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AdminProductQueryService $queryService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        $this->entityManager = $em;

        /** @var ReadOnlyTransactionRunner $transactionRunner */
        $transactionRunner = $container->get(ReadOnlyTransactionRunner::class);

        $this->queryService = new AdminProductQueryService($this->entityManager, $transactionRunner);
    }

    public function testEmptyIdsReturnsEmptyArrayWithoutQuery(): void
    {
        $products = $this->queryService->findProductsWithVariantsByIds([]);
        self::assertSame([], $products);
    }

    public function testPaginationAndTwoPhaseQueryExecution(): void
    {
        $conn = $this->entityManager->getConnection();

        // Prepare test products and variants in sandbox DB
        $conn->executeStatement("INSERT INTO products (id, external_id, name, sku, created_at, updated_at) VALUES
            (88801, 'ext-prod-88801', 'Product 88801', 'SKU-88801', NOW(), NOW()),
            (88802, 'ext-prod-88802', 'Product 88802', 'SKU-88802', NOW(), NOW()),
            (88803, 'ext-prod-88803', 'Product 88803', 'SKU-88803', NOW(), NOW())
            ON CONFLICT (id) DO NOTHING");

        $conn->executeStatement("INSERT INTO product_variants (id, product_id, external_id, price_amount, currency, stock, updated_at) VALUES
            (888011, 88801, 'ext-var-888011', 150000, 'RUB', 10.000, NOW()),
            (888012, 88801, 'ext-var-888012', 170000, 'RUB', 5.000, NOW()),
            (888021, 88802, 'ext-var-888021', 250000, 'RUB', 12.000, NOW())
            ON CONFLICT (id) DO NOTHING");

        try {
            // Phase 1: Pagination check
            $pagination = $this->queryService->findPaginatedProductIds(page: 1, limit: 2);

            self::assertGreaterThanOrEqual(3, $pagination->totalItems);
            self::assertCount(2, $pagination->ids);
            self::assertSame(1, $pagination->page);
            self::assertSame(2, $pagination->limit);

            // Phase 2: Single-query hydration and verification of variants
            $products = $this->queryService->findProductsWithVariantsByIds([88802, 88801]);

            self::assertCount(2, $products);
            self::assertSame(88802, $products[0]->getId());
            self::assertSame(88801, $products[1]->getId());

            // Check relations are loaded without trigger queries
            $prod88801 = $products[1];
            self::assertCount(2, $prod88801->getVariants());
            $variants = $prod88801->getVariants()->toArray();
            self::assertSame(150000, $variants[0]->getPriceAmount());
            self::assertSame('10.000', $variants[0]->getStock());
            self::assertSame(170000, $variants[1]->getPriceAmount());

            // Check search filtering
            $searchResult = $this->queryService->findPaginatedProductIds(page: 1, limit: 10, search: 'SKU-88802');
            self::assertContains(88802, $searchResult->ids);

            $exactExternalSearch = $this->queryService->findPaginatedProductIds(page: 1, limit: 10, search: 'ext-prod-88803');
            self::assertContains(88803, $exactExternalSearch->ids);
        } finally {
            $conn->executeStatement('DELETE FROM product_variants WHERE id IN (888011, 888012, 888021)');
            $conn->executeStatement('DELETE FROM products WHERE id IN (88801, 88802, 88803)');
        }
    }
}
