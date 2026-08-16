<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\BodyMetricRepository;
use App\State\CurrentMemberBodyMetricsProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's BODY_METRIC entity, with one
 * deviation: `bodyFatPct` is nullable here. The ERD lists it as a plain
 * decimal, but most members only have a scale that measures weight, not
 * a body-fat method — requiring both on every entry would make the
 * feature unusable for the common case. `weightKg` stays required, the
 * primary trend metric. Typed (not JSON) columns, unlike WorkoutLog,
 * since the progress chart needs indexed, queryable trend data (§5.2).
 *
 * #[ApiResource] on request (architecture doc §7/§9.1), added alongside
 * — not in place of — the existing hand-written controller, under
 * `/api/v1/...`:
 *   - `GET /members/me/body-metrics` → resolved via
 *     CurrentMemberBodyMetricsProvider (no `{id}`, always the calling
 *     Member's own — same PersonalTrackingVoter::MANAGE "own only" rule,
 *     enforced by the provider's own query; collection `security` is
 *     the coarse ROLE_MEMBER gate, same reasoning as WorkoutLog).
 * §7 lists no POST for body-metrics (only workouts get one, and even
 * that one isn't declared here — see WorkoutLog's docblock for why).
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/members/me/body-metrics',
            provider: CurrentMemberBodyMetricsProvider::class,
            security: "is_granted('ROLE_MEMBER')",
            normalizationContext: ['groups' => ['body_metric:me:read']],
        ),
    ],
)]
#[ORM\Entity(repositoryClass: BodyMetricRepository::class)]
class BodyMetric
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['body_metric:me:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: MemberProfile::class)]
    #[ORM\JoinColumn(name: 'member_id', referencedColumnName: 'user_id', nullable: false)]
    private MemberProfile $member;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['body_metric:me:read'])]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Groups(['body_metric:me:read'])]
    private string $weightKg;

    #[ORM\Column(type: 'decimal', precision: 4, scale: 1, nullable: true)]
    #[Groups(['body_metric:me:read'])]
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
