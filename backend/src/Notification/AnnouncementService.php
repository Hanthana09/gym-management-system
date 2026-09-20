<?php

namespace App\Notification;

use App\Entity\Announcement;
use App\Entity\Branch;
use App\Entity\User;
use App\Enum\Audience;
use App\Enum\NotificationType;
use App\Enum\UserRole;
use App\Repository\CoachProfileRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\UserRepository;
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
        private readonly MemberProfileRepository $memberProfiles,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly UserRepository $users,
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
            ? $this->gymWideRecipients($announcement->getCreatedBy(), $announcement->getBranch())
            : $this->ownClientRecipients($announcement->getCreatedBy());

        $sourceRole = $announcement->getCreatedBy()->getRole();
        foreach ($recipients as $recipient) {
            $this->notifications->notify($recipient, NotificationType::ANNOUNCEMENT, 'Announcement', $announcement->getBody(), $sourceRole);
        }

        return ['announcement' => $announcement, 'recipientCount' => count($recipients)];
    }

    /**
     * functional requirements §6.2: "every active Member and Coach at my
     * gym; people at other gyms never see it." Originally sourced from
     * approved Invitation.gym — correct in the invite-only world this was
     * written for (Phase 7), since that was the only place a Coach/Member
     * was ever linked to a gym. It went stale once
     * gym-management-member-profile-extension.md (walk-in `POST /members`)
     * and gym-management-coach-management.md (`POST /coaches`) shipped:
     * those deliberately create accounts with **no** Invitation row at
     * all (CLAUDE.md's documented override of the invite-only rule), so
     * an Invitation-only lookup silently excluded every walk-in Member
     * and directly-created Coach from gym-wide announcements — the
     * Announcement row still got created (audit-visible, 201 to the
     * Owner), it just fanned out to almost nobody. Fixed by sourcing
     * candidates the same way the Owner's own roster does
     * (MemberController::list()'s findAllWithUser() calls, and
     * UserRepository::findActiveByRoles() for Staff, who have no profile
     * entity) — single-gym product, so "every active Member/Coach/Staff"
     * already is "at my gym," the same assumption findAllWithUser() and
     * findTheOnlyGym() make everywhere else. Also excludes the Owner
     * themselves and anyone not ACTIVE.
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
    private function gymWideRecipients(User $owner, ?Branch $branch): array
    {
        $candidates = $this->users->findActiveByRoles([UserRole::MEMBER, UserRole::COACH, UserRole::STAFF]);

        $active = array_values(array_filter($candidates, fn (User $user) => $user !== $owner));

        if ($branch === null) {
            return $active;
        }

        return array_values(array_filter($active, function (User $user) use ($branch) {
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
