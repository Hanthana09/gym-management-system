<?php

namespace App\Command;

use App\Entity\MemberProfile;
use App\Member\MemberIdGenerator;
use App\Repository\GymRepository;
use App\Repository\InvitationRepository;
use App\Repository\MemberProfileRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * gym-management-member-profile-extension.md §3's "Backfill": run once
 * at deploy time (same shot as this phase's migration), safely
 * re-runnable — idempotent per row on "already has both gym and
 * memberId." Pattern mirrors ImportExercisesCommand (per-row skip
 * reasons, summary + table) and BackfillDailyMetricsCommand (simple
 * one-shot backfill delegating the real work to an injected service).
 *
 * Gym resolution for a pre-existing row is NOT GymRepository::
 * findTheOnlyGym() by default — InvitationRepository::findApprovedForUser()
 * is the historically accurate source (every approved member arrived via
 * an approved Invitation, which already recorded which gym), and matters
 * the moment more than one Gym row exists. findTheOnlyGym() is only the
 * fallback for rows with no Invitation at all (e.g. SeedDemoAccountsCommand-
 * created accounts that bypassed the invite flow).
 */
#[AsCommand(name: 'app:member:backfill-ids', description: 'Backfill memberId/gym on pre-existing MemberProfile rows (idempotent)')]
class BackfillMemberIdsCommand extends Command
{
    public function __construct(
        private readonly MemberProfileRepository $memberProfiles,
        private readonly InvitationRepository $invitations,
        private readonly GymRepository $gyms,
        private readonly MemberIdGenerator $memberIds,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $backfilled = 0;
        $skipped = 0;
        /** @var array<int, array{0: string, 1: string}> $unresolved memberId/name + reason */
        $unresolved = [];

        foreach ($this->memberProfiles->findAllWithUser() as $profile) {
            if ($profile->getGym() !== null && $profile->getMemberId() !== null) {
                ++$skipped;
                continue;
            }

            $gym = $profile->getGym()
                ?? $this->invitations->findApprovedForUser($profile->getUser())?->getGym()
                ?? $this->gyms->findTheOnlyGym();

            if ($gym === null) {
                $unresolved[] = [$profile->getUser()->getName(), 'no gym could be resolved (no approved invitation, no gym exists yet)'];
                continue;
            }

            $this->memberIds->generateFor($profile, $gym);
            ++$backfilled;
        }

        $io->success(sprintf('Backfilled %d, skipped %d (already done).', $backfilled, $skipped));
        if ($unresolved !== []) {
            $io->table(['Member', 'Reason unresolved'], $unresolved);
        }

        return Command::SUCCESS;
    }
}
