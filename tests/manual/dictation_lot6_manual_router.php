<?php

// Test-only identity switching and fixture controls. Never installed in the application's routes/firewall.
if ('cli-server' !== PHP_SAPI || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !preg_match('/^[a-f0-9]{16}$/D', getenv('DICTATION_MANUAL_SESSION') ?: '')) {
    http_response_code(403);
    exit;
}
$project = dirname(__DIR__, 2);
require $project.'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv($project.'/.env');
foreach (['DATABASE_URL', 'DICTATION_STORAGE_DIR', 'COURSE_STORAGE_DIR', 'DICTATION_LEARNER_ENABLED'] as $key) {
    $_SERVER[$key] = $_ENV[$key] = getenv($key);
}
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$sessionId = getenv('DICTATION_MANUAL_SESSION');
$manifest = json_decode(file_get_contents(getenv('DICTATION_MANUAL_MANIFEST')), true, 32, JSON_THROW_ON_ERROR);
$database = getenv('DICTATION_BROWSER_DB');
if ($manifest['session'] !== $sessionId || $manifest['database'] !== $database
    || !preg_match('/^orthogram_dictation_2a_[a-f0-9]{16}_test$/D', $database)
    || $manifest['dictationStorage'] !== $_SERVER['DICTATION_STORAGE_DIR'] || $manifest['courseStorage'] !== $_SERVER['COURSE_STORAGE_DIR']) {
    throw new LogicException('Configuration de recette incohérente.');
}
if ('ready' !== $manifest['status'] || time() >= $manifest['expiresAt']) {
    http_response_code(410);
    exit('Session de recette expirée.');
}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$panel = '/__recette-lot6/'.$sessionId;
$cookieName = 'lot6_account_'.$sessionId;
$account = $_COOKIE[$cookieName] ?? 'new';
if (!isset($manifest['users'][$account])) {
    http_response_code(403);
    exit;
}
$kernel = new App\Kernel('test', true);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
if (!$em instanceof Doctrine\ORM\EntityManagerInterface || $em->getConnection()->fetchOne('SELECT DATABASE()') !== $database) {
    throw new LogicException('Connexion effective non isolée : aucune écriture autorisée.');
}
// Repositories, identity sessions and fixture controls all use this verified kernel/connection.
$kernel->getContainer()->get('test.service_container')->set('session.storage.factory.mock_file',
    new Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorageFactory($_SERVER['DICTATION_STORAGE_DIR'].'/sessions'));
