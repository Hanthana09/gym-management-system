<?php

namespace App\Entity;

use App\Repository\WorkoutLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's WORKOUT_LOG entity exactly.
 * `metrics` is deliberately a JSON column, not typed columns (§5.2:
 * "workout types vary too much — sets/reps for strength, laps for swim,
 * HR zones for cardio — to model as rigid columns").
 */
#[ORM\Entity(repositoryClass: WorkoutLogRepository::class)]
class WorkoutLog
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

    #[ORM\Column(length: 255)]
    private string $type;

    #[ORM\Column]
    private int $durationMinutes;

    #[ORM\Column(type: 'json')]
    private array $metrics;

    public function __construct(MemberProfile $member, \DateTimeImmutable $date, string $type, int $durationMinutes, array $metrics = [])
    {
        $this->id = Uuid::v7();
        $this->member = $member;
        $this->date = $date;
        $this->type = $type;
        $this->durationMinutes = $durationMinutes;
        $this->metrics = $metrics;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getDurationMinutes(): int
    {
        return $this->durationMinutes;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
