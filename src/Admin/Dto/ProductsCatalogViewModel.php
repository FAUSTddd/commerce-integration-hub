<?php

declare(strict_types=1);

namespace App\Admin\Dto;

final readonly class ProductsCatalogViewModel
{
    /**
     * @param ProductItemView[] $products
     */
    public function __construct(
        public array $products,
        public int $currentPage,
        public int $totalPages,
        public int $totalItems,
        public int $limit,
        public ?string $search = null,
    ) {
    }
}
