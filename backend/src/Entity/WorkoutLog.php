<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\WorkoutLogRepository;
use App\Security\Voter\PersonalTrackingVoter;
use App\State\CurrentMemberWorkoutLogsProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's WORKOUT_LOG entity exactly.
 * `metrics` is deliberately a JSON column, not typed columns (§5.2:
 * "workout types vary too much — sets/reps for strength, laps for swim,
 * HR zones for cardio — to model as rigid columns").
 *
 * #[ApiResource] on request (architecture doc §7/§9.1), added alongside
 * — not in place of — the existing hand-written controller, under
 * `/api/v1/...`:
 *   - `GET /members/me/workouts` → resolved via
 *     CurrentMemberWorkoutLogsProvider (no `{id}`, always the calling
 *     Member's own — the provider itself does the actual scoping, same
 *     as PersonalTrackingVoter::MANAGE's "own only" rule; the collection
 *     `security` here is just the coarse ROLE_MEMBER gate, since a
 *     collection-level check has no per-item object to test the Voter
 *     against).
 * §7's `POST /members/me/workouts` is deliberately NOT declared: the
 * constructor requires a `member` (MemberProfile), and MemberProfile is
 * `operations: []` in this pass (confirmed IRI-generation failure — see
 * its own docblock), so there is no IRI a client could even supply for
 * it. The real fix — resolving "me" server-side rather than from client
 * input — needs a custom write processor, which this pass avoids (see
 * Gym's docblock on why custom providers/processors are confirmed
 * unsafe for writes here).
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/members/me/workouts',
            provider: CurrentMemberWorkoutLogsProvider::class,
            security: "is_granted('ROLE_MEMBER')",
            normalizationContext: ['groups' => ['workout_log:me:read']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: WorkoutLogRepository::class)]
class WorkoutLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['workout_log:me:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MemberProfile::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['workout_log:me:read'])]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: 255)]
    #[Groups(['workout_log:me:read'])]
    private string $type;

    #[ORM\Column]
    #[Groups(['workout_log:me:read'])]
    private int $durationMinutes;

    #[ORM\Column(type: 'json')]
    #[Groups(['workout_log:me:read'])]
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
