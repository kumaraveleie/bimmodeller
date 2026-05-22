<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\DownloadInterface;

class Download implements DownloadInterface
{
    public function download(int $id): array
    {
        // TODO Week 5: implement with QuotaEnforcer + signed CDN URL minting
        return [];
    }
}
