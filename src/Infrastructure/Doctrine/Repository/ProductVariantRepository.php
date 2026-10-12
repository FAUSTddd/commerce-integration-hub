<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Repository;

use App\Domain\Model\ProductVariant;
use App\Domain\Repository\ProductVariantRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVariant>
 */
final class ProductVariantRepository extends ServiceEntityRepository implements ProductVariantRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    public function findByExternalId(string $externalId): ?ProductVariant
    {
        return $this->findOneBy(['externalId' => $externalId]);
    }

    public function save(ProductVariant $variant): void
    {
        $this->getEntityManager()->persist($variant);
    }
}
