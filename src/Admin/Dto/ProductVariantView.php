<?php

declare(strict_types=1);

namespace App\Admin\Dto;

use DateTimeImmutable;

final readonly class ProductVariantView
{
    public function __construct(
        public int $id,
        public string $externalId,
        public int $priceAmount,
        public string $priceFormatted,
        public string $currency,
        public string $stockFormatted,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
