<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface HealthInterface
{
    /**
     * @return mixed[]
     */
    public function check(): array;
}
