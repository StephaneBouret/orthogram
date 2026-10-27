<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$url = getenv('DATABASE_URL');
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');
$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $url;
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$destination = $container->get(App\Services\ModuleTwoQuizDestination::class);
$context = $destination->verify($em->getConnection(), ['expect-database' => $argv[1]]);
if (1 !== preg_match('/^orthogram_module2_[a-f0-9]{16}_test$/D', $context['database'])) {
    throw new LogicException('Base jetable module 2 requise.');
}
$importer = $container->get(App\Services\ModuleTwoQuizImporter::class);
$plan = $importer->plan('import', $context);
file_put_contents($argv[2], 'ready');
try {
    $result = $importer->apply('import', $plan['plan_hash'], $context);
    echo json_encode(['success' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (DomainException $error) {
    echo json_encode(['success' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR);
}
$kernel->shutdown();
