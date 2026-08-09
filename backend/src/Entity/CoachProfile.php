<?php

namespace App\Entity;

use App\Repository\CoachProfileRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fields match architecture doc §5.1's COACH_PROFILE entity. All fields
 * besides the user link are nullable — none are collected at invitation
 * approval time (architecture doc §6.7); editing them is a later phase.
 */
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
}
