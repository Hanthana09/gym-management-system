<?php

namespace App\Notification;

use App\Entity\Announcement;
use App\Entity\Gym;
use App\Entity\User;
use App\Enum\Audience;
use App\Enum\NotificationType;
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
            ? $this->gymWideRecipients($announcement->getGym(), $announcement->getCreatedBy())
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
     * @return User[]
     */
    private function gymWideRecipients(Gym $gym, User $owner): array
    {
        return array_values(array_filter(
            $this->invitations->findApprovedUsersForGym($gym),
            fn (User $user) => $user !== $owner && $user->getStatus() === UserStatus::ACTIVE,
        ));
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
