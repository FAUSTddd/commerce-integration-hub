<?php

declare(strict_types=1);

namespace App\Admin\Dto;

final readonly class PaginatedProductIds
{
    /**
     * @param int[] $ids
     */
    public function __construct(
        public array $ids,
        public int $totalItems,
        public int $page,
        public int $limit,
        public int $totalPages,
    ) {
    }
}
