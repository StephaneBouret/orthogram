<?php

namespace App\Tests\Controller\Admin;

use App\Entity\Courses;
use App\Entity\Exercice;
use App\Entity\Program;
use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\Sections;
use App\Entity\User;
use App\Tests\Support\QuizFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use libphonenumber\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class QuizAdminTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private ?string $previousEnvUrl;
    private ?string $previousServerUrl;

    protected function setUp(): void
    {
        $this->previousEnvUrl = $_ENV['DATABASE_URL'] ?? null;
        $this->previousServerUrl = $_SERVER['DATABASE_URL'] ?? null;
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($this->em->getConnection()->getParams()['memory'] ?? false);
        $this->em->getConnection()->executeStatement('PRAGMA foreign_keys = ON');
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        $this->user = (new User())->setEmail('quiz-admin@example.test')->setPassword('test-password')
            ->setFirstname('Camille')->setLastname('Test')->setAddress('1 rue du Test')
            ->setPostalCode('75001')->setCity('Paris')
            ->setPhone((new PhoneNumber())->setCountryCode(33)->setNationalNumber('612345678'))
            ->setRoles(['ROLE_ADMIN']);
        $this->em->persist($this->user);
        $this->em->flush();
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->getConnection()->close();
        }
        parent::tearDown();
        if (null === $this->previousEnvUrl) {
            unset($_ENV['DATABASE_URL']);
        } else {
            $_ENV['DATABASE_URL'] = $this->previousEnvUrl;
        }
        if (null === $this->previousServerUrl) {
            unset($_SERVER['DATABASE_URL']);
        } else {
            $_SERVER['DATABASE_URL'] = $this->previousServerUrl;
        }
    }

    public function testAdminCreatesEmptyQuizThenUniqueAndMultipleQuestions(): void
    {
        $this->submit('/admin/quiz/new', 'Quiz', ['title' => 'Révision']);
        self::assertResponseRedirects();
        $quiz = $this->em->getRepository(Quiz::class)->findOneBy(['title' => 'Révision']);
        self::assertNotNull($quiz);
        self::assertCount(0, $quiz->getQuestions());

        $this->submit('/admin/quiz-question/new', 'QuizQuestion', $this->questionPayload($quiz));
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $payload = $this->questionPayload($quiz);
        $payload['multiple'] = '1';
        $payload['answers'][1]['correct'] = '1';
        $this->submit('/admin/quiz-question/new', 'QuizQuestion', $payload);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame(2, $this->em->getRepository(QuizQuestion::class)->count([]));
        self::assertSame(4, $this->em->getRepository(QuizAnswer::class)->count([]));
        $multiple = $this->em->getRepository(QuizQuestion::class)->findOneBy(['multiple' => true]);
        self::assertNotNull($multiple);
        self::assertTrue($multiple->getAnswers()->first()->isCorrect());
        self::assertTrue($multiple->getAnswers()->last()->isCorrect());
    }

    public function testEditRemovesAndAddsCorrectAnswerAndValidatesFinalCollection(): void
    {
        $question = $this->persistQuestion();
        $quiz = $question->getQuiz();
        $removedId = $question->getAnswers()->first()->getId();
        $payload = $this->questionPayload($quiz);
        unset($payload['answers'][0]);
        $payload['answers'][2] = ['content' => 'Nouvelle bonne réponse', 'correct' => '1'];
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->find(QuizAnswer::class, $removedId));
        self::assertNotNull($this->em->find(Quiz::class, $quiz->getId()));
        $reloaded = $this->em->find(QuizQuestion::class, $question->getId());
        self::assertCount(2, $reloaded->getAnswers());
        self::assertSame('Nouvelle bonne réponse', $reloaded->getAnswers()->last()->getContent());
        self::assertSame($reloaded, $reloaded->getAnswers()->first()->getQuestion());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEdits(): iterable
    {
        yield 'suppression de la seule bonne réponse' => ['remove'];
        yield 'ajout de deux bonnes réponses' => ['add'];
        yield 'contenu vide' => ['blank'];
        yield 'consigne vide' => ['title'];
        yield 'position négative' => ['position'];
        yield 'position vide' => ['emptyPosition'];
        yield 'mode manquant' => ['multiple'];
        yield 'quiz manquant' => ['quiz'];
    }

    #[DataProvider('invalidEdits')]
    public function testInvalidEditDisplaysErrorsAndDoesNotPartiallyPersist(string $case): void
    {
        $question = $this->persistQuestion();
        $before = $this->em->getConnection()->fetchAllAssociative('SELECT * FROM quiz_answer ORDER BY id');
        $payload = $this->questionPayload($question->getQuiz());
        $payload['title'] = 'Modification refusée';
        switch ($case) {
            case 'remove':
                unset($payload['answers'][0]);
                break;
            case 'add':
                $payload['answers'][2] = ['content' => 'Autre bonne réponse', 'correct' => '1'];
                break;
            case 'blank':
                $payload['answers'][0]['content'] = '  ';
                break;
            case 'title':
                $payload['title'] = '';
                break;
            case 'position':
                $payload['position'] = '-1';
                break;
            case 'emptyPosition':
                $payload['position'] = '';
                break;
            case 'multiple':
                $payload['multiple'] = '';
                break;
            case 'quiz':
                $payload['quiz'] = '';
                break;
        }
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback');
        self::assertSame($before, $this->em->getConnection()->fetchAllAssociative('SELECT * FROM quiz_answer ORDER BY id'));
        self::assertSame($question->getTitle(), $this->em->getConnection()->fetchOne('SELECT title FROM quiz_question WHERE id = ?', [$question->getId()]));
    }

    public function testInvalidCreationDoesNotPersistQuestionOrAnswers(): void
    {
        $question = $this->persistQuestion();
        $payload = $this->questionPayload($question->getQuiz());
        unset($payload['answers'][0]['correct']);
        $this->submit('/admin/quiz-question/new', 'QuizQuestion', $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback');
        self::assertSame(1, $this->em->getRepository(QuizQuestion::class)->count([]));
        self::assertSame(2, $this->em->getRepository(QuizAnswer::class)->count([]));
    }

    public function testOrdinaryUserCannotAccessDirectAdminRoutes(): void
    {
        $question = $this->persistQuestion();
        $this->user->setRoles(['ROLE_USER']);
        $this->em->flush();
        $this->client->loginUser($this->user);
        $this->client->catchExceptions(true);
        foreach (['/admin/quiz', '/admin/quiz/new', '/admin/quiz/'.$question->getQuiz()->getId().'/edit', '/admin/quiz-question', '/admin/quiz-question/new', '/admin/quiz-question/'.$question->getId().'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
            if (str_ends_with($url, '/new') || str_ends_with($url, '/edit')) {
                $this->client->request('POST', $url, ['QuizQuestion' => ['title' => 'Interdit']]);
                self::assertResponseStatusCodeSame(403);
            }
        }
    }

    public function testPersistedCollectionsUsePositionThenIdAndCascadeOnlyWithinQuiz(): void
    {
        $first = $this->persistQuestion()->setPosition(5);
        $quiz = $first->getQuiz();
        $second = QuizFactory::question()->setQuiz($quiz)->setPosition(1);
        $third = QuizFactory::question()->setQuiz($quiz)->setPosition(1);
        $first->getAnswers()->first()->setPosition(3);
        $first->getAnswers()->last()->setPosition(0);
        $extra = (new QuizAnswer())->setContent('Même position')->setPosition(0);
        $first->addAnswer($extra);
        $this->em->flush();
        $questionIds = [$second->getId(), $third->getId(), $first->getId()];
        $answerIds = [$first->getAnswers()->get(1)->getId(), $extra->getId(), $first->getAnswers()->get(0)->getId()];
        $this->em->clear();
        $quiz = $this->em->find(Quiz::class, $quiz->getId());
        self::assertSame($questionIds, $quiz->getQuestions()->map(static fn (QuizQuestion $q) => $q->getId())->toArray());
        $first = $this->em->find(QuizQuestion::class, $first->getId());
        self::assertSame($answerIds, $first->getAnswers()->map(static fn (QuizAnswer $a) => $a->getId())->toArray());
        $quiz->removeQuestion($first);
        $this->em->flush();
        self::assertSame(2, $this->em->getRepository(QuizQuestion::class)->count([]));
        self::assertSame(4, $this->em->getRepository(QuizAnswer::class)->count([]));
        self::assertSame(1, $this->em->getRepository(Quiz::class)->count([]));
    }

    public function testCourseTypeChangesKeepQuizAndExistingExerciseBehavior(): void
    {
        $question = $this->persistQuestion();
        $quiz = $question->getQuiz();
        $program = (new Program())->setName('Programme test')->setSlug('programme-test')->setDescription('Test')->setPrice(100);
        $section = (new Sections())->setName('Section test')->setSlug('section-test')->setProgram($program);
        $exercice = (new Exercice())->setTitle('Exercice test')->setInstruction('Test');
        foreach ([$program, $section, $exercice] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $payload = ['name' => 'Quiz de révision', 'contentType' => 'quiz', 'section' => (string) $section->getId(), 'quiz' => (string) $quiz->getId(), 'exercice' => (string) $exercice->getId()];
        $this->submit('/admin/courses/new', 'Courses', $payload);
        self::assertResponseRedirects();
        $course = $this->em->getRepository(Courses::class)->findOneBy(['name' => 'Quiz de révision']);
        $id = $course->getId();
        self::assertSame($quiz->getId(), $course->getQuiz()->getId());
        self::assertNull($course->getExercice());

        $payload['contentType'] = 'exercise';
        $this->submit('/admin/courses/'.$id.'/edit', 'Courses', $payload);
        self::assertResponseRedirects();
        $this->em->clear();
        $course = $this->em->find(Courses::class, $id);
        self::assertNull($course->getQuiz());
        self::assertSame($exercice->getId(), $course->getExercice()->getId());
        self::assertNotNull($this->em->find(Quiz::class, $quiz->getId()));

        $payload['contentType'] = 'twig';
        $this->submit('/admin/courses/'.$id.'/edit', 'Courses', $payload);
        self::assertResponseRedirects();
        $this->em->clear();
        $course = $this->em->find(Courses::class, $id);
        self::assertNull($course->getExercice());
        self::assertNull($course->getQuiz());
        self::assertNotNull($this->em->find(Exercice::class, $exercice->getId()));

        $payload['contentType'] = 'quiz';
        $payload['quiz'] = '';
        $this->submit('/admin/courses/'.$id.'/edit', 'Courses', $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback');

        $this->em->clear();
        $course = $this->em->find(Courses::class, $id);
        $quiz = $this->em->find(Quiz::class, $quiz->getId());
        $course->setQuiz($quiz);
        $this->em->flush();
        $this->em->remove($quiz);
        $this->em->flush();
        $this->em->clear();
        self::assertNotNull($this->em->find(Courses::class, $id));
        self::assertNull($this->em->find(Courses::class, $id)->getQuiz());
        self::assertSame(0, $this->em->getRepository(QuizQuestion::class)->count([]));
        self::assertSame(0, $this->em->getRepository(QuizAnswer::class)->count([]));
        self::assertNotNull($this->em->find(Exercice::class, $exercice->getId()));
    }

    public function testMovingPersistedQuestionToAnotherQuizKeepsItsAnswers(): void
    {
        $question = $this->persistQuestion();
        $oldQuizId = $question->getQuiz()->getId();
        $newQuiz = (new Quiz())->setTitle('Autre quiz');
        $this->em->persist($newQuiz);
        $this->em->flush();
        $id = $question->getId();
        $this->submit('/admin/quiz-question/'.$id.'/edit', 'QuizQuestion', $this->questionPayload($newQuiz));
        self::assertResponseRedirects();
        $this->em->clear();
        $question = $this->em->find(QuizQuestion::class, $id);
        self::assertNotNull($question);
        self::assertSame($newQuiz->getId(), $question->getQuiz()->getId());
        self::assertCount(2, $question->getAnswers());
        self::assertCount(0, $this->em->find(Quiz::class, $oldQuizId)->getQuestions());
    }

    public function testPopulatedIndexDisplaysBothModesWithoutSwitches(): void
    {
        $single = $this->persistQuestion()->setTitle('Question unique');
        $multiple = QuizFactory::question(true)->setQuiz($single->getQuiz())->setTitle('Question multiple');
        $this->em->flush();
        $this->client->request('GET', '/admin/quiz-question');
        self::assertResponseStatusCodeSame(200);
        foreach ([[$single, 'Non'], [$multiple, 'Oui']] as [$question, $label]) {
            $row = 'tr[data-id="'.$question->getId().'"]';
            self::assertSelectorTextContains($row.' td[data-column="title"]', $question->getTitle());
            self::assertSelectorTextContains($row.' td[data-column="multiple"]', $label);
            self::assertSelectorNotExists($row.' td[data-column="multiple"] input');
        }
        foreach (['/admin/quiz-question/new', '/admin/quiz-question/'.$single->getId().'/edit'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('select[name="QuizQuestion[multiple]"]', 'Une seule réponse');
            self::assertSelectorTextContains('select[name="QuizQuestion[multiple]"]', 'Plusieurs réponses');
        }
    }

    public function testCreatesThreeAnswersWithHiddenOrderAndReopensInSavedOrder(): void
    {
        $quiz = $this->persistQuestion()->getQuiz();
        $payload = $this->questionPayload($quiz);
        $payload['title'] = 'Trois propositions';
        $payload['answers'][2] = ['content' => 'paisiblement'];
        $this->submit('/admin/quiz-question/new', 'QuizQuestion', $payload);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $question = $this->em->getRepository(QuizQuestion::class)->findOneBy(['title' => 'Trois propositions']);
        $this->client->request('GET', '/admin/quiz-question/'.$question->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(3, '[data-quiz-answer-order] input[type="hidden"][data-quiz-answer-position]');
        self::assertSelectorNotExists('[data-quiz-answer-order] input[type="number"]');
        self::assertSelectorExists('input[type="number"][name="QuizQuestion[position]"]');
        self::assertSame(['chat', 'dort', 'paisiblement'], $this->renderedAnswerContents());
        self::assertSame([0, 1, 2], array_column($this->answerRows($question), 'position'));
    }

    public function testReorderPreservesPersistedIdentityContentAndCorrectness(): void
    {
        $question = $this->persistThreeAnswers();
        $before = $this->answerRows($question);
        $payload = $this->questionPayload($question->getQuiz());
        foreach ($before as $key => $row) {
            $payload['answers'][$key] = ['content' => $row['content']];
            if ($row['correct']) {
                $payload['answers'][$key]['correct'] = '1';
            }
        }
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload, [2, 0, 1]);
        self::assertResponseRedirects();
        $expected = [$before[2], $before[0], $before[1]];
        foreach ($expected as $position => &$row) {
            $row['position'] = $position;
        }
        unset($row);
        self::assertSame($expected, $this->answerRows($question));
        $this->client->request('GET', '/admin/quiz-question/'.$question->getId().'/edit');
        self::assertSame(array_column($expected, 'content'), $this->renderedAnswerContents());
    }

    public function testDeleteMiddleThenAppendUsesContiguousOrderWithoutLosingOtherAnswers(): void
    {
        $question = $this->persistThreeAnswers();
        $before = $this->answerRows($question);
        $payload = $this->questionPayload($question->getQuiz());
        unset($payload['answers'][1]);
        $payload['answers'][2] = ['content' => $before[2]['content']];
        $payload['answers'][3] = ['content' => 'Ajout à la fin'];
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload);
        self::assertResponseRedirects();
        $after = $this->answerRows($question);
        self::assertSame([$before[0]['id'], $before[2]['id']], array_slice(array_column($after, 'id'), 0, 2));
        self::assertNotContains($after[2]['id'], array_column($before, 'id'));
        self::assertSame('Ajout à la fin', $after[2]['content']);
        self::assertSame([0, 1, 2], array_column($after, 'position'));
        self::assertNull($this->em->find(QuizAnswer::class, $before[1]['id']));
    }

    public function testInvalidMovedAnswerKeepsOrderInputsAndErrorsWithoutPersisting(): void
    {
        $question = $this->persistThreeAnswers();
        $before = $this->answerRows($question);
        $payload = $this->questionPayload($question->getQuiz());
        $payload['answers'][2] = ['content' => ''];
        $payload['answers'][1]['content'] = 'Saisie conservée';
        $payload['answers'][1]['correct'] = '1';
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload, [2, 1, 0]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['', 'Saisie conservée', 'chat'], $this->renderedAnswerContents());
        self::assertSelectorExists('#QuizQuestion_answers_2_content.is-invalid');
        self::assertSelectorExists('#QuizQuestion_answers_1_correct[checked]');
        self::assertSelectorExists('#QuizQuestion_answers_0_correct[checked]');
        self::assertSame($before, $this->answerRows($question));
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedPositions(): iterable
    {
        yield 'manquante' => [null];
        yield 'vide' => [''];
        yield 'négative' => ['-1'];
        yield 'doublon' => ['1'];
        yield 'hors liste' => ['99'];
        yield 'décimale' => ['0.5'];
        yield 'non numérique' => ['abc'];
        yield 'zéro non canonique' => ['00'];
        yield 'entier trop grand' => [str_repeat('9', 100)];
        yield 'tableau' => [['0']];
    }

    #[DataProvider('malformedPositions')]
    public function testMalformedOrderReturnsFormErrorWithoutPersistence(mixed $position): void
    {
        $question = $this->persistQuestion();
        $before = $this->answerRows($question);
        $payload = $this->questionPayload($question->getQuiz());
        $payload['answers'][0]['position'] = $position;
        $payload['answers'][1]['position'] = '1';
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload, normalizeOrder: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-quiz-answer-order]', 'L’ordre des propositions est invalide.');
        self::assertSame($before, $this->answerRows($question));
    }

    public function testSubmittedForeignAnswerIdCannotReplaceAnExistingAnswer(): void
    {
        $question = $this->persistQuestion();
        $foreign = $this->persistQuestion()->getAnswers()->first();
        $before = $this->answerRows($question);
        $payload = $this->questionPayload($question->getQuiz());
        $payload['answers'][0]['id'] = (string) $foreign->getId();
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.invalid-feedback');
        self::assertSame($before, $this->answerRows($question));
    }

    public function testOpeningLegacyPositionsDoesNotWriteToDatabase(): void
    {
        $question = $this->persistThreeAnswers();
        foreach ($question->getAnswers() as $key => $answer) {
            $answer->setPosition(10 + 5 * $key);
        }
        $this->em->flush();
        $before = $this->answerRows($question);
        $this->client->request('GET', '/admin/quiz-question/'.$question->getId().'/edit');
        self::assertResponseIsSuccessful();
        self::assertSame(['0', '1', '2'], $this->client->getCrawler()->filter('[data-quiz-answer-position]')->extract(['value']));
        self::assertSame($before, $this->answerRows($question));
    }

    public function testReorderingDoesNotBypassMultipleAnswerValidation(): void
    {
        $question = $this->persistQuestion()->setMultiple(true);
        $this->em->flush();
        $before = $this->answerRows($question);
        $payload = $this->questionPayload($question->getQuiz());
        $payload['multiple'] = '1';
        unset($payload['answers'][0]['correct']);
        $this->submit('/admin/quiz-question/'.$question->getId().'/edit', 'QuizQuestion', $payload, [1, 0]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-quiz-answer-order]', 'Indiquez au moins une bonne réponse.');
        self::assertSame(['dort', 'chat'], $this->renderedAnswerContents());
        self::assertSame($before, $this->answerRows($question));
    }

    private function persistThreeAnswers(): QuizQuestion
    {
        $question = $this->persistQuestion();
        $question->addAnswer((new QuizAnswer())->setContent('Troisième proposition')->setPosition(2));
        $this->em->flush();

        return $question;
    }

    /** @return list<array<string, mixed>> */
    private function answerRows(QuizQuestion $question): array
    {
        return $this->em->getConnection()->fetchAllAssociative('SELECT * FROM quiz_answer WHERE question_id = ? ORDER BY position, id', [$question->getId()]);
    }

    /** @return list<string> */
    private function renderedAnswerContents(): array
    {
        return $this->client->getCrawler()->filter('[data-quiz-answer-order] textarea')->extract(['_text']);
    }

    private function persistQuestion(): QuizQuestion
    {
        $question = QuizFactory::question();
        $this->em->persist($question->getQuiz());
        $this->em->flush();

        return $question;
    }

    /** @return array{quiz: string, title: string, text: string, explanation: string, multiple: string, theme: string, position: string, answers: array<int, array<string, string>>} */
    private function questionPayload(Quiz $quiz): array
    {
        return [
            'quiz' => (string) $quiz->getId(), 'title' => 'Repérez les mots demandés.',
            'text' => 'Le chat dort.', 'explanation' => 'Explication.', 'multiple' => '0', 'theme' => 'Noms', 'position' => '0',
            'answers' => [
                ['content' => 'chat', 'correct' => '1'],
                ['content' => 'dort'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<int>|null       $answerOrder display order of the original form keys, as transmitted by the controller
     */
    private function submit(string $url, string $name, array $payload, ?array $answerOrder = null, bool $normalizeOrder = true): void
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="'.$name.'"]')->form();
        $values = $form->getPhpValues();
        $values['ea']['newForm']['btn'] = 'saveAndReturn';
        if ('QuizQuestion' === $name && isset($payload['answers']) && $normalizeOrder) {
            foreach ($answerOrder ?? array_keys($payload['answers']) as $position => $key) {
                $payload['answers'][$key]['position'] = (string) $position;
            }
        }
        $values[$name] = array_replace($values[$name], $payload);
        $this->client->request('POST', $form->getUri(), $values);
    }
}
