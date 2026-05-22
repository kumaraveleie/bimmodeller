<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface VersionManifestInterface
{
    /**
     * @return mixed[]
     */
    public function get(): array;
}
