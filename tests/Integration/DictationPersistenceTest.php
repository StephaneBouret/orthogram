<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Dictation;
use App\Entity\DictationAttempt;
use App\Entity\DictationVersion;
use App\Entity\Lesson;
use App\Entity\User;
use App\Enum\DictationAction as Action;
use App\Enum\LessonStatus;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationEditorialService;
use App\Tests\Support\DictationAudioFixture;
use App\Tests\Support\DictationFactory;
use App\Tests\Support\DictationTestDatabase;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DictationPersistenceTest extends TestCase
{
    private static DictationTestDatabase $database;
    private EntityManagerInterface $em;

    public static function setUpBeforeClass(): void
    {
        self::$database = new DictationTestDatabase();
        try {
            self::$database->install();
        } catch (\Throwable $error) {
            self::$database->close();
            throw $error;
        }
    }

    protected function setUp(): void
    {
        $this->em = self::$database->manager();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->close();
    }

    public function testExactReloadUniqueFirstFeedbackAndPhaseProjections(): void
    {
        [$user, $dictation, , $course] = DictationFactory::create($this->em);
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        self::assertArrayNotHasKey('initialFeedback', $state);
        $copy = "\r\nL’\u{00A0}ouvre.  ";
        $first = $service->transition($user, $state['attemptId'], Action::Evaluate, 0, $copy);
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertSame($first, $service->state($user, $state['attemptId']));
        self::assertSame($first, $service->transition($user, $state['attemptId'], Action::Evaluate, 0, $copy));
        self::assertSame($copy, $first['initialCopy']);
        self::assertStringNotContainsString('SECRET', json_encode($first, JSON_THROW_ON_ERROR));
        $saved = $service->transition($user, $state['attemptId'], Action::Save, 1, 'L’ecole ouvre.');
        self::assertSame($first['initialFeedback'], $saved['initialFeedback']);
        $closed = $service->transition($user, $state['attemptId'], Action::Close, 2, 'L’ecole ouvre.');
        self::assertTrue($closed['prerequisiteSatisfied']);
        self::assertFalse($closed['canResume']);
        self::assertArrayNotHasKey('initialFeedback', $closed);
        self::assertArrayNotHasKey('issues', $closed['closedResult']);
        $course = $this->em->find($course::class, $course->getId());
        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName('Validation réelle')->setStatus(LessonStatus::DONE);
        $this->em->persist($lesson);
        $this->em->flush();
        $resumed = $service->transition($user, $state['attemptId'], Action::Resume, 3);
        self::assertSame('L’ecole ouvre.', $resumed['draft']);
        self::assertArrayNotHasKey('initialFeedback', $resumed);
        self::assertArrayNotHasKey('closedResult', $resumed);
        self::assertArrayNotHasKey('correctionContent', $resumed);
        // A replay in a later phase obeys that phase's projection, not the old response.
        self::assertSame($resumed, $service->transition($user, $state['attemptId'], Action::Evaluate, 0, $copy));
        $final = $service->transition($user, $state['attemptId'], Action::Finalize, 4, "L’e\u{0301}cole ouvre.");
        self::assertSame('SECRET_CORRECTION_TEST', $final['correctionContent']);
        self::assertSame("L’e\u{0301}cole ouvre.", $final['finalCopy']);
        self::assertTrue($final['finalResult']['noErrors']);
        self::assertSame('post_courses', $final['finalResultSource']);
        self::assertArrayNotHasKey('issues', $final['finalResult']);
        self::assertSame($final, $service->transition($user, $state['attemptId'], Action::Finalize, 4, "L’e\u{0301}cole ouvre."));
        $this->em->clear();
        self::assertSame($final, $service->state($this->em->find(User::class, $user->getId()), $state['attemptId']));
    }

    public function testCrossUserReadIsDenied(): void
    {
        [$user, $dictation] = DictationFactory::create($this->em);
        $other = QuizPlayerFactory::user('other-'.bin2hex(random_bytes(6)).'@example.test');
        $this->em->persist($other);
        $this->em->flush();
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        $this->expectException(NotFoundHttpException::class);
        $service->state($other, $state['attemptId']);
    }

    public function testCrossUserWriteIsDenied(): void
    {
        [$user, $dictation] = DictationFactory::create($this->em);
        $other = QuizPlayerFactory::user('other-'.bin2hex(random_bytes(6)).'@example.test');
        $this->em->persist($other);
        $this->em->flush();
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        $this->expectException(NotFoundHttpException::class);
        $service->transition($other, $state['attemptId'], Action::Evaluate, 0, 'L’école ouvre.');
    }

    public function testNewVersionDoesNotReplaceAttemptOrFrozenReference(): void
    {
        [$user, $dictation, $version] = DictationFactory::create($this->em);
        $service = DictationFactory::service($this->em);
        $first = $service->start($user, $dictation->getId());
        $editorial = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $next = $editorial->createVersion($dictation, 'Version suivante', 'Autre texte.');
        DictationAudioFixture::prepare($next);
        $editorial->saveDraft($next, $next->getRevision());
        $editorial->publish($next, $next->getRevision());
        self::assertSame(2, $next->getNumber());
        self::assertSame($first, $service->start($user, $dictation->getId()));
        $state = $service->transition($user, $first['attemptId'], Action::Evaluate, 0, 'L’école ouvre.');
        self::assertTrue($state['closedResult']['noErrors']);
        self::assertSame($version->getId(), $this->em->find(DictationAttempt::class, $first['attemptId'])->getVersion()->getId());
    }

    public function testUsedVersionIsProtectedBySqlForeignKey(): void
    {
        [$user, $dictation, $version] = DictationFactory::create($this->em);
        DictationFactory::service($this->em)->start($user, $dictation->getId());
        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em->getConnection()->delete('dictation_version', ['id' => $version->getId()]);
    }

    public function testAttemptUniquenessIsEnforcedBySql(): void
    {
        [$user, $dictation, $version] = DictationFactory::create($this->em);
        DictationFactory::service($this->em)->start($user, $dictation->getId());
        $this->em->persist(new DictationAttempt($user, $version, false));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSectionUniquenessIsEnforcedBySql(): void
    {
        [, $dictation] = DictationFactory::create($this->em);
        $this->em->persist(new Dictation($dictation->getSection()));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testPublishedVersionRejectsStaleDraftUpdateFromAnotherConnection(): void
    {
        [, $dictation] = DictationFactory::create($this->em);
        $editorial = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $draft = $editorial->createVersion($dictation, 'Brouillon', 'Texte.');
        DictationAudioFixture::prepare($draft);
        $editorial->saveDraft($draft, $draft->getRevision());
        $other = self::$database->manager();
        $stale = $other->find(DictationVersion::class, $draft->getId());
        $editorial->publish($draft, $draft->getRevision());
        $stale->edit('Modification concurrente', 'Autre.', null);
        $this->expectException(OptimisticLockException::class);
        $other->flush();
    }

    public function testPublicationAfterUnsavedEditPreservesSubmittedContent(): void
    {
        [, $dictation] = DictationFactory::create($this->em);
        $editorial = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $draft = $editorial->createVersion($dictation, 'Titre A', 'Texte A.', 'Correction A');
        DictationAudioFixture::prepare($draft);
        $editorial->saveDraft($draft, $draft->getRevision());
        $id = $draft->getId();
        $revision = $draft->getRevision();
        $variants = [['referenceIndex' => 1, 'alternatives' => ['bé']]];
        $draft->edit('Titre B', 'Texte B.', 'Correction B', $variants, true);
        try {
            $editorial->publish($draft, $revision);
            self::fail('La publication doit refuser les modifications non enregistrées.');
        } catch (ConflictHttpException $exception) {
            self::assertStringContainsString('non enregistrées', $exception->getMessage());
        }
        self::assertTrue($this->em->isOpen());
        self::assertSame('Texte B.', $draft->getCanonicalText());
        $other = self::$database->manager();
        $stored = $other->find(DictationVersion::class, $id);
        self::assertSame('Texte A.', $stored->getCanonicalText());
        self::assertFalse($stored->isPublished());
        self::assertSame($revision, $stored->getRevision());

        $editorial->saveDraft($draft, $revision);
        self::assertSame($revision + 1, $draft->getRevision());
        $editorial->publish($draft, $draft->getRevision());
        $this->em->clear();
        $published = $this->em->find(DictationVersion::class, $id);
        self::assertTrue($published->isPublished());
        self::assertSame('Texte B.', $published->getCanonicalText());
        self::assertSame('Titre B', $published->getTitle());
        self::assertSame('Correction B', $published->getCorrectionContent());
        self::assertEquals($variants, $published->getVariants());
    }

    public function testNormalPublicationOfSavedDraftAndPublishedImmutability(): void
    {
        [, $dictation] = DictationFactory::create($this->em);
        $editorial = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $draft = $editorial->createVersion($dictation, 'Titre A', 'Texte A.');
        DictationAudioFixture::prepare($draft);
        $editorial->saveDraft($draft, $draft->getRevision());
        $draft->edit('Titre B', 'Texte B.', null);
        $editorial->saveDraft($draft, $draft->getRevision());
        $id = $draft->getId();
        $revision = $draft->getRevision();
        $this->em->clear();
        $saved = $this->em->find(DictationVersion::class, $id);
        self::assertFalse($saved->isPublished());
        $editorial->publish($saved, $revision);
        self::assertSame($revision + 1, $saved->getRevision());
        $publishedAt = $saved->getPublishedAt();
        $editorial->publish($saved, $saved->getRevision());
        self::assertSame($revision + 1, $saved->getRevision());
        self::assertSame($publishedAt, $saved->getPublishedAt());
        $this->em->clear();
        $published = $this->em->find(DictationVersion::class, $id);
        self::assertSame('Texte B.', $published->getCanonicalText());
        self::assertTrue($published->isPublished());
        $this->expectException(\DomainException::class);
        $published->edit('Titre C', 'Texte C.', null);
    }

    public function testPublicationAlsoRefusesAnAlreadyComputedUnsavedChangeSet(): void
    {
        [, $dictation] = DictationFactory::create($this->em);
        $editorial = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $draft = $editorial->createVersion($dictation, 'Titre A', 'Texte A.');
        DictationAudioFixture::prepare($draft);
        $editorial->saveDraft($draft, $draft->getRevision());
        $draft->edit('Titre B', 'Texte B.', null);
        $this->em->getUnitOfWork()->computeChangeSet($this->em->getClassMetadata(DictationVersion::class), $draft);
        try {
            $editorial->publish($draft, $draft->getRevision());
            self::fail('Un change set préparé ne constitue pas un enregistrement.');
        } catch (ConflictHttpException) {
            self::assertSame('Texte B.', $draft->getCanonicalText());
            $stored = self::$database->manager()->find(DictationVersion::class, $draft->getId());
            self::assertSame('Texte A.', $stored->getCanonicalText());
            self::assertFalse($stored->isPublished());
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function concurrentEditorialChanges(): iterable
    {
        yield 'concurrent save, clean stale object' => [false, false];
        yield 'concurrent publication, clean stale object' => [true, false];
        yield 'concurrent save, unsaved local edit' => [false, true];
        yield 'concurrent publication, unsaved local edit' => [true, true];
    }

    #[DataProvider('concurrentEditorialChanges')]
    public function testStalePublicationNeverOverwritesOrDiscardsContent(bool $publishConcurrently, bool $localEdit): void
    {
        [, $dictation] = DictationFactory::create($this->em);
        $editorial = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $stale = $editorial->createVersion($dictation, 'Titre A', 'Texte A.');
        DictationAudioFixture::prepare($stale);
        $editorial->saveDraft($stale, $stale->getRevision());
        $revision = $stale->getRevision();
        $other = self::$database->manager();
        $current = $other->find(DictationVersion::class, $stale->getId());
        $otherService = new DictationEditorialService($other, new DictationCorrector(), DictationAudioFixture::storage());
        if ($publishConcurrently) {
            $otherService->publish($current, $revision);
        } else {
            $current->edit('Titre C', 'Texte C.', null);
            $otherService->saveDraft($current, $revision);
        }
        if ($localEdit) {
            $stale->edit('Titre B', 'Texte B.', null);
        }
        try {
            $editorial->publish($stale, $revision);
            self::fail('Publication périmée acceptée.');
        } catch (ConflictHttpException) {
            self::assertSame($localEdit ? 'Texte B.' : 'Texte A.', $stale->getCanonicalText());
            self::assertFalse($stale->isPublished());
            self::assertSame($revision, $stale->getRevision());
        }
        if ($localEdit) {
            // Dirty publication was refused before the transaction; saving must detect the stale revision too.
            try {
                $editorial->saveDraft($stale, $revision);
                self::fail('Enregistrement périmé accepté.');
            } catch (ConflictHttpException) {
                self::assertSame('Texte B.', $stale->getCanonicalText());
            }
        }
        $stored = self::$database->manager()->find(DictationVersion::class, $stale->getId());
        self::assertSame($publishConcurrently ? 'Texte A.' : 'Texte C.', $stored->getCanonicalText());
        self::assertSame($publishConcurrently, $stored->isPublished());
        self::assertSame($revision + 1, $stored->getRevision());
    }

    public function testPublicationChecksSubmittedRevisionEvenOnAFreshlyLoadedObject(): void
    {
        [, $dictation] = DictationFactory::create($this->em);
        $editorial = new DictationEditorialService($this->em, new DictationCorrector(), DictationAudioFixture::storage());
        $draft = $editorial->createVersion($dictation, 'Titre A', 'Texte A.');
        DictationAudioFixture::prepare($draft);
        $editorial->saveDraft($draft, $draft->getRevision());
        $formRevision = $draft->getRevision();
        $draft->edit('Titre C', 'Texte C.', null);
        $editorial->saveDraft($draft, $formRevision);
        $id = $draft->getId();
        $this->em->clear();
        $current = $this->em->find(DictationVersion::class, $id);
        try {
            $editorial->publish($current, $formRevision);
            self::fail('La révision soumise par le formulaire doit être vérifiée.');
        } catch (ConflictHttpException) {
            self::assertSame('Texte C.', $current->getCanonicalText());
            self::assertFalse(self::$database->manager()->find(DictationVersion::class, $id)->isPublished());
        }
    }

    public function testFrozenResultsRejectOrmChanges(): void
    {
        [$user, $dictation] = DictationFactory::create($this->em);
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        $service->transition($user, $state['attemptId'], Action::Evaluate, 0, 'L’ ouvre.');
        $attempt = $this->em->find(DictationAttempt::class, $state['attemptId']);
        (new \ReflectionProperty(DictationAttempt::class, 'initialCopy'))->setValue($attempt, 'altération');
        $this->expectException(\DomainException::class);
        $this->em->flush();
    }

    public function testRealCompletionRequiredAndLatePassageDetected(): void
    {
        [$user, $dictation, , $course] = DictationFactory::create($this->em);
        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName('Commencé')->setStatus(LessonStatus::STUDY);
        $this->em->persist($lesson);
        $this->em->flush();
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        self::assertTrue($state['latePassage']);
        $service->transition($user, $state['attemptId'], Action::Evaluate, 0, 'L’école ouvre.');
        $this->expectException(ConflictHttpException::class);
        $service->transition($user, $state['attemptId'], Action::Resume, 1);
    }

    public function testNoErrorPathStoresNoSyntheticFinalResult(): void
    {
        [$user, $dictation, , $course] = DictationFactory::create($this->em);
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        $service->transition($user, $state['attemptId'], Action::Evaluate, 0, 'L’école ouvre.');
        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName('Terminé')->setStatus(LessonStatus::DONE);
        $this->em->persist($lesson);
        $this->em->flush();
        $resumed = $service->transition($user, $state['attemptId'], Action::Resume, 1);
        self::assertSame('reviewing', $resumed['state']);
        self::assertArrayNotHasKey('correctionContent', $resumed);
        $final = $service->transition($user, $state['attemptId'], Action::Finalize, 2);
        self::assertSame('initial_closure', $final['finalResultSource']);
        self::assertFalse($final['latePassage']);
        $row = $this->em->getConnection()->fetchAssociative('SELECT corrected_copy, corrected_result, final_copy, final_result FROM dictation_attempt WHERE id = ?', [$state['attemptId']]);
        self::assertSame(['corrected_copy' => null, 'corrected_result' => null, 'final_copy' => null, 'final_result' => null], $row);
    }

    public function testMigrationDownAndUpStayWithinNewTables(): void
    {
        $connection = $this->em->getConnection();
        $users = $connection->fetchOne('SELECT COUNT(*) FROM user');
        self::$database->migration($connection, false);
        self::assertNotContains('dictation_attempt', $connection->createSchemaManager()->listTableNames());
        self::assertSame($users, $connection->fetchOne('SELECT COUNT(*) FROM user'));
        self::$database->migration($connection, true);
        self::assertContains('dictation_attempt', $connection->createSchemaManager()->listTableNames());
    }

    public function testCompletionIsReadAgainBeforeFinalization(): void
    {
        [$user, $dictation, , $course] = DictationFactory::create($this->em);
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        $service->transition($user, $state['attemptId'], Action::Evaluate, 0, 'L’école ouvre.');
        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName('Terminé')->setStatus(LessonStatus::DONE);
        $this->em->persist($lesson);
        $this->em->flush();
        $service->transition($user, $state['attemptId'], Action::Resume, 1);
        // Another connection reverses completion; the first EM still holds a DONE object.
        $other = self::$database->manager();
        $other->getConnection()->update('lesson', ['status' => 'study'], ['id' => $lesson->getId()]);
        $this->expectException(ConflictHttpException::class);
        $service->transition($user, $state['attemptId'], Action::Finalize, 2);
    }

    public function testLateSaveCannotOverwriteFinalizedCopyEvenFromStaleIdentityMap(): void
    {
        [$user, $dictation, , $course] = DictationFactory::create($this->em);
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        $service->transition($user, $state['attemptId'], Action::Evaluate, 0, 'L’école ouvre.');
        $other = self::$database->manager();
        $other->find(DictationAttempt::class, $state['attemptId']);
        $otherUser = $other->find(User::class, $user->getId());
        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName('Terminé')->setStatus(LessonStatus::DONE);
        $this->em->persist($lesson);
        $this->em->flush();
        $service->transition($user, $state['attemptId'], Action::Resume, 1);
        $service->transition($user, $state['attemptId'], Action::Finalize, 2);
        try {
            DictationFactory::service($other)->transition($otherUser, $state['attemptId'], Action::Save, 1, 'Périmé');
            self::fail('Sauvegarde périmée acceptée.');
        } catch (ConflictHttpException) {
            self::assertSame('L’école ouvre.', $service->state($user, $state['attemptId'])['finalCopy']);
        }
    }

    public function testBookmarkDetectsLatePassageWithoutACompletedLesson(): void
    {
        [$user, $dictation, , $course] = DictationFactory::create($this->em);
        $user->setLastVisitedCourse($course);
        $this->em->flush();
        $state = DictationFactory::service($this->em)->start($user, $dictation->getId());
        self::assertTrue($state['latePassage']);
        self::assertFalse($state['coursesComplete']);
    }

    public function testPerfectSelfCorrectionSurvivesReloadWithoutFinalEvaluation(): void
    {
        [$user, $dictation, , $course] = DictationFactory::create($this->em);
        $service = DictationFactory::service($this->em);
        $state = $service->start($user, $dictation->getId());
        $service->transition($user, $state['attemptId'], Action::Evaluate, 0, 'L’école ouvre');
        $closed = $service->transition($user, $state['attemptId'], Action::Close, 1, 'L’école ouvre.');
        self::assertTrue($closed['closedResult']['noErrors']);
        $lesson = (new Lesson())->setUser($user)->setCourse($course)->setName('Terminé')->setStatus(LessonStatus::DONE);
        $this->em->persist($lesson);
        $this->em->flush();
        $service->transition($user, $state['attemptId'], Action::Resume, 2);
        $final = $service->transition($user, $state['attemptId'], Action::Finalize, 3);
        $this->em->clear();
        self::assertSame($final, $service->state($this->em->find(User::class, $user->getId()), $state['attemptId']));
        $attempt = $this->em->find(DictationAttempt::class, $state['attemptId']);
        self::assertFalse($attempt->getInitialResult()['noErrors']);
        self::assertNull($attempt->getFinalResult());
        self::assertSame('initial_closure', $final['finalResultSource']);
    }
}
