<?php

declare(strict_types=1);

// Isolated prod cache proof. No real env is loaded; every DB connection throws.
require dirname(__DIR__, 2).'/vendor/autoload.php';

final class DeploymentNoDatabaseDriver extends Doctrine\DBAL\Driver\AbstractMySQLDriver
{
    public function connect(array $params): Doctrine\DBAL\Driver\Connection
    {
        throw new RuntimeException('A pre-migration command tried to connect to the database.');
    }
}

$repo = dirname(__DIR__, 2);
$fixture = $repo.'/var/beta-cache-test-'.bin2hex(random_bytes(6));
$fs = new Symfony\Component\Filesystem\Filesystem();
$fs->mkdir($fixture);
foreach (['config', 'src', 'templates', 'translations', 'assets', 'public/bundles'] as $directory) {
    if (is_dir($repo.'/'.$directory)) {
        $fs->mirror($repo.'/'.$directory, $fixture.'/'.$directory);
    }
}
foreach (['composer.json', 'composer.lock', 'importmap.php'] as $file) {
    $fs->copy($repo.'/'.$file, $fixture.'/'.$file);
}
// Dictation storage deliberately does not exist: cache/container must not access it.
$fs->mkdir([$fixture.'/private/courses', $fixture.'/vendor']);
// Use installed dependencies, but load application classes from the isolated copy.
$autoload = '<?php require '.var_export($repo.'/vendor/autoload.php', true).';'.PHP_EOL;
$autoload .= 'spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, "App\\\\")) {
        $file = dirname(__DIR__)."/src/".str_replace("\\\\", "/", substr($class, 4)).".php";
        if (is_file($file)) { require $file; }
    }
}, true, true);'.PHP_EOL;
file_put_contents($fixture.'/vendor/autoload.php', $autoload);
require $fixture.'/vendor/autoload.php';
file_put_contents($fixture.'/config/packages/deploy_test.yaml', "doctrine:\n    dbal:\n        driver_class: DeploymentNoDatabaseDriver\n");
$values = [
    'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_SECRET' => 'isolated-test',
    'DATABASE_URL' => 'mysql://unused:unused@127.0.0.1:1/no_database?serverVersion=8.4.7&charset=utf8mb4',
    'COURSE_STORAGE_DIR' => $fixture.'/private/courses',
    'DICTATION_STORAGE_DIR' => $fixture.'/private/dictations', 'DICTATION_LEARNER_ENABLED' => '0',
    'ORTHOGRAM_DICTATION_WEB_DEFERRED' => '1',
    'APP_SHARE_DIR' => $fixture.'/var/share', 'DEFAULT_URI' => 'https://example.invalid',
    'APP_HOSTNAME' => 'example.invalid', 'APP_SUPPORT_EMAIL' => 'test@example.invalid',
    'MAILER_DEFAULT_FROM' => 'test@example.invalid', 'MAILER_DSN' => 'null://null',
    'MESSENGER_TRANSPORT_DSN' => 'in-memory://', 'STRIPE_PUBLIC_KEY' => 'pk_test_fake',
    'STRIPE_SECRET_KEY' => 'sk_test_fake', 'STRIPE_WEBHOOK_SECRET' => 'test-fake', 'GOOGLE_API_KEY' => 'test-fake',
];
foreach ($values as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key.'='.$value);
}
$dotenvText = '';
foreach ($values as $key => $value) {
    $dotenvText .= $key.'='.$value.PHP_EOL;
}
file_put_contents($fixture.'/.env', $dotenvText);
file_put_contents($fixture.'/.env.local', $dotenvText);
file_put_contents($fixture.'/.env.beta', $dotenvText);
file_put_contents($fixture.'/.env.local.php', '<?php return '.var_export($values, true).';');
chdir($fixture);
foreach (['cache:clear', 'cache:warmup'] as $command) {
    $kernel = new App\Kernel('prod', false);
    if (realpath($kernel->getProjectDir()) !== realpath($fixture)) {
        throw new RuntimeException('Refusing a non-isolated kernel');
    }
    $application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    $status = $application->run(new Symfony\Component\Console\Input\ArrayInput([
        'command' => $command, '--env' => 'prod', '--no-debug' => true,
    ]));
    if (0 !== $status) {
        exit($status);
    }
    $kernel->shutdown();
}
// Exercise the real post-cache checker against this same isolated kernel.
$argv = ['check', 'container', $fixture.'/.env.beta', $values['COURSE_STORAGE_DIR'], $values['DICTATION_STORAGE_DIR']];
$argc = count($argv);
require $repo.'/ops/check_beta_environment.php';
if (file_exists($values['DICTATION_STORAGE_DIR'])) {
    throw new RuntimeException('Cache/container unexpectedly created dictation storage');
}
echo 'PASS prod caches + effective container; DB connections forbidden, dictation storage absent. Fixture: '.$fixture.PHP_EOL;
