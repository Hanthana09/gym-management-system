<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use App\Repository\CoachProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fields match architecture doc §5.1's COACH_PROFILE entity. All fields
 * besides the user link are nullable — none are collected at invitation
 * approval time (architecture doc §6.7); editing them is a later phase.
 *
 * #[ApiResource(operations: []) — same shared-PK-via-relation pattern as
 * MemberProfile (`#[ORM\Id]` is the `user` OneToOne, not a plain scalar
 * column), which is empirically confirmed (see MemberProfile's own
 * docblock) to break IRI generation across every format this API serves.
 * §7 also has no CoachProfile-shaped endpoint of its own anyway (coaches
 * appear only via `GET /coaches/:id/schedule`, which returns PtSession
 * data, not this entity's fields).
 *
 * roadmap Phase 17: `getHourlyRate()`/`setHourlyRate()` are added here —
 * this field existed since Phase 2 but had no accessor at all (nothing
 * in this codebase ever read or wrote it before now). Architecture doc
 * §6.13's note explains why: it's "the minimal use of an existing,
 * otherwise-unused field" for FinancialSummaryController's read-time PT
 * revenue estimate.
 *
 * gym-management-coach-management.md: the "future coach-profile-
 * management phase" the note above anticipated. `specialty`/`bio` (both
 * present since Phase 2, both previously accessor-less) are now wired up
 * alongside `hourlyRate`, all three editable via PATCH /coaches/:id
 * (CoachController → CoachService). No schema change — every column
 * already existed.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
#[ORM\Entity(repositoryClass: CoachProfileRepository::class)]
class CoachProfile
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false)]
    private User $user;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $specialty = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)]
    private ?string $hourlyRate = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSpecialty(): ?string
    {
        return $this->specialty;
    }

    public function setSpecialty(?string $specialty): void
    {
        $this->specialty = $specialty;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): void
    {
        $this->bio = $bio;
    }

    public function getHourlyRate(): ?string
    {
        return $this->hourlyRate;
    }

    public function setHourlyRate(?string $hourlyRate): void
    {
        $this->hourlyRate = $hourlyRate;
    }
}
