<?php

// Local manual fixture only. Never run this router on a public interface.
if ('cli-server' !== PHP_SAPI || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
$retouches = '1' === getenv('QUIZ_HISTORY_RETOUCHES');
$fixtureName = $retouches ? 'quiz-history-retouches' : 'quiz-history-browser';
$database = str_replace('\\', '/', $project).'/var/'.$fixtureName.'.sqlite';
$initialize = !is_file($database);
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$database;
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$static = realpath($project.'/public'.$path);
if ($static && str_starts_with($static, realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($static)) {
    return false;
}
$kernel = new App\Kernel('test', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
if (!$em instanceof Doctrine\ORM\EntityManagerInterface) {
    throw new LogicException('Doctrine ORM requis.');
}
if ($em->getConnection()->getParams()['path'] !== $database) {
    throw new LogicException('Not isolated');
}
if ($initialize) {
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    [$user, $course] = App\Tests\Support\QuizPlayerFactory::create($em);
    $secondSection = $course->getSection();
    if ($retouches) {
        $course->getSection()->setName('Les fondamentaux')->setPosition(0);
        $secondSection = (new App\Entity\Sections())->setName('Pour aller plus loin')->setSlug('plus-loin')->setProgram($course->getSection()->getProgram())->setPosition(1);
        $em->persist($secondSection);
    }
    $quiz = $course->getQuiz()->setTitle('Les accords dans le groupe nominal');
    for ($position = 2; $position < 10; ++$position) {
        $quiz->addQuestion(App\Tests\Support\QuizFactory::question()->setPosition($position));
    }
    $em->flush();
    $snapshot = ['version' => 1, 'title' => $quiz->getTitle(), 'questions' => []];
    foreach ($quiz->getQuestions() as $question) {
        $answers = [];
        foreach ($question->getAnswers() as $answer) {
            $answers[] = ['id' => $answer->getId(), 'content' => $answer->getContent(), 'correct' => $answer->isCorrect()];
        }
        $snapshot['questions'][] = ['id' => $question->getId(), 'title' => $question->getTitle(), 'text' => $question->getText(), 'multiple' => $question->isMultiple(), 'theme' => $question->getTheme(), 'explanation' => $question->getExplanation(), 'answers' => $answers];
    }
    $series = [[4, 6, 5, 7, 9], [4, 6, 5], [0]];
    if ($retouches) {
        $series = [...$series, [10, 10], [10, 5], [0, ...array_fill(0, 38, 5), 10]];
    }
    foreach ($series as $index => $scores) {
        $target = 0 === $index ? $course : (new App\Entity\Courses())->setName('Test '.($index + 1))->setSlug('test-'.($index + 1))->setSection($course->getSection())->setContentType(App\Enum\CourseContentType::Quiz)->setQuiz($quiz)->setPosition($index);
        if ($retouches && $index >= 2) {
            $target->setSection($secondSection);
        }
        $em->persist($target);
        $frozen = $snapshot;
        $frozen['title'] = ['Les accords dans le groupe nominal', 'Un test dont le contenu a changé', 'Une première tentative', 'Des résultats stables', 'Une baisse à observer', 'Un historique dense'][$index];
        foreach ($scores as $number => $score) {
            if (1 === $index && 2 === $number) {
                $frozen['questions'][0]['explanation'] = 'Une correction éditoriale différente.';
            }
            $attempt = new App\Entity\QuizAttempt($user, $target, $quiz, $frozen);
            foreach ($frozen['questions'] as $i => $question) {
                $correct = $i < $score;
                $selected = array_column(array_filter($question['answers'], static fn ($a) => $a['correct'] === $correct), 'id');
                $attempt->record($question['multiple'] ? $selected : array_slice($selected, 0, 1), $correct);
            }
            $attempt->complete();
            (new ReflectionProperty(App\Entity\QuizAttempt::class, 'completedAt'))->setValue($attempt,
                (new DateTimeImmutable('2026-09-18 10:00:00'))->modify('+'.$number.' minutes'));
            $em->persist($attempt);
        }
        if (0 === $index) {
            $em->persist(new App\Entity\QuizAttempt($user, $target, $quiz, $snapshot));
        }
    }
    $em->flush();
}
$jarPath = $project.'/var/'.$fixtureName.'.cookies';
$jar = file_exists($jarPath) ? unserialize(file_get_contents($jarPath)) : new Symfony\Component\BrowserKit\CookieJar();
$client = new Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel, [], null, $jar);
$client->disableReboot();
if (!file_exists($jarPath)) {
    $client->loginUser($em->find(App\Entity\User::class, 1));
}
$headers = array_filter($_SERVER, static fn ($key) => str_starts_with($key, 'HTTP_') || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH']), ARRAY_FILTER_USE_KEY);
$client->request($_SERVER['REQUEST_METHOD'], 'http://127.0.0.1:8803'.$_SERVER['REQUEST_URI'], $_POST, [], $headers, file_get_contents('php://input'));
$response = $client->getResponse();
file_put_contents($jarPath, serialize($client->getCookieJar()));
http_response_code($response->getStatusCode());
foreach ($response->headers->all() as $name => $values) {
    foreach ($values as $value) {
        header($name.': '.$value, false);
    }
}
$response->sendContent();
