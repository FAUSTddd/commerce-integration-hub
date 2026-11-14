<?php

declare(strict_types=1);

namespace App\Admin\Dto;

use DateTimeImmutable;

final readonly class ProductItemView
{
    /**
     * @param ProductVariantView[] $variants
     */
    public function __construct(
        public int $id,
        public string $externalId,
        public ?string $sku,
        public string $name,
        public int $variantsCount,
        public DateTimeImmutable $updatedAt,
        public array $variants = [],
    ) {
    }
}
