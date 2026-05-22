<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\SearchInterface;

class Search implements SearchInterface
{
    public function search(string $q, int $page = 1, int $perPage = 20): array
    {
        // TODO Week 4: implement with Magento Elasticsearch query API
        return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
    }
}
