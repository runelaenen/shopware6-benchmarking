<?php

namespace Tideways\Shopware6Benchmarking\Reporting;

class LocustStats
{
    /**
     * @param array<string, array<string, HdrHistogram>> $pageByTime
     * @param array<string, HdrHistogram> $pageSummary
     */
    public function __construct(
        public ?\DateTimeImmutable $startDate = null,
        public ?\DateTimeImmutable $endDate = null,
        public array               $pageByTime = [],
        public array               $pageSummary = [],
    ) {}

    public function getTotalRequests() : int
    {
        return $this->pageSummary['overall']->getRequestCount();
    }
}