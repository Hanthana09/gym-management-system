<?php

namespace App\Command;

use App\Billing\InvoiceGenerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * gym-management-billing-v1.md §5.1. Manual/ops entry point mirroring
 * BackfillDailyMetricsCommand's pattern — the actual daily trigger is
 * BillingScheduleProvider's Symfony Scheduler job; this delegates to the
 * same InvoiceGenerationService, so running it by hand (or twice in one
 * day) is exactly as idempotent as the scheduled run.
 */
#[AsCommand(name: 'app:billing:generate-invoices', description: 'Generate recurring invoices for all subscriptions due for billing today')]
class GenerateInvoicesCommand extends Command
{
    public function __construct(private readonly InvoiceGenerationService $generator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->generator->generateForAllDueSubscriptions();

        $io->success(sprintf(
            'Generated %d invoice(s); %d subscription(s) already up to date for today.',
            $result['processed'],
            $result['skippedAlreadyGenerated'],
        ));

        return Command::SUCCESS;
    }
}
