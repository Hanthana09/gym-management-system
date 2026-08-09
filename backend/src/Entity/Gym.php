<?php

namespace App\Entity;

use App\Repository\GymRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's GYM entity exactly. Not named in
 * Phase 3's own scope, but required infrastructure: INVITATION.gym_id and
 * InvitationVoter::SEND (§9.1) both depend on it. This is a single-gym
 * product (CLAUDE.md), so in practice there's exactly one row — lazily
 * created for an Owner the first time they send an invitation rather than
 * needing a separate "gym setup" screen not yet in the roadmap.
 */
#[ORM\Entity(repositoryClass: GymRepository::class)]
class Gym
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $address;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', nullable: false)]
    private User $owner;

    public function __construct(string $name, string $address, User $owner)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->address = $address;
        $this->owner = $owner;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }
}
