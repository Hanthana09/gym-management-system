<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
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
 *
 * #[ApiResource(operations: []) — §7's `/reports/*` endpoints read this
 * table internally (DailyMetricSnapshotRepository), but their response
 * bodies are shaped as dashboard/trend/forecast DTOs, not this entity's
 * own rows — same "aggregate endpoint, not entity CRUD" reasoning as
 * Gym's docblock gives for the same `/reports/*` list.
 *
 * roadmap Phase 17: `retailRevenue`/`expenseTotal`/`expenseByCategory`
 * are new — appended as trailing, defaulted constructor/update()
 * parameters (never inserted before existing ones) specifically so
 * RevenueForecasterTest's pre-existing 8-positional-arg call site (and
 * DailyMetricAggregator's own 9-arg one, `$branch` included) keep
 * compiling unchanged, per this phase's "don't break existing call
 * sites" requirement. `expenseByCategory` is JSON (category_id => amount)
 * for the same flexible-per-category reason WORKOUT_LOG.metrics is JSON
 * (§5.2) — a fixed column per category would mean a migration every time
 * an Owner added one.
 */
#[ApiResource(routePrefix: '/api/v1', operations: [])]
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

    /** roadmap Phase 17: sum of that day/branch's PRODUCT_SALE.total_amount. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $retailRevenue;

    /** roadmap Phase 17: sum of that day/branch's EXPENSE.amount. */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $expenseTotal;

    /** roadmap Phase 17: category_id => amount, JSON per the class docblock's rationale. @var array<string, string> */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $expenseByCategory;

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
        string $retailRevenue = '0.00',
        string $expenseTotal = '0.00',
        array $expenseByCategory = [],
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
        $this->retailRevenue = $retailRevenue;
        $this->expenseTotal = $expenseTotal;
        $this->expenseByCategory = $expenseByCategory;
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

    public function getRetailRevenue(): string
    {
        return $this->retailRevenue;
    }

    public function getExpenseTotal(): string
    {
        return $this->expenseTotal;
    }

    /** @return array<string, string> */
    public function getExpenseByCategory(): array
    {
        return $this->expenseByCategory;
    }

    /**
     * Overwrite in place — DailyMetricAggregator re-runs idempotently
     * rather than deleting+recreating rows. roadmap Phase 17's three new
     * trailing params default to null, meaning "leave unchanged" — kept
     * nullable-optional rather than required so this signature stays
     * backward compatible with any future caller that only knows about
     * the pre-Phase-17 fields, same "don't break existing call sites"
     * reasoning as the constructor above. DailyMetricAggregator (the only
     * real caller) always passes all three explicitly.
     */
    public function update(
        int $checkinsCount,
        int $activeMembersCount,
        int $newMembersCount,
        int $cancelledMembersCount,
        string $revenue,
        int $atRiskMembersCount,
        ?string $retailRevenue = null,
        ?string $expenseTotal = null,
        ?array $expenseByCategory = null,
    ): void {
        $this->checkinsCount = $checkinsCount;
        $this->activeMembersCount = $activeMembersCount;
        $this->newMembersCount = $newMembersCount;
        $this->cancelledMembersCount = $cancelledMembersCount;
        $this->revenue = $revenue;
        $this->atRiskMembersCount = $atRiskMembersCount;
        if ($retailRevenue !== null) {
            $this->retailRevenue = $retailRevenue;
        }
        if ($expenseTotal !== null) {
            $this->expenseTotal = $expenseTotal;
        }
        if ($expenseByCategory !== null) {
            $this->expenseByCategory = $expenseByCategory;
        }
    }
}
