<?php

namespace App\Tests\Command;

use App\Entity\Exercise;
use App\Repository\ExerciseRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * claude-code-prompt-exercise-media.md's testing section — fixture
 * dataset under tests/fixtures/exercise-import/ mirroring the real
 * on-disk layout (dist/exercises.json + exercises/{id}/*.jpg), no network
 * calls. `--source` points at it directly, exercising the exact same code
 * path a real vendored checkout would.
 */
final class ImportExercisesCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ExerciseRepository $exercises;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->exercises = $container->get(ExerciseRepository::class);
        $this->em->getConnection()->executeStatement('TRUNCATE exercise_log, workout_schedule_exercise, exercise CASCADE');

        $application = new Application(self::$kernel);
        $command = $application->find('app:exercise:import');
        $this->commandTester = new CommandTester($command);
    }

    private function fixtureSource(): string
    {
        return dirname(__DIR__) . '/fixtures/exercise-import';
    }

    public function test_running_the_import_creates_the_expected_exercises_with_correctly_sized_images(): void
    {
        $this->commandTester->execute(['--source' => $this->fixtureSource()]);

        self::assertSame(0, $this->commandTester->getStatusCode());
        self::assertStringContainsString('Imported 2, updated 0, skipped 3.', $this->commandTester->getDisplay());

        $benchPress = $this->exercises->findOneBySourceId('Test_Bench_Press');
        self::assertNotNull($benchPress);
        self::assertSame('Test Bench Press', $benchPress->getName());
        self::assertSame(['chest'], $benchPress->getPrimaryMuscles());
        self::assertNotNull($benchPress->getPosterImagePath());
        self::assertCount(2, $benchPress->getDetailImagePaths());

        $media = static::getContainer()->get('exercise_media.storage');
        self::assertInstanceOf(FilesystemOperator::class, $media);
        self::assertTrue($media->fileExists($benchPress->getPosterImagePath()));
        self::assertLessThanOrEqual(15 * 1024, $media->fileSize($benchPress->getPosterImagePath()));
        foreach ($benchPress->getDetailImagePaths() as $detailPath) {
            self::assertTrue($media->fileExists($detailPath));
            self::assertLessThanOrEqual(60 * 1024, $media->fileSize($detailPath));
        }
    }

    /** claude-code-prompt-exercise-media.md: "running the import command twice does not duplicate rows." */
    public function test_running_the_import_twice_does_not_duplicate_rows_and_updates_changed_fields(): void
    {
        $this->commandTester->execute(['--source' => $this->fixtureSource()]);
        $firstId = $this->exercises->findOneBySourceId('Test_Bench_Press')?->getId();

        // Simulate upstream data changing between runs.
        $updatedManifest = json_decode((string) file_get_contents($this->fixtureSource() . '/dist/exercises.json'), true, flags: JSON_THROW_ON_ERROR);
        $updatedManifest[0]['name'] = 'Test Bench Press (Updated)';
        $tmpDir = sys_get_temp_dir() . '/exercise-import-rerun-' . bin2hex(random_bytes(4));
        mkdir($tmpDir . '/dist', 0777, true);
        symlink($this->fixtureSource() . '/exercises', $tmpDir . '/exercises');
        file_put_contents($tmpDir . '/dist/exercises.json', json_encode($updatedManifest, JSON_THROW_ON_ERROR));

        $secondRun = new CommandTester((new Application(self::$kernel))->find('app:exercise:import'));
        $secondRun->execute(['--source' => $tmpDir]);

        self::assertStringContainsString('Imported 0, updated 2, skipped 3.', $secondRun->getDisplay());

        $count = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM exercise');
        self::assertSame(2, $count, 'a second run must not create duplicate rows');

        $benchPress = $this->exercises->findOneBySourceId('Test_Bench_Press');
        self::assertSame((string) $firstId, (string) $benchPress->getId(), 'the upsert must reuse the existing row, not replace it');
        self::assertSame('Test Bench Press (Updated)', $benchPress->getName());
    }

    /** claude-code-prompt-exercise-media.md: "a fixture record missing name/category is skipped ... appears in the summary." */
    public function test_records_missing_required_fields_are_skipped_and_reported_not_imported_with_nulls(): void
    {
        $this->commandTester->execute(['--source' => $this->fixtureSource()]);

        self::assertNull($this->exercises->findOneBySourceId('Test_Missing_Name'));
        self::assertNull($this->exercises->findOneBySourceId('Test_Missing_Category'));
        self::assertNull($this->exercises->findOneBySourceId('Test_Missing_Muscles'));

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('missing name', $display);
        self::assertStringContainsString('missing category', $display);
        self::assertStringContainsString('missing primaryMuscles', $display);
    }

    /** setly-phase-exercise-media.md §3 / verification checklist: enforced at the DB level, not just application logic. */
    public function test_source_id_uniqueness_is_enforced_at_the_database_level(): void
    {
        $exercise = new Exercise('duplicate-source-id', 'One', 'one', 'beginner', 'strength');
        $this->em->persist($exercise);
        $this->em->flush();

        $duplicate = new Exercise('duplicate-source-id', 'Two', 'two', 'beginner', 'strength');
        $this->em->persist($duplicate);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
