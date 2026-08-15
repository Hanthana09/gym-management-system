<?php

namespace App\Notification;

use App\Entity\Announcement;
use App\Entity\Branch;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\Audience;
use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Repository\CoachProfileRepository;
use App\Repository\InvitationRepository;
use App\Repository\MemberProfileRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * functional requirements §6.2/§6.3: Owner broadcasts gym-wide, Coach
 * broadcasts to own clients only. The Announcement row is the audit
 * record of the broadcast itself; each recipient additionally gets their
 * own Notification row via NotificationService (architecture doc §6.6's
 * "fans out to in-app").
 */
class AnnouncementService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationService $notifications,
        private readonly InvitationRepository $invitations,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly CoachProfileRepository $coachProfiles,
    ) {
    }

    /**
     * $announcement is already persisted-candidate-checked against
     * AnnouncementVoter by the controller before this is called (same
     * "build candidate, check Voter, then call the service" shape as
     * InvitationController/PtSessionController).
     *
     * @return array{announcement: Announcement, recipientCount: int}
     */
    public function publish(Announcement $announcement): array
    {
        $this->em->persist($announcement);
        $this->em->flush();

        $recipients = $announcement->getAudience() === Audience::GYM_WIDE
            ? $this->gymWideRecipients($announcement->getGym(), $announcement->getCreatedBy(), $announcement->getBranch())
            : $this->ownClientRecipients($announcement->getCreatedBy());

        $sourceRole = $announcement->getCreatedBy()->getRole();
        foreach ($recipients as $recipient) {
            $this->notifications->notify($recipient, NotificationType::ANNOUNCEMENT, 'Announcement', $announcement->getBody(), $sourceRole);
        }

        return ['announcement' => $announcement, 'recipientCount' => count($recipients)];
    }

    /**
     * functional requirements §6.2: "every active Member and Coach at my
     * gym; people at other gyms never see it" — approved Invitation.gym is
     * the only place a Coach/Member is linked to a specific gym (User has
     * no direct gym_id), so that's what scopes this, not a blanket
     * "every active Coach/Member in the system" query. Also excludes the
     * Owner themselves (harmless — an Owner has no invitation to their
     * own gym anyway) and anyone since suspended.
     *
     * roadmap Phase 16: when $branch is given (the Owner targeted one
     * branch, not gym-wide), this narrows further to Members enrolled at
     * that branch and Coach/Staff assigned to it — otherwise a
     * "branch-targeted" announcement would still reach the whole gym,
     * defeating the point of picking a branch at all. This isn't in the
     * retrofit checklist's literal text (which only names the Voter), but
     * follows directly from what "target one branch" has to mean.
     *
     * @return User[]
     */
    private function gymWideRecipients(Gym $gym, User $owner, ?Branch $branch): array
    {
        $approved = array_values(array_filter(
            $this->invitations->findApprovedUsersForGym($gym),
            fn (User $user) => $user !== $owner && $user->getStatus() === UserStatus::ACTIVE,
        ));

        if ($branch === null) {
            return $approved;
        }

        return array_values(array_filter($approved, function (User $user) use ($branch) {
            if ($user->getRole() === UserRole::MEMBER) {
                $member = $this->memberProfiles->findOneByUser($user);
                $enrollingBranch = $member?->getActiveMembership()?->getPlan()?->getBranch();

                return $enrollingBranch === $branch;
            }

            // Coach/Staff: reached only if assigned to this branch.
            return $user->getBranchAssignments()->exists(fn ($k, $a) => $a->getBranch() === $branch);
        }));
    }

    /** @return User[] */
    private function ownClientRecipients(User $coachUser): array
    {
        $coach = $this->coachProfiles->findOneByUser($coachUser);
        if ($coach === null) {
            return [];
        }

        return array_map(
            fn ($member) => $member->getUser(),
            $this->memberProfiles->findClientsOfCoach($coach),
        );
    }
}
