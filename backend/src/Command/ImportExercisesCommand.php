<?php

namespace App\Command;

use App\Entity\Exercise;
use App\Exercise\ExerciseImageProcessor;
use App\Repository\ExerciseRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * setly-phase-exercise-media.md §4 / claude-code-prompt-exercise-media.md
 * task 3. Run once at deploy time, safely re-runnable (idempotent on
 * `source_id`). Never fetches from GitHub itself — `--source` points at a
 * local, pre-vendored copy (see `make vendor-exercise-data`), pinned to a
 * specific commit for reproducibility:
 *
 *   Pinned commit: b0eed061e1c832b3ed815fbaa4b45b3cdc14df49
 *   (yuhonas/free-exercise-db, latest commit on `main` as of this phase's
 *   implementation — the repo has no tagged releases. Re-vendor via the
 *   Makefile target, which checks out this same SHA; bump both together
 *   if the dataset is ever deliberately updated.)
 */
#[AsCommand(name: 'app:exercise:import', description: 'Import the free-exercise-db catalog into the Exercise table (idempotent on source_id)')]
class ImportExercisesCommand extends Command
{
    public const PINNED_SOURCE_COMMIT = 'b0eed061e1c832b3ed815fbaa4b45b3cdc14df49';

    public function __construct(
        private readonly ExerciseRepository $exercises,
        private readonly EntityManagerInterface $em,
        private readonly ExerciseImageProcessor $imageProcessor,
        #[Autowire(service: 'exercise_media.storage')]
        private readonly FilesystemOperator $media,
        private readonly CacheItemPoolInterface $exerciseCatalogCache,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Local path to a vendored free-exercise-db checkout (containing dist/exercises.json and exercises/). '
                . 'Pinned commit: ' . self::PINNED_SOURCE_COMMIT . '. Re-vendor via `make vendor-exercise-data`.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sourceDir = rtrim((string) ($input->getOption('source') ?: $this->projectDir . '/var/vendor-data/free-exercise-db'), '/');
        $manifestPath = $sourceDir . '/dist/exercises.json';

        if (!is_file($manifestPath)) {
            $io->error("No dataset found at {$manifestPath}. Run `make vendor-exercise-data` first, or pass --source.");

            return Command::FAILURE;
        }

        $records = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

        $imported = 0;
        $updated = 0;
        /** @var array<int, array{0: string, 1: string}> $skipped id/name (or index) + reason */
        $skipped = [];

        foreach ($records as $record) {
            $reason = $this->validationError($record);
            if ($reason !== null) {
                $skipped[] = [(string) ($record['id'] ?? $record['name'] ?? '(unknown)'), $reason];
                continue;
            }

            [$posterPath, $detailPaths] = $this->processImages($sourceDir, $record);

            $exercise = $this->exercises->findOneBySourceId($record['id']);
            $isNew = $exercise === null;
            if ($exercise === null) {
                $exercise = new Exercise($record['id'], $record['name'], $this->slugify($record['name']), $record['level'] ?? 'beginner', $record['category']);
                $this->em->persist($exercise);
            }

            $exercise->update(
                $record['name'],
                $this->slugify($record['name']),
                $record['force'] ?? null,
                $record['level'] ?? 'beginner',
                $record['mechanic'] ?? null,
                $record['equipment'] ?? null,
                $record['primaryMuscles'] ?? [],
                $record['secondaryMuscles'] ?? [],
                $record['instructions'] ?? [],
                $record['category'],
            );
            $exercise->setImages($posterPath, $detailPaths);

            $isNew ? $imported++ : $updated++;
        }

        $this->em->flush();
        $this->exerciseCatalogCache->clear();

        $io->success(sprintf('Imported %d, updated %d, skipped %d.', $imported, $updated, count($skipped)));
        if ($skipped !== []) {
            $io->table(['Record', 'Reason skipped'], $skipped);
        }

        return Command::SUCCESS;
    }

    /** claude-code-prompt-exercise-media.md task 3: name/primaryMuscles/category minimum, plus id (the idempotency key). */
    private function validationError(mixed $record): ?string
    {
        if (!is_array($record)) {
            return 'record is not a valid object';
        }
        if (empty($record['id'])) {
            return 'missing id';
        }
        if (empty($record['name'])) {
            return 'missing name';
        }
        if (empty($record['primaryMuscles']) || !is_array($record['primaryMuscles'])) {
            return 'missing primaryMuscles';
        }
        if (empty($record['category'])) {
            return 'missing category';
        }

        return null;
    }

    /** @return array{0: ?string, 1: string[]} [posterPath, detailPaths] relative to exercise_media.storage's root */
    private function processImages(string $sourceDir, array $record): array
    {
        $images = is_array($record['images'] ?? null) ? $record['images'] : [];
        if ($images === []) {
            return [null, []];
        }

        $sourceId = $record['id'];
        $posterPath = null;
        $detailPaths = [];

        foreach ($images as $index => $relativeImagePath) {
            $bytes = @file_get_contents($sourceDir . '/exercises/' . $relativeImagePath);
            if ($bytes === false) {
                continue; // a listed image file missing from the vendored checkout — skip that one image, not the whole record
            }

            if ($index === 0) {
                $poster = $this->imageProcessor->toPoster($bytes);
                $filename = sprintf('poster-%s.webp', $this->contentHash($poster->binary));
                $this->media->write($sourceId . '/' . $filename, $poster->binary);
                $posterPath = $sourceId . '/' . $filename;
            }

            $detail = $this->imageProcessor->toDetail($bytes);
            $filename = sprintf('detail-%d-%s.webp', $index, $this->contentHash($detail->binary));
            $this->media->write($sourceId . '/' . $filename, $detail->binary);
            $detailPaths[] = $sourceId . '/' . $filename;
        }

        return [$posterPath, $detailPaths];
    }

    /** setly-phase-exercise-media.md §6: content-hashed filenames — a re-import that changes an image gets a new URL, no manual cache invalidation needed. */
    private function contentHash(string $binary): string
    {
        return substr(hash('sha256', $binary), 0, 8);
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }
}
