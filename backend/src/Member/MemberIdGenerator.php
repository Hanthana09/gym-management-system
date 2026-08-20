<?php

namespace App\Member;

use App\Entity\Gym;
use App\Entity\MemberProfile;
use App\Gym\GymCodeGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * gym-management-member-profile-extension.md §3: `{GymCode}-{0001}`,
 * race-safe under concurrent creation for the same gym.
 *
 * Race-safety choice: a per-gym counter row (`member_sequence`, plain
 * table, no Doctrine entity/repository — it's never read/written except
 * through the one atomic statement below) updated via a single
 * INSERT ... ON CONFLICT DO UPDATE ... RETURNING. Postgres serializes
 * concurrent upserts targeting the same row via its own row lock, so two
 * concurrent calls for the same gym are guaranteed distinct, sequential
 * numbers with no retry loop or app-level locking needed. Chosen over
 * "unique constraint + retry": retrying after a failed flush() means
 * recovering a corrupted EntityManager mid-request, which is more
 * fragile than avoiding the race in the first place.
 */
class MemberIdGenerator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly GymCodeGenerator $gymCodes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function generateFor(MemberProfile $profile, Gym $gym): string
    {
        $gymCode = $this->gymCodes->ensureCodeFor($gym);

        $nextNumber = (int) $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO member_sequence (gym_id, next_number)
                VALUES (:gymId, 2)
                ON CONFLICT (gym_id) DO UPDATE SET next_number = member_sequence.next_number + 1
                RETURNING next_number - 1
                SQL,
            ['gymId' => $gym->getId()->toRfc4122()],
        );

        $memberId = sprintf('%s-%04d', $gymCode, $nextNumber);

        if ($profile->getGym() === null) {
            $profile->assignGym($gym);
        }
        $profile->setMemberId($memberId);
        $this->em->flush();

        return $memberId;
    }
}
