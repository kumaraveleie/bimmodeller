<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface ProductsInterface
{
    /**
     * @param string|null $category
     * @param string|null $manufacturer
     * @param string|null $compliance
     * @param int $page
     * @param int $perPage
     * @param string $sort
     * @return mixed[]
     */
    public function list(
        ?string $category = null,
        ?string $manufacturer = null,
        ?string $compliance = null,
        int $page = 1,
        int $perPage = 20,
        string $sort = 'name'
    ): array;

    /**
     * @param int $id
     * @return mixed[]
     */
    public function get(int $id): array;
}
