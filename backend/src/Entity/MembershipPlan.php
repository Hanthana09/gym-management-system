<?php

namespace App\Entity;

use App\Repository\MembershipPlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's MEMBERSHIP_PLAN entity exactly.
 */
#[ORM\Entity(repositoryClass: MembershipPlanRepository::class)]
class MembershipPlan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 8, scale: 2)]
    private string $price;

    #[ORM\Column]
    private int $durationDays;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $features;

    /**
     * @param string[] $features
     */
    public function __construct(Gym $gym, string $name, string $price, int $durationDays, array $features)
    {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->name = $name;
        $this->price = $price;
        $this->durationDays = $durationDays;
        $this->features = $features;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
    }

    public function getDurationDays(): int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): void
    {
        $this->durationDays = $durationDays;
    }

    /** @return string[] */
    public function getFeatures(): array
    {
        return $this->features;
    }

    /** @param string[] $features */
    public function setFeatures(array $features): void
    {
        $this->features = $features;
    }
}
