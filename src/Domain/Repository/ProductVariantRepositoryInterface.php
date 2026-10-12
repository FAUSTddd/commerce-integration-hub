<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\ProductVariant;

interface ProductVariantRepositoryInterface
{
    public function findByExternalId(string $externalId): ?ProductVariant;

    public function save(ProductVariant $variant): void;
}
