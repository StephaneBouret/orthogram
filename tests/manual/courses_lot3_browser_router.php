<?php

// Disposable local browser fixtures. Never serve this router on a public interface.
if ('cli-server' !== PHP_SAPI || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}

use App\Entity\Comment;
use App\Entity\Courses;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SubscriptionStatus;
use App\Enum\UserAccountStatus;
use App\Tests\Support\QuizPlayerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorToken;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\CookieJar;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
$root = str_replace('\\', '/', $project).'/var/courses-lot3-browser';
if (!is_dir($root.'/files')) {
    mkdir($root.'/files', 0700, true);
}
$database = $root.'/fixture.sqlite';
$initialize = !is_file($database);
$_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///'.$database;
$_ENV['COURSE_STORAGE_DIR'] = $_SERVER['COURSE_STORAGE_DIR'] = $root;
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'test';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$static = realpath($project.'/public'.$path);
if (preg_match('#^/(assets|favicon|img)/#', $path) && $static
    && str_starts_with($static, realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($static)) {
    return false;
}
$kernel = new App\Kernel('test', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
if (!$em instanceof EntityManagerInterface || 'pdo_sqlite' !== $em->getConnection()->getParams()['driver']
    || $database !== $em->getConnection()->getParams()['path']) {
    throw new LogicException('Isolated SQLite fixtures required.');
}
if ($initialize) {
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    [$admin, $quiz] = QuizPlayerFactory::create($em);
    $admin->setEmail('admin@lot3.example.test');
    $section = $quiz->getSection();
    $section->getProgram()->setSlug('formation-en-orthographe')->setName('Formation en orthographe');
    $quiz->setPosition(2)->setIsFree(true);
    $free = (new Courses())->setName('Lecture gratuite')->setSlug('lecture-gratuite')->setSection($section)
        ->setPosition(1)->setIsFree(true)->setPartialFileName('fixture.html.twig');
    $paid = (new Courses())->setName('Cours réservé')->setSlug('cours-reserve')->setSection($section)
        ->setPosition(0)->setShortDescription('SECRET contenu réservé');
    $comment = (new Comment())->setUser($admin)->setCourse($free)->setContent('SECRET commentaire');
    foreach ([$free, $paid, $comment] as $entity) {
        $em->persist($entity);
    }
    foreach (['unsubscribed', 'expired', 'subscriber', 'inactive', '2fa', 'remember-me'] as $profile) {
        $user = QuizPlayerFactory::user($profile.'@lot3.example.test')->setRoles([]);
        if ('inactive' === $profile) {
            $user->setAccountStatus(UserAccountStatus::INACTIVE);
        }
        $em->persist($user);
        if (in_array($profile, ['expired', 'subscriber'], true)) {
            $subscription = (new Subscription())->setUser($user)->setEmail($user->getEmail())
                ->setStatus(SubscriptionStatus::ACTIVE)->setStartsAt(new DateTimeImmutable('-2 days'))
                ->setEndsAt(new DateTimeImmutable('expired' === $profile ? '-1 day' : '+1 year'));
            $em->persist($subscription);
        }
    }
    $em->flush();
    file_put_contents($root.'/files/fixture.html.twig', '<div class="text-longform"><h2>Une lecture ouverte</h2><p>CONTENU GRATUIT — {{ course.name }}</p></div>');
}
$em->clear();
if (preg_match('#^/__lot3/profile/(anonymous|unsubscribed|expired|subscriber|admin|inactive|2fa|remember-me)$#', $path, $match)) {
    setcookie('lot3_profile', $match[1], ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    setcookie('lot3_session', bin2hex(random_bytes(16)), ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: /courses/formation-en-orthographe');
    exit;
}
if ('POST' === $_SERVER['REQUEST_METHOD'] && '/__lot3/free' === $path) {
    $em->getRepository(Courses::class)->findOneBy(['slug' => 'lecture-gratuite'])->setIsFree('1' === ($_POST['value'] ?? ''));
    $em->flush();
    header('Location: /courses/formation-en-orthographe');
    exit;
}
$profile = $_COOKIE['lot3_profile'] ?? 'anonymous';
$jarPath = $root.'/'.hash('sha256', $profile.'|'.($_COOKIE['lot3_session'] ?? '')).'.cookies';
$jar = is_file($jarPath) ? unserialize(file_get_contents($jarPath)) : new CookieJar();
$client = new KernelBrowser($kernel, [], null, $jar);
$client->disableReboot();
if (!is_file($jarPath) && 'anonymous' !== $profile && $user = $em->getRepository(User::class)->findOneBy(['email' => $profile.'@lot3.example.test'])) {
    $client->loginUser($user);
    if (in_array($profile, ['2fa', 'remember-me'], true)) {
        $token = '2fa' === $profile
            ? new TwoFactorToken(new UsernamePasswordToken($user, 'main', $user->getRoles()), null, 'main', ['email'])
            : new RememberMeToken($user, 'main');
        $session = $client->getSession();
        $session->set('_security_main', serialize($token));
        $session->save();
    }
}
$headers = array_filter($_SERVER, static fn ($key) => (str_starts_with($key, 'HTTP_') && 'HTTP_COOKIE' !== $key)
    || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true), ARRAY_FILTER_USE_KEY);
$client->request($_SERVER['REQUEST_METHOD'], 'http://127.0.0.1:8813'.$_SERVER['REQUEST_URI'], $_POST, [], $headers, file_get_contents('php://input'));
$response = $client->getResponse();
file_put_contents($jarPath, serialize($client->getCookieJar()));
http_response_code($response->getStatusCode());
foreach ($response->headers->all() as $name => $values) {
    if ('set-cookie' === $name) {
        continue;
    }
    foreach ($values as $value) {
        header($name.': '.$value, false);
    }
}
echo $client->getInternalResponse()->getContent();
