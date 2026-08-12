<?php

namespace App\Command;

use App\Reporting\DailyMetricAggregator;
use App\Repository\GymRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * roadmap Phase 11: "Backfill historical snapshots from existing data
 * when this ships" — a one-time operation (unlike the nightly job, which
 * only ever computes yesterday going forward), so it's a console command
 * rather than something wired into the regular schedule.
 */
#[AsCommand(name: 'analytics:backfill', description: 'Backfill DAILY_METRIC_SNAPSHOT rows from existing attendance/membership/invoice history')]
class BackfillDailyMetricsCommand extends Command
{
    public function __construct(
        private readonly DailyMetricAggregator $aggregator,
        private readonly GymRepository $gyms,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $gym = $this->gyms->findTheOnlyGym();
        if ($gym === null) {
            $io->warning('No gym exists yet — nothing to backfill.');

            return Command::SUCCESS;
        }

        $count = $this->aggregator->backfill($gym);
        $io->success(sprintf('Backfilled %d day%s of snapshots for %s.', $count, $count === 1 ? '' : 's', $gym->getName()));

        return Command::SUCCESS;
    }
}
