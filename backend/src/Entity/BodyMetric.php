<?php

namespace App\Entity;

use App\Repository\BodyMetricRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's BODY_METRIC entity, with one
 * deviation: `bodyFatPct` is nullable here. The ERD lists it as a plain
 * decimal, but most members only have a scale that measures weight, not
 * a body-fat method — requiring both on every entry would make the
 * feature unusable for the common case. `weightKg` stays required, the
 * primary trend metric. Typed (not JSON) columns, unlike WorkoutLog,
 * since the progress chart needs indexed, queryable trend data (§5.2).
 */
#[ORM\Entity(repositoryClass: BodyMetricRepository::class)]
class BodyMetric
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MemberProfile::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    private string $weightKg;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 1, nullable: true)]
    private ?string $bodyFatPct;

    public function __construct(MemberProfile $member, \DateTimeImmutable $date, string $weightKg, ?string $bodyFatPct = null)
    {
        $this->id = Uuid::v7();
        $this->member = $member;
        $this->date = $date;
        $this->weightKg = $weightKg;
        $this->bodyFatPct = $bodyFatPct;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMember(): MemberProfile
    {
        return $this->member;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getWeightKg(): string
    {
        return $this->weightKg;
    }

    public function getBodyFatPct(): ?string
    {
        return $this->bodyFatPct;
    }
}
