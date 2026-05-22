<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\HealthInterface;
use Magento\Framework\App\ProductMetadataInterface;

class Health implements HealthInterface
{
    private const MODULE_VERSION = '1.0.0';

    public function __construct(
        private readonly ProductMetadataInterface $productMetadata
    ) {}

    public function check(): array
    {
        return [
            'status'          => 'ok',
            'version'         => self::MODULE_VERSION,
            'magento_version' => $this->productMetadata->getVersion(),
        ];
    }
}
