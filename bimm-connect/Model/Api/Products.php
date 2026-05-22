<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\ProductsInterface;

class Products implements ProductsInterface
{
    public function list(
        ?string $category = null,
        ?string $manufacturer = null,
        ?string $compliance = null,
        int $page = 1,
        int $perPage = 20,
        string $sort = 'name'
    ): array {
        // TODO Week 3: implement with ProductRepositoryInterface + custom filters
        return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
    }

    public function get(int $id): array
    {
        // TODO Week 3: implement with ProductRepositoryInterface
        return [];
    }
}
