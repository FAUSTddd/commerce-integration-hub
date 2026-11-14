<?php

declare(strict_types=1);

namespace App\Admin\Dto;

/**
 * @template T
 */
final readonly class PaginatedResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $limit,
        public int $totalItems,
        public int $totalPages,
    ) {
    }
}
