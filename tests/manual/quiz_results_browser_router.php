<?php

// Local manual fixture only. Never run this router on a public interface.
if ('cli-server' !== PHP_SAPI || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
$database = str_replace('\\', '/', $project).'/var/quiz-results-browser.sqlite';
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
    $quiz = $course->getQuiz()->setTitle('Les accords dans le groupe nominal');
    $snapshot = ['version' => 1, 'title' => $quiz->getTitle(), 'questions' => []];
    foreach ($quiz->getQuestions() as $question) {
        $answers = [];
        foreach ($question->getAnswers() as $answer) {
            $answers[] = ['id' => $answer->getId(), 'content' => $answer->getContent(), 'correct' => $answer->isCorrect()];
        }
        $snapshot['questions'][] = ['id' => $question->getId(), 'title' => $question->getTitle(), 'text' => $question->getText(), 'multiple' => $question->isMultiple(), 'theme' => $question->getTheme(), 'explanation' => $question->getExplanation(), 'answers' => $answers];
    }
    foreach ([0, 1, 2, null, null] as $index => $score) {
        $target = 0 === $index ? $course : (new App\Entity\Courses())->setName('Test '.($index + 1))->setSlug('test-'.($index + 1))->setSection($course->getSection())->setContentType(App\Enum\CourseContentType::Quiz)->setQuiz($quiz)->setPosition($index);
        $em->persist($target);
        if (4 === $index) {
            continue;
        }
        $frozen = $snapshot;
        $frozen['title'] = ['Les accords dans le groupe nominal', 'Les homophones grammaticaux', 'L’accord du participe passé', 'Les mots invariables'][$index];
        $attempt = new App\Entity\QuizAttempt($user, $target, $quiz, $frozen);
        if (null !== $score) {
            foreach ($frozen['questions'] as $i => $question) {
                $attempt->record([$question['answers'][0]['id']], $i < $score);
            }
            $attempt->complete();
        }
        $em->persist($attempt);
    }
    $em->flush();
}
$jarPath = $project.'/var/quiz-results-browser.cookies';
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
