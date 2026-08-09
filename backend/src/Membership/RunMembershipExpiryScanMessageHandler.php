<?php

namespace App\Membership;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RunMembershipExpiryScanMessageHandler
{
    public function __construct(private readonly MembershipExpiryScanner $scanner)
    {
    }

    public function __invoke(RunMembershipExpiryScanMessage $message): void
    {
        $this->scanner->scan();
    }
}
