<?php

declare(strict_types=1);

namespace App\Admin\Repository;

use App\Admin\Database\ReadOnlyTransactionRunner;
use App\Admin\Dto\PaginatedProductIds;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminProductQueryService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ReadOnlyTransactionRunner $transactionRunner,
    ) {
    }

    /**
     * Phase 1: Retrieve paginated product IDs with optional search by SKU prefix or exact external_id.
     */
    public function findPaginatedProductIds(int $page = 1, int $limit = 25, ?string $search = null): PaginatedProductIds
    {
        $page = max(1, $page);
        $limit = max(1, min(50, $limit));

        return $this->transactionRunner->run(function () use ($page, $limit, $search): PaginatedProductIds {
            $qbCount = $this->entityManager->createQueryBuilder()
                ->select('COUNT(p.id)')
                ->from(Product::class, 'p');

            $qbIds = $this->entityManager->createQueryBuilder()
                ->select('p.id')
                ->from(Product::class, 'p')
                ->orderBy('p.id', 'DESC')
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit);

            $trimmedSearch = $search !== null ? trim($search) : '';
            if ($trimmedSearch !== '') {
                $qbCount->where('p.sku LIKE :searchPrefix OR p.externalId = :exactExternalId')
                    ->setParameter('searchPrefix', $trimmedSearch . '%')
                    ->setParameter('exactExternalId', $trimmedSearch);

                $qbIds->where('p.sku LIKE :searchPrefix OR p.externalId = :exactExternalId')
                    ->setParameter('searchPrefix', $trimmedSearch . '%')
                    ->setParameter('exactExternalId', $trimmedSearch);
            }

            $totalItems = (int) $qbCount->getQuery()->getSingleScalarResult();
            $totalPages = $totalItems > 0 ? (int) ceil($totalItems / $limit) : 1;

            if ($totalItems === 0 || ($page > $totalPages && $page > 1)) {
                return new PaginatedProductIds([], $totalItems, $page, $limit, $totalPages);
            }

            /** @var list<array{id: int|string}> $idRows */
            $idRows = $qbIds->getQuery()->getArrayResult();
            $ids = array_map(static fn(array $row): int => (int) $row['id'], $idRows);

            return new PaginatedProductIds($ids, $totalItems, $page, $limit, $totalPages);
        });
    }

    /**
     * Phase 2: Hydrate products and variants in a single query without N+1, preserving ID order.
     *
     * @param int[] $ids
     * @return Product[]
     */
    public function findProductsWithVariantsByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->transactionRunner->run(function () use ($ids): array {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('p', 'v')
                ->from(Product::class, 'p')
                ->leftJoin('p.variants', 'v')
                ->where('p.id IN (:ids)')
                ->setParameter('ids', $ids)
                ->orderBy('p.id', 'DESC');

            /** @var Product[] $products */
            $products = $qb->getQuery()->getResult();

            // Preserve exactly the requested IDs order
            $orderMap = array_flip($ids);
            usort($products, static function (Product $a, Product $b) use ($orderMap): int {
                $posA = $orderMap[$a->getId()] ?? PHP_INT_MAX;
                $posB = $orderMap[$b->getId()] ?? PHP_INT_MAX;
                return $posA <=> $posB;
            });

            return $products;
        });
    }
}
