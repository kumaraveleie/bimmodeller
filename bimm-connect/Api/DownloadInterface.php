<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface DownloadInterface
{
    /**
     * @param int $id
     * @return mixed[]
     */
    public function download(int $id): array;
}
