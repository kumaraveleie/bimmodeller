<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Api;

use BIMM\Connect\Api\VersionManifestInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class VersionManifest implements VersionManifestInterface
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function get(): array
    {
        return [
            'latest'        => $this->scopeConfig->getValue('bimm/plugin/latest_version') ?? '1.0.0',
            'url'           => $this->scopeConfig->getValue('bimm/plugin/download_url') ?? '',
            'sha256'        => $this->scopeConfig->getValue('bimm/plugin/sha256') ?? '',
            'released_at'   => $this->scopeConfig->getValue('bimm/plugin/released_at') ?? '',
            'changelog'     => $this->scopeConfig->getValue('bimm/plugin/changelog') ?? '',
            'min_supported' => $this->scopeConfig->getValue('bimm/plugin/min_supported') ?? '1.0.0',
        ];
    }
}
