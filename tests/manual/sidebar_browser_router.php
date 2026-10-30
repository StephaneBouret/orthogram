<?php

// Local browser fixture only: a new SQLite database under var/ for every run.
if ('cli-server' !== PHP_SAPI || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
$run = getenv('SIDEBAR_BROWSER_RUN');
if (!is_string($run) || !preg_match('/^sidebar-browser-[a-f0-9]+$/D', $run)) {
    throw new LogicException('An isolated browser run is required.');
}
$root = str_replace('\\', '/', $project).'/var/'.$run;
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$database = $root.'/fixture.sqlite';
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
$connection = $em->getConnection();
if ('pdo_sqlite' !== $connection->getParams()['driver'] || $database !== str_replace('\\', '/', $connection->fetchAllAssociative('PRAGMA database_list')[0]['file'])) {
    throw new LogicException('Isolated DB required.');
}
if ($initialize) {
    (new Doctrine\ORM\Tools\SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    [$user, $quizCourse] = App\Tests\Support\QuizPlayerFactory::create($em);
    $quizCourse->getSection()->setPosition(4);
    $program = $quizCourse->getSection()->getProgram();
    foreach (['intro' => 0, 'empty' => 1, 'done' => 2, 'exercise' => 3, 'last' => 5] as $slug => $position) {
        $section = (new App\Entity\Sections())->setName($slug)->setSlug($slug)->setPosition($position)->setProgram($program);
        $em->persist($section);
        foreach ('empty' === $slug ? [] : ('intro' === $slug ? ['first', 'second'] : [$slug]) as $courseSlug) {
            $course = (new App\Entity\Courses())->setName($courseSlug)->setSlug($courseSlug)->setSection($section)->setPosition('second' === $courseSlug ? 1 : 0);
            $course->setIsFree('first' === $courseSlug);
            $em->persist($course);
            if ('done' === $slug) {
                $em->persist((new App\Entity\Lesson())->setUser($user)->setCourse($course)->setName($slug)->setStatus(App\Enum\LessonStatus::DONE));
            }
            if ('exercise' === $slug) {
                $exercise = (new App\Entity\Exercice())->setTitle('Le verbe')->setInstruction('Sélectionnez le verbe.')->setSentences([
                    ['id' => 's1', 'words' => [['id' => 'yes', 'text' => 'Chante', 'isAnswer' => true, 'explanation' => 'Chante est un verbe.'], ['id' => 'no', 'text' => 'la']]],
                ]);
                $em->persist($exercise);
                $course->setContentType(App\Enum\CourseContentType::Exercise)->setExercice($exercise);
            }
        }
    }
    $em->flush();
    $em->clear();
}
$anonymous = '1' === ($_GET['anonymous'] ?? null);
$jarPath = $root.($anonymous ? '/anonymous.cookies' : '/browser.cookies');
$jar = is_file($jarPath) ? unserialize(file_get_contents($jarPath)) : new Symfony\Component\BrowserKit\CookieJar();
$client = new Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel, [], null, $jar);
$client->disableReboot();
if (!$anonymous && !is_file($jarPath)) {
    $client->loginUser($em->getRepository(App\Entity\User::class)->findOneBy(['email' => 'quiz-player@example.test']));
}
$headers = array_filter($_SERVER, static fn ($key) => (str_starts_with($key, 'HTTP_') && 'HTTP_COOKIE' !== $key) || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true), ARRAY_FILTER_USE_KEY);
$client->request($_SERVER['REQUEST_METHOD'], 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'], $_POST, [], $headers, file_get_contents('php://input'));
$response = $client->getResponse();
file_put_contents($jarPath, serialize($client->getCookieJar()));
http_response_code($response->getStatusCode());
foreach ($response->headers->all() as $name => $values) {
    if ('set-cookie' !== $name) {
        foreach ($values as $value) {
            header($name.': '.$value, false);
        }
    }
}
echo $client->getInternalResponse()->getContent();
