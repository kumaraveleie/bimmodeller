<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\CategoriesInterface;

class Categories implements CategoriesInterface
{
    public function list(): array
    {
        // TODO Week 4: implement with CategoryRepositoryInterface
        return ['categories' => []];
    }
}
