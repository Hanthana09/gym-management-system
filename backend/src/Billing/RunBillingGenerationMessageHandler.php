<?php

namespace App\Billing;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunBillingGenerationMessageHandler
{
    public function __construct(private readonly InvoiceGenerationService $generator)
    {
    }

    public function __invoke(RunBillingGenerationMessage $message): void
    {
        $this->generator->generateForAllDueSubscriptions();
    }
}
