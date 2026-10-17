<?php

// Local isolated fixture router. No public deployment or development database.
if ('cli-server' !== PHP_SAPI || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit;
}
$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
foreach (['DATABASE_URL', 'DICTATION_STORAGE_DIR', 'COURSE_STORAGE_DIR'] as $key) {
    $_SERVER[$key] = $_ENV[$key] = getenv($key);
}
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$database = getenv('DICTATION_BROWSER_DB');
if (!is_string($database) || 1 !== preg_match('/^orthogram_dictation_2a_[a-f0-9]{16}_test$/D', $database)) {
    throw new LogicException('Base isolée requise.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$static = realpath($project.'/public'.$path);
if ($static && str_starts_with($static, realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($static)) {
    return false;
}
$kernel = new App\Kernel('test', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
if ($em->getConnection()->getDatabase() !== $database) {
    throw new LogicException('Connexion non isolée.');
}
$jarPath = getenv('DICTATION_STORAGE_DIR').'/browser.cookies';
$jar = file_exists($jarPath) ? unserialize(file_get_contents($jarPath)) : new Symfony\Component\BrowserKit\CookieJar();
$client = new Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel, [], null, $jar);
$client->disableReboot();
if (!file_exists($jarPath)) {
    $client->loginUser($em->find(App\Entity\User::class, (int) getenv('DICTATION_BROWSER_USER')));
}
$headers = array_filter($_SERVER, static fn ($key) => str_starts_with($key, 'HTTP_') || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH']), ARRAY_FILTER_USE_KEY);
$files = Symfony\Component\HttpFoundation\Request::createFromGlobals()->files->all();
$client->request($_SERVER['REQUEST_METHOD'], 'http://127.0.0.1:8815'.$_SERVER['REQUEST_URI'], $_POST, $files, $headers, file_get_contents('php://input'));
$response = $client->getResponse();
file_put_contents($jarPath, serialize($client->getCookieJar()));
http_response_code($response->getStatusCode());
foreach ($response->headers->all() as $name => $values) {
    foreach ($values as $value) {
        header($name.': '.$value, false);
    }
}
// BrowserKit has already consumed BinaryFileResponse. Forward exactly the range it received.
echo $response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse ? $client->getInternalResponse()->getContent() : $response->getContent();
