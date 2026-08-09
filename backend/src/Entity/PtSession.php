<?php

namespace App\Entity;

use App\Enum\PtSessionStatus;
use App\Repository\PtSessionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's PT_SESSION entity, plus a
 * `declined` status value — see PtSessionStatus for why.
 */
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

    #[ORM\ManyToOne(targetEntity: MemberProfile::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

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
        \DateTimeImmutable $scheduledAt,
        int $durationMinutes,
    ) {
        $this->id = Uuid::v7();
        $this->coach = $coach;
        $this->member = $member;
        $this->scheduledAt = $scheduledAt;
        $this->durationMinutes = $durationMinutes;
        $this->status = PtSessionStatus::PENDING;
        $this->createdAt = new \DateTimeImmutable();
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