if ($path === $panel) {
    header('Cache-Control: private, no-store');
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Frame-Options: DENY');
    $token = getenv('DICTATION_MANUAL_TOKEN');
    if ('POST' === $_SERVER['REQUEST_METHOD']) {
        if (!is_string($_POST['token'] ?? null) || !hash_equals($token, $_POST['token'])
            || (isset($_SERVER['HTTP_ORIGIN']) && 'http://127.0.0.1:8815' !== $_SERVER['HTTP_ORIGIN'])) {
            http_response_code(403);
            exit('Commande de recette refusée.');
        }
        $action = $_POST['action'] ?? '';
        if ('switch' === $action && isset($manifest['users'][$_POST['account'] ?? ''])) {
            setcookie($cookieName, $_POST['account'], ['expires' => $manifest['expiresAt'], 'path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
        } elseif (in_array($action, ['add-first', 'add-second'], true)) {
            $em->wrapInTransaction(function () use ($em, $manifest, $action): void {
                $section = $em->find(App\Entity\Sections::class, $manifest['emptySection'], Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);
                $number = 'add-first' === $action ? 1 : 2;
                $existing = $em->getRepository(App\Entity\Courses::class)->findOneBy(['section' => $section, 'slug' => 'ajout-'.$number]);
                if (null !== $existing) {
                    return;
                }
                if (2 === $number && null === $em->getRepository(App\Entity\Courses::class)->findOneBy(['section' => $section, 'slug' => 'ajout-1'])) {
                    throw new LogicException('Ajouter le premier cours avant le second.');
                }
                $course = (new App\Entity\Courses())->setSection($section)->setName('Cours ajouté '.$number)->setSlug('ajout-'.$number)->setPosition($number)->setPartialFileName('manual-lot6.html.twig');
                $em->persist($course);
            });
        } else {
            http_response_code(400);
            exit('Action de recette inconnue.');
        }
        header('Location: '.$panel, true, 303);
        exit;
    }
    if ('GET' !== $_SERVER['REQUEST_METHOD']) {
        http_response_code(405);
        exit;
    }
    $escape = static fn ($text) => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $courseCount = (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM courses WHERE section_id = ?', [$manifest['emptySection']]);
    echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Harnais isolé — lot 6</title><style>body{font:18px system-ui;max-width:850px;margin:2rem auto;padding:1rem;line-height:1.6}button,a{padding:.5rem}form{display:inline-block;margin:.5rem}code{white-space:pre-wrap}a:focus-visible,button:focus-visible{outline:3px solid #d66b00}table{border-collapse:collapse}td,th{padding:.5rem;border:1px solid #aaa}</style><h1>Recette manuelle isolée — lot 6</h1>';
    echo '<p>Ces commandes appartiennent exclusivement au harnais jetable, pas à l’application.</p><p><strong>Compte sélectionné : '.('new' === $account ? 'Nouveau — prérequis obligatoires' : 'Ancien — exempté').'</strong></p>';
    foreach (['new' => 'Passer au nouveau compte', 'legacy' => 'Passer à l’ancien compte'] as $key => $label) {
        echo '<form method="post"><input type="hidden" name="token" value="'.$escape($token).'"><input type="hidden" name="action" value="switch"><input type="hidden" name="account" value="'.$key.'"><button>'.$label.'</button></form>';
    }
    echo '<p><a href="'.$escape($manifest['summaryUrl']).'">Ouvrir le sommaire avec ce compte</a></p><ul>';
    foreach (['fondamentaux' => 'Fondamentaux sans dictée', 'dictee/prive' => 'Cours privé direct', 'dictee/gratuit' => 'Cours gratuit direct', 'section-vide' => 'Section initialement vide'] as $slug => $label) {
        echo '<li><a href="'.$escape($manifest['summaryUrl'].'/'.$slug).'">'.$label.'</a></li>';
    }
    echo '</ul><h2>Section initialement vide</h2><p>Cours actuels : '.$courseCount.'. Aucune commande ne valide de cours ni ne crée une tentative.</p>';
    foreach (['add-first' => 'Ajouter le premier cours', 'add-second' => 'Ajouter le second cours'] as $action => $label) {
        $disabled = ('add-first' === $action && $courseCount > 0) || ('add-second' === $action && 1 !== $courseCount);
        echo '<form method="post"><input type="hidden" name="token" value="'.$escape($token).'"><input type="hidden" name="action" value="'.$action.'"><button '.($disabled ? 'disabled' : '').'>'.$label.'</button></form>';
    }
    echo '<p>Observer la section vide, puis ajouter les cours un par un. Recharger la section après chaque ajout. La reprise doit rester après le dernier cours et attendre la clôture initiale et toutes les validations.</p><h2>Comptages en lecture seule</h2><table><tr><th>Compte</th><th>Tentatives</th><th>Lesson</th></tr>';
    foreach ($manifest['users'] as $key => $userId) {
        $attempts = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM dictation_attempt WHERE user_id = ?', [$userId]);
        $lessons = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson WHERE user_id = ?', [$userId]);
        echo '<tr><td>'.$escape($key).'</td><td>'.$attempts.'</td><td>'.$lessons.'</td></tr>';
    }
    echo '</table><h2>Textes synthétiques réservés à la recette</h2><p>Référence : <code>'.$escape($manifest['reference']).'</code></p><p>Copie fautive : <code>'.$escape($manifest['faultyCopy']).'</code></p><p>Résultat calculé : '.$manifest['expectedFaulty']['score'].' %, '.$manifest['expectedFaulty']['lexicalErrorCount'].' erreurs d’orthographe, '.$manifest['expectedFaulty']['punctuationErrorCount'].' erreur de ponctuation. Les pistes sont des tonalités synthétiques, pas une voix dictant le texte.</p><p>Expiration : '.$escape($manifest['expiresParis']).'.</p><p>Retournez à cette adresse pour changer de compte. Les copies restent propres à chaque compte.</p></html>';
    exit;
}
$static = realpath($project.'/public'.$path);
if ($static && str_starts_with($static, realpath($project.'/public').DIRECTORY_SEPARATOR) && is_file($static)) {
    return false;
}
$jarPath = $_SERVER['DICTATION_STORAGE_DIR'].'/manual-'.$account.'.cookies';
$jar = file_exists($jarPath) ? unserialize(file_get_contents($jarPath), ['allowed_classes' => [Symfony\Component\BrowserKit\CookieJar::class, Symfony\Component\BrowserKit\Cookie::class]]) : new Symfony\Component\BrowserKit\CookieJar();
$client = new Symfony\Bundle\FrameworkBundle\KernelBrowser($kernel, [], null, $jar);
$client->disableReboot();
if (!file_exists($jarPath)) {
    $client->loginUser($em->find(App\Entity\User::class, $manifest['users'][$account]));
}
$headers = array_filter($_SERVER, static fn ($key) => str_starts_with($key, 'HTTP_') || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH']), ARRAY_FILTER_USE_KEY);
// Real browser cookies select the harness identity only; BrowserKit owns the isolated application session.
unset($headers['HTTP_COOKIE']);
$files = Symfony\Component\HttpFoundation\Request::createFromGlobals()->files->all();
$client->request($_SERVER['REQUEST_METHOD'], 'http://127.0.0.1:8815'.$_SERVER['REQUEST_URI'], $_POST, $files, $headers, file_get_contents('php://input'));
$response = $client->getResponse();
file_put_contents($jarPath, serialize($client->getCookieJar()));
http_response_code($response->getStatusCode());
foreach ($response->headers->all() as $name => $values) {
    foreach ($values as $value) {
        if ('set-cookie' !== strtolower($name)) {
            header($name.': '.$value, false);
        }
    }
}
echo $response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse ? $client->getInternalResponse()->getContent() : $response->getContent();
