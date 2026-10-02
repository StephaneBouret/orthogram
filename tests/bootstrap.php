<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Every test process gets private disposable uploads, including pre-existing admin tests.
// Never inherit the development/production file storage for replacement/deletion tests.
$courseTestStorage = sys_get_temp_dir().'/orthogram-course-tests-'.bin2hex(random_bytes(10));
mkdir($courseTestStorage, 0700, true);
$_ENV['COURSE_STORAGE_DIR'] = $_SERVER['COURSE_STORAGE_DIR'] = $courseTestStorage;
register_shutdown_function(static function () use ($courseTestStorage): void {
    (new Symfony\Component\Filesystem\Filesystem())->remove($courseTestStorage);
});

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
