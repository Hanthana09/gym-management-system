<?php

namespace App\Entity;

use App\Repository\MemberProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fields match architecture doc §5.1's MEMBER_PROFILE entity. All fields
 * besides the user link are nullable — none are collected at invitation
 * approval time (architecture doc §6.7); editing them is a later phase.
 */
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

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
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
