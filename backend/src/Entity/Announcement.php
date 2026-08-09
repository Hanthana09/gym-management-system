<?php

namespace App\Entity;

use App\Enum\Audience;
use App\Repository\AnnouncementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's ANNOUNCEMENT entity, plus
 * `audience` and `createdBy` — see Audience enum for why `audience` is
 * required by §9.1's AnnouncementVoter. `createdBy` is needed alongside
 * it: when `audience` is OWN_CLIENTS, resolving "which Coach's clients"
 * requires knowing who authored the announcement, not just which gym.
 */
#[ORM\Entity(repositoryClass: AnnouncementRepository::class)]
class Announcement
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: false)]
    private User $createdBy;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(length: 20, enumType: Audience::class)]
    private Audience $audience;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Gym $gym, User $createdBy, string $body, Audience $audience)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->createdBy = $createdBy;
        $this->body = $body;
        $this->audience = $audience;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getAudience(): Audience
    {
        return $this->audience;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
