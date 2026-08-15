<?php

namespace App\Entity;

use App\Repository\DailyMetricSnapshotRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Fields match architecture doc §5.1's DAILY_METRIC_SNAPSHOT entity,
 * updated by roadmap Phase 16 with `branch_id` (nullable). A
 * pre-aggregated read model (§5.2) — every analytics feature in Phase 11
 * reads from this table, never from raw ATTENDANCE_LOG/MEMBERSHIP/
 * INVOICE rows directly. One row per branch per day, PLUS one gym-wide
 * rollup row per day where `branch` is null — DailyMetricAggregator
 * computes both (nightly for yesterday, or via backfill for historical
 * days), so "show me Branch A" and "show me the whole business" are both
 * simple queries against this one table, not two aggregation paths.
 *
 * The unique constraint intentionally still names only
 * (gym_id, snapshot_date) — Postgres treats every NULL as distinct in a
 * unique index, so it can't by itself stop two gym-wide (branch_id=null)
 * rows for the same day. DailyMetricAggregator's own find-then-update-in-
 * place logic (never delete+recreate) is what actually prevents that in
 * practice; this constraint still does real work for the per-branch case.
 */
#[ORM\Entity(repositoryClass: DailyMetricSnapshotRepository::class)]
#[ORM\UniqueConstraint(name: 'gym_snapshot_date_unique', columns: ['gym_id', 'snapshot_date', 'branch_id'])]
class DailyMetricSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Gym::class)]
    #[ORM\JoinColumn(name: 'gym_id', nullable: false)]
    private Gym $gym;

    #[ORM\ManyToOne(targetEntity: Branch::class)]
    #[ORM\JoinColumn(name: 'branch_id', nullable: true)]
    private ?Branch $branch;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $snapshotDate;

    #[ORM\Column]
    private int $checkinsCount;

    #[ORM\Column]
    private int $activeMembersCount;

    #[ORM\Column]
    private int $newMembersCount;

    #[ORM\Column]
    private int $cancelledMembersCount;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $revenue;

    #[ORM\Column]
    private int $atRiskMembersCount;

    public function __construct(
        Gym $gym,
        \DateTimeImmutable $snapshotDate,
        int $checkinsCount,
        int $activeMembersCount,
        int $newMembersCount,
        int $cancelledMembersCount,
        string $revenue,
        int $atRiskMembersCount,
        ?Branch $branch = null,
    ) {
        $this->id = Uuid::v7();
        $this->gym = $gym;
        $this->branch = $branch;
        $this->snapshotDate = $snapshotDate;
        $this->checkinsCount = $checkinsCount;
        $this->activeMembersCount = $activeMembersCount;
        $this->newMembersCount = $newMembersCount;
        $this->cancelledMembersCount = $cancelledMembersCount;
        $this->revenue = $revenue;
        $this->atRiskMembersCount = $atRiskMembersCount;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getGym(): Gym
    {
        return $this->gym;
    }

    public function getBranch(): ?Branch
    {
        return $this->branch;
    }

    public function getSnapshotDate(): \DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function getCheckinsCount(): int
    {
        return $this->checkinsCount;
    }

    public function getActiveMembersCount(): int
    {
        return $this->activeMembersCount;
    }

    public function getNewMembersCount(): int
    {
        return $this->newMembersCount;
    }

    public function getCancelledMembersCount(): int
    {
        return $this->cancelledMembersCount;
    }

    public function getRevenue(): string
    {
        return $this->revenue;
    }

    public function getAtRiskMembersCount(): int
    {
        return $this->atRiskMembersCount;
    }

    /** Overwrite in place — DailyMetricAggregator re-runs idempotently rather than deleting+recreating rows. */
    public function update(
        int $checkinsCount,
        int $activeMembersCount,
        int $newMembersCount,
        int $cancelledMembersCount,
        string $revenue,
        int $atRiskMembersCount,
    ): void {
        $this->checkinsCount = $checkinsCount;
        $this->activeMembersCount = $activeMembersCount;
        $this->newMembersCount = $newMembersCount;
        $this->cancelledMembersCount = $cancelledMembersCount;
        $this->revenue = $revenue;
        $this->atRiskMembersCount = $atRiskMembersCount;
    }
}
