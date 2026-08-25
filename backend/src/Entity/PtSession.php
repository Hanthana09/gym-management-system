<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Enum\PtSessionStatus;
use App\Repository\PtSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's PT_SESSION entity, plus a
 * `declined` status value — see PtSessionStatus for why. `branch`
 * (roadmap Phase 16) is where the session takes place; functional
 * requirements §14.3: a Member can pick any branch where at least one
 * Coach is assigned, not just their own enrolling branch — this column
 * is genuinely member-selected, not derived.
 *
 * #[ApiResource(operations: []) — every §7 endpoint for this entity
 * hits a different blocker, checked individually rather than assumed:
 *   - `POST /pt-sessions`: PtSessionController::create() validates the
 *     chosen coach is actually assigned to the chosen branch before
 *     accepting the request (its own docblock explains why: an
 *     unvalidated combination would create a permanently-unrespondable
 *     session). A bare Post bypasses that entirely — same class of
 *     regression as AttendanceLog's check-in, not declared for the same
 *     reason.
 *   - `PATCH /pt-sessions/:id/status`: the status enum only has domain
 *     methods (confirm()/decline()/cancel()), no setStatus() — there is
 *     no writable field for a bare Patch to denormalize onto.
 *   - `GET /coaches/:id/schedule`: PtSessionController's own docblock
 *     says outright there's "no dedicated 'coach schedule' Voter
 *     attribute" — the real check is an identity comparison the
 *     controller does directly (Owner, or this Coach viewing their own
 *     id), not a single subject-based `is_granted()` expression this
 *     pass can declare cleanly.
 * `coach`/`member` also couldn't be exposed as linked resources even if
 * an operation existed: both are `operations: []` (CoachProfile/
 * MemberProfile's confirmed IRI-generation failure).
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: PtSessionRepository::class)]
class PtSession
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: CoachProfile::class)]
    #[ORM\JoinColumn(name: 'coach_id', referencedColumnName: 'user_id', nullable: false)]
    private CoachProfile $coach;

    #[ORM\ManyToOne(targetEntity: MemberProfile::class, inversedBy: 'ptSessions')]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', nullable: false)]
    private Branch $branch;

    #[ORM\Column]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column]
    private int $durationMinutes;

    #[ORM\Column(length: 20, enumType: PtSessionStatus::class)]
    private PtSessionStatus $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        CoachProfile $coach,
        MemberProfile $member,
        Branch $branch,
        \DateTimeImmutable $scheduledAt,
        int $durationMinutes,
    ) {
        $this->id = Uuid::v7();
        $this->coach = $coach;
        $this->member = $member;
        $this->branch = $branch;
        $this->scheduledAt = $scheduledAt;
        $this->durationMinutes = $durationMinutes;
        $this->status = PtSessionStatus::PENDING;
        $this->createdAt = new \DateTimeImmutable();
        $member->addPtSession($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCoach(): CoachProfile
    {
        return $this->coach;
    }

    public function getMember(): MemberProfile
    {
        return $this->member;
    }

    public function getBranch(): Branch
    {
        return $this->branch;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function getStatus(): PtSessionStatus
    {
        return $this->status;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPending(): bool
    {
        return $this->status === PtSessionStatus::PENDING;
    }

    public function confirm(): void
    {
        $this->status = PtSessionStatus::CONFIRMED;
    }

    public function decline(): void
    {
        $this->status = PtSessionStatus::DECLINED;
    }

    /** functional requirements §5.1: Member cancelling their own still-pending request. */
    public function cancel(): void
    {
        $this->status = PtSessionStatus::CANCELLED;
    }

    /**
     * functional requirements §5.3: notes are Coach-only by default — this
     * setter has no corresponding Member-facing read path (see
     * PtSessionController's serialization, which omits notes for Members).
     * The open Coach-visibility question flagged in architecture doc §9 is
     * unchanged by this phase.
     */
    public function setNotes(?string $notes): void
    {
        $this->notes = $notes;
    }
}
