<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Invoice;
use App\Entity\User;
use App\Repository\InvoiceRepository;
use App\Repository\MemberProfileRepository;
use Symfony\Bundle\SecurityBundle\Security;

/** Resolves §7's `GET /members/me/invoices` — always the calling Member's own, no `{id}` in the path. */
final class CurrentMemberInvoicesProvider implements ProviderInterface
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly InvoiceRepository $invoices,
        private readonly Security $security,
    ) {
    }

    /** @return Invoice[] */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return [];
        }

        $member = $this->memberProfiles->findOneByUser($user);

        return $member !== null ? $this->invoices->findAllForMember($member) : [];
    }
}
