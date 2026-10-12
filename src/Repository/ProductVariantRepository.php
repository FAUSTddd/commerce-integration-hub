<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVariant>
 */
class ProductVariantRepository extends ServiceEntityRepository
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
