<?php

namespace App\Invitation;

use App\Entity\CoachProfile;
use App\Entity\Invitation;
use App\Entity\MemberProfile;
use App\Entity\User;
use App\Enum\InvitationRole;
use App\Enum\UserStatus;
use App\Event\InvitationApprovedEvent;
use App\Event\InvitationDeclinedEvent;
use App\Event\InvitationSentEvent;
use App\Gym\GymProvisioningService;
use App\Repository\CoachProfileRepository;
use App\Repository\InvitationRepository;
use App\Repository\MemberProfileRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * architecture doc §6.7: an Owner never creates an active Coach/Member
 * directly — every account starts here, as a pending invitation the
 * invitee must explicitly approve.
 */
class InvitationService
{
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly InvitationRepository $invitations,
        private readonly GymProvisioningService $gymProvisioning,
        private readonly UserRepository $users,
        private readonly CoachProfileRepository $coachProfiles,
        private readonly MemberProfileRepository $memberProfiles,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @return array{invitation: Invitation, created: bool}
     */
    public function sendInvitation(User $owner, string $destination, InvitationRole $role): array
    {
        $gym = $this->gymProvisioning->ensureGymForOwner($owner);

        $existing = $this->invitations->findPendingForDestination($gym, $destination);
        if ($existing !== null) {
            if ($existing->markExpiredIfNeeded()) {
                $this->em->flush(); // persist the lazy expiry flip, then fall through to create a new one
            } else {
                // functional requirements §2.1: re-inviting a pending destination
                // returns the existing invitation rather than creating a duplicate.
                return ['invitation' => $existing, 'created' => false];
            }
        }

        $isEmail = filter_var($destination, FILTER_VALIDATE_EMAIL) !== false;
        $email = $isEmail ? $destination : null;
        $phone = $isEmail ? null : $destination;

        $existingUser = $email !== null
            ? $this->users->findOneByEmail($email)
            : $this->users->findOneByPhone($phone);

        $invitation = new Invitation(
            $gym,
            $owner,
            $existingUser,
            $email,
            $phone,
            $role,
            new \DateTimeImmutable('+' . self::EXPIRY_DAYS . ' days'),
        );
        $this->em->persist($invitation);
        $this->em->flush();

        $this->dispatcher->dispatch(new InvitationSentEvent($invitation), InvitationSentEvent::NAME);

        return ['invitation' => $invitation, 'created' => true];
    }

    /** @return Invitation[] */
    public function listForUser(User $user): array
    {
        $invitations = $this->invitations->findForUser($user);
        $anyExpired = false;
        foreach ($invitations as $invitation) {
            $anyExpired = $invitation->markExpiredIfNeeded() || $anyExpired;
        }
        if ($anyExpired) {
            $this->em->flush();
        }

        return $invitations;
    }

    public function approve(Invitation $invitation): void
    {
        $this->assertRespondable($invitation);

        $user = $invitation->getUser();
        if ($user === null) {
            throw new InvitationNotRespondableException('no_linked_user');
        }

        $invitation->approve();
        $user->setStatus(UserStatus::ACTIVE);
        $this->createOrReactivateProfile($user, $invitation->getRole());
        $this->em->flush();

        $this->dispatcher->dispatch(new InvitationApprovedEvent($invitation), InvitationApprovedEvent::NAME);
    }

    public function decline(Invitation $invitation): void
    {
        $this->assertRespondable($invitation);

        $invitation->decline();
        $this->em->flush();

        $this->dispatcher->dispatch(new InvitationDeclinedEvent($invitation), InvitationDeclinedEvent::NAME);
    }

    /**
     * architecture doc §6.7: "the invitee registers ... via their first OTP
     * login" — called from OtpService when a code is verified for a
     * destination with no existing account yet.
     */
    public function provisionUserForDestination(string $destination): ?User
    {
        $invitation = $this->invitations->findAnyPendingForDestination($destination);
        if ($invitation === null || $invitation->markExpiredIfNeeded()) {
            if ($invitation !== null) {
                $this->em->flush();
            }

            return null;
        }

        $isEmail = filter_var($destination, FILTER_VALIDATE_EMAIL) !== false;
        $user = new User(
            $destination,
            $isEmail ? $destination : null,
            $isEmail ? null : $destination,
            $invitation->getRole()->toUserRole(),
            UserStatus::PENDING_APPROVAL,
        );

        $this->em->persist($user);
        $invitation->setUser($user);
        $this->em->flush();

        return $user;
    }

    private function assertRespondable(Invitation $invitation): void
    {
        if ($invitation->markExpiredIfNeeded()) {
            $this->em->flush();

            throw new InvitationNotRespondableException('expired');
        }

        if (!$invitation->isPending()) {
            throw new InvitationNotRespondableException('already_responded');
        }
    }

    private function createOrReactivateProfile(User $user, InvitationRole $role): void
    {
        if ($role === InvitationRole::COACH && $this->coachProfiles->findOneByUser($user) === null) {
            $this->em->persist(new CoachProfile($user));
        }

        if ($role === InvitationRole::MEMBER && $this->memberProfiles->findOneByUser($user) === null) {
            $this->em->persist(new MemberProfile($user));
        }
    }
}
