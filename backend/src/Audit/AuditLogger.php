<?php

namespace App\Audit;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * architecture doc §9: "full audit log... for any Owner action that
 * touches another user's account... or financial record." The single
 * write path every such action goes through — see AuditLog's docblock
 * for why this table doesn't already exist from an earlier phase.
 */
class AuditLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param array<string, mixed> $metadata */
    public function log(User $actor, string $action, string $entityType, Uuid $entityId, array $metadata = []): AuditLog
    {
        $entry = new AuditLog($actor, $action, $entityType, $entityId, $metadata);
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
