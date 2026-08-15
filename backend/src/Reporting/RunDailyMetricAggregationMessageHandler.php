<?php

namespace App\Reporting;

use App\Repository\BranchRepository;
use App\Repository\GymRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunDailyMetricAggregationMessageHandler
{
    public function __construct(
        private readonly DailyMetricAggregator $aggregator,
        private readonly GymRepository $gyms,
        private readonly BranchRepository $branches,
    ) {
    }

    public function __invoke(RunDailyMetricAggregationMessage $message): void
    {
        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null) {
            return;
        }

        $yesterday = (new \DateTimeImmutable('today'))->modify('-1 day');

        // roadmap Phase 16: the gym-wide rollup AND every one of the gym's branches — not one row per night.
        $this->aggregator->aggregate($gym, $yesterday);
        foreach ($this->branches->findByGym($gym) as $branch) {
            $this->aggregator->aggregate($gym, $yesterday, $branch);
        }
    }
}
