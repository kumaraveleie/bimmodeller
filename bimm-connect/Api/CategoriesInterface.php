<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface CategoriesInterface
{
    /**
     * @return mixed[]
     */
    public function list(): array;
}
