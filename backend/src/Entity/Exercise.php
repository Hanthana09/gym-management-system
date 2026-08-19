<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\ExerciseCollectionProvider;
use App\State\ExerciseItemProvider;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * setly-phase-exercise-media.md §3: a platform-wide, shared reference
 * catalog — no `gym_id`, every gym and coach sees the same rows. Written
 * only by ImportExercisesCommand (claude-code-prompt-exercise-media.md's
 * hard exclusion: no per-gym custom exercise creation), never via a write
 * endpoint — this entity has no mutators beyond what the import's
 * find-or-create-by-`sourceId` upsert needs.
 *
 * `#[ApiResource]` with real Get/GetCollection operations (not
 * `operations: []` like every gym/user-scoped entity elsewhere in this
 * codebase) — Exercise has no relation at all, so it doesn't hit the
 * shared-PK/IRI-generation issues documented on Gym/MemberProfile's own
 * docblocks. Custom providers are still used, not the default Doctrine
 * one, because the Redis-cached JSONB_EXISTS filtering
 * (ExerciseRepository::findFilteredIds()) can't be expressed as a
 * declarative ApiResource filter.
 */
#[ApiResource(
    routePrefix: '/api/v1',
    operations: [
        new GetCollection(
            uriTemplate: '/exercises',
            provider: ExerciseCollectionProvider::class,
            security: "is_granted('ROLE_COACH') or is_granted('ROLE_OWNER')",
            normalizationContext: ['groups' => ['exercise:list']],
        ),
        new Get(
            uriTemplate: '/exercises/{id}',
            provider: ExerciseItemProvider::class,
            security: "is_granted('ROLE_COACH') or is_granted('ROLE_OWNER')",
            normalizationContext: ['groups' => ['exercise:detail']],
        ),
    ],
)]
#[ORM\Entity]
#[ORM\Table(name: 'exercise')]
#[ORM\UniqueConstraint(name: 'exercise_source_id_unique', columns: ['source_id'])]
class Exercise
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[Groups(['exercise:list', 'exercise:detail'])]
    private Uuid $id;

    /** Original dataset id/slug, e.g. `Alternate_Incline_Dumbbell_Curl` — the idempotent re-import key. */
    #[ORM\Column(length: 255, unique: true)]
    private string $sourceId;

    #[ORM\Column(length: 255)]
    #[Groups(['exercise:list', 'exercise:detail'])]
    private string $name;

    #[ORM\Column(length: 255)]
    #[Groups(['exercise:list', 'exercise:detail'])]
    private string $slug;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['exercise:detail'])]
    private ?string $force = null;

    #[ORM\Column(length: 20)]
    #[Groups(['exercise:detail'])]
    private string $level;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['exercise:detail'])]
    private ?string $mechanic = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['exercise:list', 'exercise:detail'])]
    private ?string $equipment = null;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    #[Groups(['exercise:detail'])]
    private array $primaryMuscles = [];

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    #[Groups(['exercise:detail'])]
    private array $secondaryMuscles = [];

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    #[Groups(['exercise:detail'])]
    private array $instructions = [];

    /** free-exercise-db's category enum includes "olympic weightlifting" (22 chars) — length must comfortably exceed the longest real value. */
    #[ORM\Column(length: 32)]
    #[Groups(['exercise:list', 'exercise:detail'])]
    private string $category;

    /** Flysystem path under exercise_media.storage, WebP ~300px ~10-15KB. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $posterImagePath = null;

    /** @var string[] Flysystem paths, ordered, WebP ~600px ~40-60KB each. */
    #[ORM\Column(type: 'json')]
    private array $detailImagePaths = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $sourceId, string $name, string $slug, string $level, string $category)
    {
        $this->id = Uuid::v7();
        $this->sourceId = $sourceId;
        $this->name = $name;
        $this->slug = $slug;
        $this->level = $level;
        $this->category = $category;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getForce(): ?string
    {
        return $this->force;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getMechanic(): ?string
    {
        return $this->mechanic;
    }

    public function getEquipment(): ?string
    {
        return $this->equipment;
    }

    /** @return string[] */
    public function getPrimaryMuscles(): array
    {
        return $this->primaryMuscles;
    }

    /** @return string[] */
    public function getSecondaryMuscles(): array
    {
        return $this->secondaryMuscles;
    }

    /** @return string[] */
    public function getInstructions(): array
    {
        return $this->instructions;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getPosterImagePath(): ?string
    {
        return $this->posterImagePath;
    }

    /** @return string[] */
    public function getDetailImagePaths(): array
    {
        return $this->detailImagePaths;
    }

    #[ApiProperty]
    #[Groups(['exercise:list', 'exercise:detail'])]
    public function getPosterUrl(): ?string
    {
        return $this->posterImagePath !== null ? '/media/exercises/' . $this->posterImagePath : null;
    }

    /** @return string[] */
    #[ApiProperty]
    #[Groups(['exercise:detail'])]
    public function getDetailImageUrls(): array
    {
        return array_map(fn (string $path) => '/media/exercises/' . $path, $this->detailImagePaths);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * ImportExercisesCommand's upsert — overwrites every dataset-derived
     * field on a re-run (setly-phase-exercise-media.md §4 step 2: "upsert
     * ... re-running the import updates existing rows rather than
     * duplicating"). `sourceId` itself is never touched here — it's the
     * lookup key, not a field re-imports change.
     *
     * @param string[] $primaryMuscles
     * @param string[] $secondaryMuscles
     * @param string[] $instructions
     */
    public function update(
        string $name,
        string $slug,
        ?string $force,
        string $level,
        ?string $mechanic,
        ?string $equipment,
        array $primaryMuscles,
        array $secondaryMuscles,
        array $instructions,
        string $category,
    ): void {
        $this->name = $name;
        $this->slug = $slug;
        $this->force = $force;
        $this->level = $level;
        $this->mechanic = $mechanic;
        $this->equipment = $equipment;
        $this->primaryMuscles = $primaryMuscles;
        $this->secondaryMuscles = $secondaryMuscles;
        $this->instructions = $instructions;
        $this->category = $category;
        $this->touch();
    }

    /** @param string[] $detailImagePaths */
    public function setImages(?string $posterImagePath, array $detailImagePaths): void
    {
        $this->posterImagePath = $posterImagePath;
        $this->detailImagePaths = $detailImagePaths;
        $this->touch();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
