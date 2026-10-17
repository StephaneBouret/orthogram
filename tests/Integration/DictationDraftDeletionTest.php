<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Dictation;
use App\Entity\DictationVersion;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationEditorialService;
use App\Tests\Support\DictationAudioFixture;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

final class DictationDraftDeletionTest extends TestCase
{
    private static DictationTestDatabase $database;
    private EntityManagerInterface $em;

    public static function setUpBeforeClass(): void
    {
        self::$database = new DictationTestDatabase();
        self::$database->install();
    }

    protected function setUp(): void
    {
        $this->em = self::$database->manager();
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->close();
    }

    private function service(?EntityManagerInterface $em = null): DictationEditorialService
    {
        return new DictationEditorialService($em ?? $this->em, new DictationCorrector(), DictationAudioFixture::storage());
    }

    public function testDeletionPreservesIdentityVersionsAttemptsResultsAndSharedAudio(): void
    {
        [$user, $dictation, $source] = DictationFactory::create($this->em);
        $attempts = DictationFactory::service($this->em);
        $state = $attempts->start($user, $dictation->getId());
        $attempts->transition($user, $state['attemptId'], \App\Enum\DictationAction::Evaluate, 0, 'L’ ouvre.');
        $before = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM dictation_attempt');
        $draft = $this->service()->cloneVersion($source, $source->getRevision());
        $id = $draft->getId();
        $survivor = $this->service()->cloneVersion($source, $source->getRevision());
        $audio = $draft->getWritingAudio();
        $files = glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3');
        $this->service()->deleteDraft($dictation->getId(), $id, $draft->getRevision());
        $this->em->clear();
        self::assertNull($this->em->find(DictationVersion::class, $id));
        self::assertNotNull($this->em->find(Dictation::class, $dictation->getId()));
        self::assertSame([1, 3], array_map('intval', $this->em->getConnection()->fetchFirstColumn('SELECT number FROM dictation_version WHERE dictation_id = ? ORDER BY number', [$dictation->getId()])));
        self::assertSame($before, $this->em->getConnection()->fetchAllAssociative('SELECT * FROM dictation_attempt'));
        self::assertSame($files, glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3'));
        DictationAudioFixture::storage()->verify($audio);
        $source = $this->em->find(DictationVersion::class, $source->getId());
        self::assertEquals($audio, $source->getWritingAudio());
        self::assertEquals($audio, $this->em->find(DictationVersion::class, $survivor->getId())->getWritingAudio());
        self::assertSame(4, $this->service()->createVersion($source->getDictation(), 'Après le trou', 'Texte.')->getNumber());
        self::assertSame(5, $this->service()->cloneVersion($source, $source->getRevision())->getNumber());
    }

    #[DataProvider('refusals')]
    public function testBusinessRefusals(string $kind, int $status): void
    {
        [$user, $dictation, $source] = DictationFactory::create($this->em);
        $draft = 'published' === $kind ? $source : $this->service()->cloneVersion($source, $source->getRevision());
        $id = $draft->getId();
        $dictationId = $dictation->getId();
        if ('attempt' === $kind) {
            $state = DictationFactory::service($this->em)->start($user, $dictationId);
            // Deliberately inconsistent legacy row in this disposable DB only: even a draft with a reference is protected.
            $this->em->getConnection()->executeStatement('UPDATE dictation_attempt SET version_id = ? WHERE id = ?', [$id, $state['attemptId']]);
        }
        if ('identity' === $kind) {
            [, $other] = DictationFactory::create($this->em);
            $dictationId = $other->getId();
        }
        if ('repeated' === $kind) {
            $this->service()->deleteDraft($dictationId, $id, $draft->getRevision());
        }
        try {
            $this->service()->deleteDraft($dictationId, $id, $draft->getRevision() + ('revision' === $kind ? 1 : 0));
            self::fail('Deletion accepted.');
        } catch (HttpException $error) {
            self::assertSame($status, $error->getStatusCode());
        }
        self::assertSame('repeated' !== $kind, false !== self::$database->manager()->getConnection()->fetchOne('SELECT id FROM dictation_version WHERE id = ?', [$id]));
    }

    /** @return iterable<array{string, int}> */
    public static function refusals(): iterable
    {
        yield ['published', 409];
        yield ['attempt', 409];
        yield ['revision', 409];
        yield ['identity', 404];
        yield ['repeated', 404];
    }

    #[DataProvider('staleOperations')]
    public function testStaleOperationsCannotResurrectDeletedVersion(string $action): void
    {
        [, $dictation, $source] = DictationFactory::create($this->em);
        $draft = $this->service()->cloneVersion($source, $source->getRevision());
        $id = $draft->getId();
        $other = self::$database->manager();
        $this->service($other)->deleteDraft($dictation->getId(), $id, $draft->getRevision());
        $files = glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3');
        try {
            if ('save' === $action) {
                $draft->edit('Saisie tardive', 'Texte.', null);
                $this->service()->saveDraft($draft, $draft->getRevision());
            } elseif ('import' === $action) {
                $this->service()->importAudio($draft, $draft->getRevision(), 'complete', DictationAudioFixture::upload());
            } else {
                $this->service()->cloneVersion($draft, $draft->getRevision());
            }
            self::fail('Stale operation accepted.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        self::assertSame($files, glob($_SERVER['DICTATION_STORAGE_DIR'].'/*.mp3'));
        self::assertSame([1], array_map('intval', $other->getConnection()->fetchFirstColumn('SELECT number FROM dictation_version WHERE dictation_id = ?', [$dictation->getId()])));
    }

    /** @return iterable<array{string}> */
    public static function staleOperations(): iterable
    {
        yield ['save'];
        yield ['import'];
        yield ['clone'];
    }

    #[DataProvider('races')]
    public function testRealConcurrentPublicationDeletionAndEdition(string $winner, string $waiting, int $expectedStatus): void
    {
        [, $dictation, $source] = DictationFactory::create($this->em);
        $draft = $this->service()->cloneVersion($source, $source->getRevision());
        $id = $draft->getId();
        $revision = $draft->getRevision();
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        $connection->fetchOne('SELECT id FROM dictation WHERE id = ? FOR UPDATE', [$dictation->getId()]);
        $worker = new Process([PHP_BINARY, '-d', 'xdebug.mode=off', dirname(__DIR__).'/Support/dictation_editorial_worker.php', self::$database->name, (string) $dictation->getId(), (string) $id, (string) $revision, $waiting, $_SERVER['DICTATION_STORAGE_DIR']]);
        $worker->setTimeout(30);
        try {
            $worker->start();
            $deadline = microtime(true) + 10;
            do {
                $output = $worker->getOutput();
                if (preg_match('/READY (\d+)/', $output, $matches)) {
                    $waitingCount = $connection->fetchOne('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID = ?', [$matches[1]]);
                    if ((int) $waitingCount > 0) {
                        break;
                    }
                }
                usleep(10000);
            } while ($worker->isRunning() && microtime(true) < $deadline);
            self::assertGreaterThan(0, (int) ($waitingCount ?? 0), 'Worker must actually wait on the identity lock. '.$worker->getErrorOutput());
            if ('delete' === $winner) {
                $this->service()->deleteDraft($dictation->getId(), $id, $revision);
            } elseif ('publish' === $winner) {
                $this->service()->publish($draft, $revision);
            } else {
                $draft->edit('Concurrent edit', $draft->getCanonicalText(), null);
                $this->service()->saveDraft($draft, $revision);
            }
            $connection->commit();
            $worker->wait();
            self::assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
            self::assertStringContainsString('RESULT '.$expectedStatus, $worker->getOutput());
            $stored = $connection->fetchAssociative('SELECT published_at, title FROM dictation_version WHERE id = ?', [$id]);
            if ('delete' === $winner) {
                self::assertFalse($stored);
            } elseif ('publish' === $winner) {
                self::assertNotNull($stored['published_at']);
            } else {
                self::assertSame('Concurrent edit', $stored['title']);
            }
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    /** @return iterable<array{string, string, int}> */
    public static function races(): iterable
    {
        yield ['publish', 'delete', 409];
        yield ['delete', 'publish', 404];
        yield ['save', 'delete', 409];
    }
}
