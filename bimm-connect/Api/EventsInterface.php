<?php

declare(strict_types=1);

namespace BIMM\Connect\Api;

interface EventsInterface
{
    /**
     * @param mixed[] $events
     * @return void
     */
    public function ingest(array $events): void;
}
