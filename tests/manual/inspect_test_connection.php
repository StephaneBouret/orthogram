<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$environment = $argv[1] ?? 'test';
if (!in_array($environment, ['dev', 'test'], true)) {
    throw new InvalidArgumentException('Usage: php tests/manual/inspect_test_connection.php [dev|test]');
}

// Match phpunit.dist.xml; keep DATABASE_URL and TEST_TOKEN overrides visible.
foreach (['DATABASE_URL', 'TEST_TOKEN', 'MAILER_DSN'] as $key) {
    printf("%s inherited: %s\n", $key, false !== getenv($key) ? 'yes' : 'no');
}
$_SERVER['APP_ENV'] = $environment;
$_SERVER['APP_DEBUG'] = '1';
if ('test' === $environment) {
    require dirname(__DIR__).'/bootstrap.php';
} else {
    (new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
}

$kernel = new Kernel($environment, true);
$kernel->boot();
try {
    foreach ($kernel->getContainer()->get('doctrine')->getConnections() as $name => $connection) {
        $params = $connection->getParams();
        // Never print credentials, a DSN, driver options or nested connection parameters.
        echo json_encode([
            'environment' => $kernel->getEnvironment(),
            'connection' => $name,
            'driver' => $params['driver'] ?? null,
            'host' => $params['host'] ?? null,
            'port' => $params['port'] ?? null,
            'database' => $params['dbname'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;

        // The dev connection is inspected without opening it.
        if ('dev' === $environment) {
            continue;
        }
        if ('pdo_mysql' !== ($params['driver'] ?? '')
            || !in_array($params['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || !str_ends_with($params['dbname'] ?? '', '_test')
            || isset($params['unix_socket']) || isset($params['primary']) || isset($params['replica'])) {
            throw new LogicException('Expected a direct local MySQL connection ending in _test. Stop before any write.');
        }
        if ($connection->fetchOne('SELECT DATABASE()') !== $params['dbname']) {
            throw new LogicException('Selected database differs from the resolved configuration.');
        }
        echo "SELECT DATABASE() confirmed; no database writes performed.\n";
    }
} finally {
    $kernel->shutdown();
}
