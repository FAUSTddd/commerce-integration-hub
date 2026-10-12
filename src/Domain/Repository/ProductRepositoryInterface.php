<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Model\Product;

interface ProductRepositoryInterface
{
    public function findByExternalId(string $externalId): ?Product;

    public function save(Product $product): void;
}
