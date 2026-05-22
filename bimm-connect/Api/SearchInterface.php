<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface SearchInterface
{
    /**
     * @param string $q
     * @param int $page
     * @param int $perPage
     * @return mixed[]
     */
    public function search(string $q, int $page = 1, int $perPage = 20): array;
}
