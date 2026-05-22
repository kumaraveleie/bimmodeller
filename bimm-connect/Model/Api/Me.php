<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\MeInterface;

class Me implements MeInterface
{
    public function get(): array
    {
        // TODO Week 8: resolve customer from Bearer token context
        return [];
    }
}
