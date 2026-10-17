<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\DictationVersion;
use App\Services\Dictation\DictationAudioStorage;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DictationAudioFixture
{
    public static function storage(): DictationAudioStorage
    {
        if (!isset($_SERVER['DICTATION_STORAGE_DIR'])) {
            $root = sys_get_temp_dir().'/orthogram-dictation-tests-'.bin2hex(random_bytes(10));
            mkdir($root, 0700, true);
            $_SERVER['DICTATION_STORAGE_DIR'] = $_ENV['DICTATION_STORAGE_DIR'] = $root;
            register_shutdown_function(static fn () => (new Filesystem())->remove($root));
        }

        return new DictationAudioStorage($_SERVER['DICTATION_STORAGE_DIR'], dirname(__DIR__, 2).'/public');
    }

    public static function upload(): UploadedFile
    {
        return new UploadedFile(dirname(__DIR__).'/Fixtures/Dictation/tone.mp3', 'synthetic.mp3', 'audio/mpeg', test: true);
    }

    public static function longUpload(): UploadedFile
    {
        // Repeat only the audio frames of our 64 kbit/s fixture, without its ID3/Xing headers.
        // This yields a real MP3 longer than 300 seconds without an encoder dependency.
        $source = dirname(__DIR__).'/Fixtures/Dictation/tone.mp3';
        $info = (new \getID3())->analyze($source);
        $offset = $info['avdataoffset'] + (int) floor(144 * 64000 / 44100);
        $frames = substr(file_get_contents($source), $offset, $info['avdataend'] - $offset);
        $path = tempnam(sys_get_temp_dir(), 'dictation-long-');
        file_put_contents($path, str_repeat($frames, 150));
        register_shutdown_function(static fn () => unlink($path));

        return new UploadedFile($path, 'synthetic-long.mp3', 'audio/mpeg', test: true);
    }

    public static function prepare(DictationVersion $version): void
    {
        $audio = self::storage()->import(self::upload());
        $version->replaceAudio('complete', $audio);
        $version->replaceAudio('writing', $audio);
        $version->editSegments([['start' => 0, 'end' => 1000]]);
    }
}
