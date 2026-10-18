<?php

// Launched only by serve_dictation_lot6_browser.php --manual.
if ('cli' !== PHP_SAPI || '--manual' !== ($argv[1] ?? null)) {
    exit("Mode manuel explicite requis.\n");
}
$socket = @stream_socket_client('tcp://127.0.0.1:8815', $code, $message, 1);
if (false !== $socket) {
    fclose($socket);
    throw new LogicException('Port 8815 occupé : aucun serveur extérieur ne sera arrêté.');
}
// Never inherit an actual media directory, even if supplied by the parent shell.
unset($_SERVER['DICTATION_STORAGE_DIR'], $_ENV['DICTATION_STORAGE_DIR']);
require dirname(__DIR__).'/bootstrap.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['DICTATION_LEARNER_ENABLED'] = $_ENV['DICTATION_LEARNER_ENABLED'] = '0';
$sessionId = bin2hex(random_bytes(8));
$project = dirname(__DIR__, 2);
$stop = $project.'/var/dictation-6-manual-'.$sessionId.'.stop';
$manifestPath = $project.'/var/dictation-6-manual-'.$sessionId.'.json';
$database = new App\Tests\Support\DictationTestDatabase();
$server = null;
$kernel = null;
$em = null;
$manifest = null;
try {
    // A live SQL check, not merely a comparison of configured connection parameters.
    $probe = $database->manager();
    if ($probe->getConnection()->fetchOne('SELECT DATABASE()') !== $database->name) {
        throw new LogicException('Connexion effective non isolée avant installation.');
    }
    $database->install();
    $legacyManager = $database->manager();
    if ($legacyManager->getConnection()->fetchOne('SELECT DATABASE()') !== $database->name) {
        throw new LogicException('Connexion effective non isolée avant création de compte.');
    }
    $legacy = App\Tests\Support\QuizPlayerFactory::user('ancien-lot6@example.test')->setRoles(['ROLE_USER'])->setFirstname('Ancien')->setLastname('RECETTE');
    $legacySubscription = (new App\Entity\Subscription())->setEmail($legacy->getEmail())->setStatus(App\Enum\SubscriptionStatus::ACTIVE)->setIsLifetime(true);
    $legacy->addSubscription($legacySubscription);
    $legacyManager->persist($legacy);
    $legacyManager->persist($legacySubscription);
    $legacyManager->flush();
    if (null !== $legacy->getDictationPrerequisites()) {
        throw new LogicException('Le compte ancien créé avant activation doit être exempté.');
    }

    // A fresh kernel binds both repositories and the real listener to the isolated DB and enabled flag.
    $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $database->kernelUrl();
    $_SERVER['DICTATION_LEARNER_ENABLED'] = $_ENV['DICTATION_LEARNER_ENABLED'] = '1';
    $kernel = new App\Kernel('test', true);
    $kernel->boot();
    $em = $kernel->getContainer()->get('doctrine')->getManager();
    if (!$em instanceof Doctrine\ORM\EntityManagerInterface || $em->getConnection()->fetchOne('SELECT DATABASE()') !== $database->name) {
        throw new LogicException('Connexion effective non isolée avant publication synthétique.');
    }
    $program = (new App\Entity\Program())->setName('Recette isolée — dictées lot 6')->setSlug('formation-en-orthographe')->setDescription('Formation synthétique réservée à cette recette.')->setPrice(0);
    $foundation = (new App\Entity\Sections())->setName('Fondamentaux sans dictée')->setSlug('fondamentaux')->setProgram($program)->setPosition(0);
    $section = (new App\Entity\Sections())->setName('Dictée et deux cours')->setSlug('dictee')->setProgram($program)->setPosition(1);
    $empty = (new App\Entity\Sections())->setName('Section initialement vide')->setSlug('section-vide')->setProgram($program)->setPosition(2);
    $course = static fn ($section, $name, $slug, $position, $free = false) => (new App\Entity\Courses())->setSection($section)->setName($name)->setSlug($slug)->setPosition($position)->setIsFree($free)->setPartialFileName('manual-lot6.html.twig');
    foreach ([$program, $foundation, $section, $empty,
        $course($foundation, 'Fondamentaux de recette', 'base', 0),
        $course($section, 'Cours privé de recette', 'prive', 0),
        $course($section, 'Cours gratuit de recette', 'gratuit', 1, true)] as $entity) {
        $em->persist($entity);
    }
    $reference = 'L’école ouvre ses portes aux enfants chaque lundi matin.';
    $faulty = 'L’ecole ouvre ses porte aux enfants chaque lundi matin.';
    $engine = new App\Services\Dictation\DictationCorrector();
    $dictations = [];
    foreach ([$section, $empty] as $targetSection) {
        $dictation = new App\Entity\Dictation($targetSection);
        $version = new App\Entity\DictationVersion($dictation, 1, 'Dictée synthétique — '.$targetSection->getName(), $reference, 'Explication synthétique : « école » prend un accent aigu ; « ses portes » est au pluriel.');
        App\Tests\Support\DictationAudioFixture::prepare($version);
        $version->publish($engine, App\Tests\Support\DictationAudioFixture::storage());
        $em->persist($dictation);
        $em->persist($version);
        $dictations[] = $dictation;
    }
    $em->flush();
    $new = App\Tests\Support\QuizPlayerFactory::user('nouveau-lot6@example.test')->setRoles(['ROLE_USER'])->setFirstname('Nouveau')->setLastname('RECETTE');
    $subscription = (new App\Entity\Subscription())->setEmail($new->getEmail())->setStatus(App\Enum\SubscriptionStatus::ACTIVE)->setIsLifetime(true);
    $new->addSubscription($subscription);
    $em->persist($new); // Actual enabled prePersist listener, after both publications are committed.
    $em->persist($subscription);
    $em->flush();
    foreach ($dictations as $dictation) {
        if (!in_array($dictation->getId(), $new->getDictationPrerequisites() ?? [], true)) {
            throw new LogicException('Inscription automatique réelle manquante.');
        }
    }
    if (0 !== (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM dictation_attempt')
        || 0 !== (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM lesson')) {
        throw new LogicException('La préparation doit rester sans tentative ni Lesson.');
    }
    mkdir($_SERVER['COURSE_STORAGE_DIR'].'/files');
    mkdir($_SERVER['DICTATION_STORAGE_DIR'].'/sessions');
    file_put_contents($_SERVER['COURSE_STORAGE_DIR'].'/files/manual-lot6.html.twig', '<h2>Cours synthétique</h2><p>Ce contenu appartient uniquement à la recette isolée. Utilisez « Valider » pour valider ce cours.</p>');
    $deadline = time() + 45 * 60;
    $manifest = [
        'session' => $sessionId, 'database' => $database->name, 'status' => 'ready',
        'startUrl' => 'http://127.0.0.1:8815/__recette-lot6/'.$sessionId,
        'summaryUrl' => '/courses/formation-en-orthographe',
        'expiresAt' => $deadline, 'expiresParis' => (new DateTimeImmutable('@'.$deadline))->setTimezone(new DateTimeZone('Europe/Paris'))->format('Y-m-d H:i:s T'),
        'stop' => $stop, 'dictationStorage' => $_SERVER['DICTATION_STORAGE_DIR'], 'courseStorage' => $_SERVER['COURSE_STORAGE_DIR'],
        'users' => ['new' => $new->getId(), 'legacy' => $legacy->getId()], 'emptySection' => $empty->getId(),
        'reference' => $reference, 'faultyCopy' => $faulty,
        'expectedFaulty' => $engine->correct($reference, $faulty)->publicFeedback(),
        'expectedPerfect' => $engine->correct($reference, $reference)->publicFeedback(),
    ];
    file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $server = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', '-S', '127.0.0.1:8815', '-t', 'public', 'tests/manual/dictation_lot6_manual_router.php'], [
        0 => ['pipe', 'r'], 1 => ['file', $_SERVER['DICTATION_STORAGE_DIR'].'/browser.stdout', 'a'], 2 => ['file', $_SERVER['DICTATION_STORAGE_DIR'].'/browser.stderr', 'a'],
    ], $pipes, $project, array_merge(getenv(), [
        'APP_ENV' => 'test', 'APP_DEBUG' => '1', 'DICTATION_LEARNER_ENABLED' => '1', 'DATABASE_URL' => $database->kernelUrl(),
        'DICTATION_BROWSER_DB' => $database->name, 'DICTATION_MANUAL_SESSION' => $sessionId, 'DICTATION_MANUAL_MANIFEST' => $manifestPath,
        'DICTATION_MANUAL_TOKEN' => bin2hex(random_bytes(32)),
        'DICTATION_STORAGE_DIR' => $_SERVER['DICTATION_STORAGE_DIR'], 'COURSE_STORAGE_DIR' => $_SERVER['COURSE_STORAGE_DIR'],
    ]), ['bypass_shell' => true]);
    if (!is_resource($server)) {
        throw new RuntimeException('Impossible de lancer le serveur isolé.');
    }
    fclose($pipes[0]);
    echo 'READY '.$manifest['startUrl'].PHP_EOL;
    echo 'Manifest: '.$manifestPath.PHP_EOL;
    echo 'Expiration Paris: '.$manifest['expiresParis'].PHP_EOL;
    echo 'Stop: '.$stop.PHP_EOL;
    echo "Verified: 0 attempts, 0 lessons; no workflow transition executed.\n";
    while (!file_exists($stop) && time() < $deadline && proc_get_status($server)['running']) {
        usleep(100000);
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $em?->getConnection()->close();
    $kernel?->shutdown();
    $database->close();
    foreach ([$_SERVER['DICTATION_STORAGE_DIR'], $_SERVER['COURSE_STORAGE_DIR']] as $root) {
        $resolved = realpath($root);
        if (false === $resolved || dirname($resolved) !== realpath(sys_get_temp_dir())
            || !preg_match('/^orthogram-(dictation|course)-tests-[a-f0-9]{20}$/D', basename($resolved))) {
            throw new LogicException('Nettoyage refusé hors des stockages jetables de cette session.');
        }
        (new Symfony\Component\Filesystem\Filesystem())->remove($resolved);
    }
    if (file_exists($stop)) {
        unlink($stop);
    }
    if (null !== $manifest) {
        $manifest['status'] = 'cleaned';
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    echo "CLEANED isolated database and both session-specific storages.\n";
}
