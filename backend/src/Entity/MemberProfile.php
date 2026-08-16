<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\MembershipStatus;
use App\Repository\MemberProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fields match architecture doc §5.1's MEMBER_PROFILE entity. All fields
 * besides the user link are nullable — none are collected at invitation
 * approval time (architecture doc §6.7); editing them is a later phase.
 *
 * #[ApiResource(operations: []) — empirically confirmed incompatible,
 * not just theoretically awkward. §7's `GET /members` was attempted here
 * (GetCollection + a matching Get, both secured with MemberVoter's real
 * attributes) but every request 400s with "Unable to generate an IRI for
 * the item of type MemberProfile" — tried against a real Owner token and
 * real data, both with and without an explicit uriVariables/Link
 * mapping. The cause: this entity's `#[ORM\Id]` is the `user` relation
 * itself (a shared-PK pattern), and every format this API is configured
 * to serve (jsonld/jsonapi/hal — config/packages/api_platform.yaml has
 * no plain `json`) needs to generate a self-link per item, which this
 * identifier shape defeats. CoachProfile has the identical shared-PK
 * pattern and hits the same wall — see its own docblock.
 * §7's `PATCH /members/:id/status` has a second, independent problem
 * even setting the IRI issue aside: MemberVoter::MANAGE's subject is
 * MemberProfile, but the field it authorizes changing (`status`) lives
 * on the related User, not here — nothing on this entity to denormalize
 * onto without a custom processor (see MemberService::updateStatus()
 * for the real implementation this pass doesn't reimplement).
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: MemberProfileRepository::class)]
class MemberProfile
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dateOfBirth = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $emergencyContact = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $healthNotes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $goals = null;

    /**
     * roadmap Phase 16 / architecture doc §9.1's MemberVoter: the Staff
     * VIEW branch needs "which branch did this member enroll at," derived
     * from `getActiveMembership()?->getPlan()?->getBranch()`. Kept in sync
     * by Membership's own constructor (same in-memory pattern as
     * User::branchAssignments), not Doctrine cascade, so this works
     * against freshly-constructed entities in unit tests with no
     * EntityManager, exactly like the doc's Voter body expects.
     *
     * @var Collection<int, Membership>
     */
    #[ORM\OneToMany(mappedBy: 'member', targetEntity: Membership::class)]
    private Collection $memberships;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->memberships = new ArrayCollection();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** @internal called from Membership's own constructor to keep this side in sync. */
    public function addMembership(Membership $membership): void
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
        }
    }

    /**
     * architecture doc §9.1's MemberVoter Staff branch: the enrolling
     * branch comes from the member's currently active (or paused, still
     * "in force") membership — not necessarily the most recent one ever
     * created, matching MembershipRepository::findOneOngoingForMember()'s
     * ACTIVE|PAUSED definition of "ongoing" elsewhere in this codebase.
     */
    public function getActiveMembership(): ?Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getStatus() === MembershipStatus::ACTIVE || $membership->getStatus() === MembershipStatus::PAUSED) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * Needed by AttendanceVoter (§9.1, copied verbatim in Phase 5), whose
     * VIEW branch grants a Coach access to "own clients." Personal
     * Training (Phase 6) hasn't defined the actual coach-client
     * relationship yet, so this is a placeholder that always denies —
     * correct behavior for now, since no member has an assigned coach
     * yet — until Phase 6 gives it a real implementation.
     */
    public function hasCoach(User $coach): bool
    {
        return false;
    }
}
