<?php

declare(strict_types=1);

namespace BIMM\Connect\Model\Subscription;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

class MonthlyResetJob
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly LoggerInterface $logger
    ) {}

    public function execute(): void
    {
        // TODO Week 9: resolve bimm_quota_used_this_month attribute_id dynamically
        $connection = $this->resource->getConnection();
        $this->logger->info('BIMM MonthlyResetJob: quota reset skipped — attribute_id not configured yet');
    }
}
