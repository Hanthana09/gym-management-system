<?php

namespace App\Reporting;

use App\Repository\GymRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunDailyMetricAggregationMessageHandler
{
    public function __construct(
        private readonly DailyMetricAggregator $aggregator,
        private readonly GymRepository $gyms,
    ) {
    }

    public function __invoke(RunDailyMetricAggregationMessage $message): void
    {
        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null) {
            return;
        }

        $this->aggregator->aggregate($gym, (new \DateTimeImmutable('today'))->modify('-1 day'));
    }
}
