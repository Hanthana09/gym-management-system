<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\Gender;
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
 *
 * gym-management-member-profile-extension.md: adds `gender`/`address*`/
 * `gym`/`memberId` and finally wires up getters/setters for the
 * pre-existing (but previously unused — no accessor at all) `dateOfBirth`
 * column. That spec doc calls the field `dob`; this codebase already had
 * the column as `dateOfBirth` from an earlier phase, so the existing
 * name is kept rather than renaming a column with no functional reason
 * to (CLAUDE.md: don't touch existing columns without a migration
 * reason — a naming preference in a later spec isn't one).
 * `emergencyContact`/`healthNotes`/`goals` stay unwired — out of this
 * phase's scope, not part of this spec.
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

    #[ORM\Column(length: 20, enumType: Gender::class, nullable: true)]
    private ?Gender $gender = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $addressLine = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $addressCity = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $addressPostalCode = null;

    /**
     * gym-management-member-profile-extension.md §3/§6.1: nullable only
     * during the pre-existing-row backfill window (app:member:backfill-ids)
     * — every row created from here on (invite-acceptance or manual
     * walk-in) gets one immediately via MemberIdGenerator.
     *
     * Mutability is deliberately NOT enforced here (plain setter, no
     * throw-if-already-set guard). A follow-up feature made mutability
     * gym-policy-dependent — Gym::memberIdMode AUTO keeps it effectively
     * immutable (MemberController rejects `memberId` in any write
     * payload for those gyms), MANUAL allows Owner/Staff to correct it
     * anytime. Trying to express both rules on the entity itself would
     * need two setters with confusing semantics; the controller is
     * already the single place that decides "can this request touch
     * memberId at all," so it's the right enforcement point.
     */
    #[ORM\Column(length: 20, unique: true, nullable: true)]
    private ?string $memberId = null;

    /**
     * gym-management-member-profile-extension.md §6.1/§9.1: added
     * against architecture doc §5.2's original hub-scoped design (no
     * gym_id on this entity) specifically so memberId can be scoped
     * per-gym and MemberVoter's Owner branches can enforce a real
     * cross-gym boundary instead of the single-gym-product collapse
     * used everywhere else (GymRepository::findTheOnlyGym()). Nullable
     * only pre-backfill, same as memberId — MemberVoter treats a null
     * gym as "still visible to any Owner" so existing access doesn't
     * regress until the backfill command has run.
     */
    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: true)]
    private ?Gym $gym = null;

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

    /**
     * Inverse side of PtSession::$member, kept in sync from PtSession's
     * own constructor (`$member->addPtSession($this)`) rather than
     * Doctrine cascade — same pattern as $memberships above, so
     * hasCoach() below works against freshly-constructed entities in
     * unit tests with no EntityManager, exactly like the Voters that
     * call it expect (AttendanceVoterTest/MemberVoterTest).
     *
     * @var Collection<int, PtSession>
     */
    #[ORM\OneToMany(mappedBy: 'member', targetEntity: PtSession::class)]
    private Collection $ptSessions;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->memberships = new ArrayCollection();
        $this->ptSessions = new ArrayCollection();
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

    /** @internal called from PtSession's own constructor to keep this side in sync. */
    public function addPtSession(PtSession $session): void
    {
        if (!$this->ptSessions->contains($session)) {
            $this->ptSessions->add($session);
        }
    }

    /**
     * @internal for a throwaway PtSession built only to exercise a Voter
     * check (e.g. PtSessionController::create()'s permission-check
     * candidate) and then discarded, never persisted. Left registered on
     * a real, managed MemberProfile, it would still be sitting in this
     * collection at the next flush — an unpersisted "new" entity Doctrine
     * refuses to silently cascade-insert. The candidate's constructor
     * call to addPtSession() already happened; this undoes exactly that,
     * restoring the member to the state it was in before the candidate
     * existed.
     */
    public function removePtSession(PtSession $session): void
    {
        $this->ptSessions->removeElement($session);
    }

    /**
     * Needed by AttendanceVoter/MemberVoter (§9.1), whose VIEW branches
     * grant a Coach access to "own clients." Personal Training (Phase 6)
     * defines "own client" as: has this coach ever had a PT session with
     * this member, any status, ever — the same relationship
     * PtSessionRepository::countDistinctMembersForCoachAndBranch() already
     * uses for the Coach dashboard's "assigned members" count, just
     * evaluated for one specific coach/member pair instead of counted.
     */
    public function hasCoach(User $coach): bool
    {
        foreach ($this->ptSessions as $session) {
            if ($session->getCoach()->getUser() === $coach) {
                return true;
            }
        }

        return false;
    }

    public function getDateOfBirth(): ?\DateTimeImmutable
    {
        return $this->dateOfBirth;
    }

    public function setDateOfBirth(?\DateTimeImmutable $dateOfBirth): void
    {
        $this->dateOfBirth = $dateOfBirth;
    }

    /** gym-management-member-profile-extension.md §3: computed, not persisted — null when dateOfBirth is null. */
    public function getAge(): ?int
    {
        if ($this->dateOfBirth === null) {
            return null;
        }

        return $this->dateOfBirth->diff(new \DateTimeImmutable())->y;
    }

    public function getGender(): ?Gender
    {
        return $this->gender;
    }

    public function setGender(?Gender $gender): void
    {
        $this->gender = $gender;
    }

    public function getAddressLine(): ?string
    {
        return $this->addressLine;
    }

    public function setAddressLine(?string $addressLine): void
    {
        $this->addressLine = $addressLine;
    }

    public function getAddressCity(): ?string
    {
        return $this->addressCity;
    }

    public function setAddressCity(?string $addressCity): void
    {
        $this->addressCity = $addressCity;
    }

    public function getAddressPostalCode(): ?string
    {
        return $this->addressPostalCode;
    }

    public function setAddressPostalCode(?string $addressPostalCode): void
    {
        $this->addressPostalCode = $addressPostalCode;
    }

    public function getMemberId(): ?string
    {
        return $this->memberId;
    }

    public function setMemberId(?string $memberId): void
    {
        $this->memberId = $memberId;
    }

    public function getGym(): ?Gym
    {
        return $this->gym;
    }

    /** @internal only MemberIdGenerator/the backfill command may call this. */
    public function assignGym(Gym $gym): void
    {
        $this->gym = $gym;
    }
}
