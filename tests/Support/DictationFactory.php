<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Courses;
use App\Entity\Dictation;
use App\Entity\DictationVersion;
use App\Entity\Program;
use App\Entity\Sections;
use App\Entity\User;
use App\Enum\CourseContentType;
use App\Services\Courses\SectionCompletionService;
use App\Services\Dictation\DictationAttemptService;
use App\Services\Dictation\DictationCorrector;
use App\Services\Dictation\DictationCourseProgress;
use App\Services\Dictation\DictationTokenizer;
use Doctrine\ORM\EntityManagerInterface;

final class DictationFactory
{
    /** Entirely synthetic editorial content; never used as production fixtures.
     * @return array{User, Dictation, DictationVersion, Courses}
     */
    public static function create(EntityManagerInterface $em): array
    {
        $suffix = bin2hex(random_bytes(6));
        $user = QuizPlayerFactory::user('dictation-'.$suffix.'@example.test')->setRoles(['ROLE_USER']);
        $program = (new Program())->setName('Test dictées')->setSlug('test-'.$suffix)->setDescription('Test isolé')->setPrice(0);
        $section = (new Sections())->setName('Section test')->setSlug('section-'.$suffix)->setProgram($program);
        $course = (new Courses())->setName('Cours requis')->setSlug('cours-'.$suffix)->setContentType(CourseContentType::Twig)->setSection($section);
        $dictation = new Dictation($section);
        $version = new DictationVersion($dictation, 1, 'Dictée synthétique', 'L’école ouvre.', 'SECRET_CORRECTION_TEST');
        DictationAudioFixture::prepare($version);
        $version->publish(new DictationCorrector(), DictationAudioFixture::storage());
        foreach ([$user, $program, $section, $course, $dictation, $version] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$user, $dictation, $version, $course];
    }

    public static function service(EntityManagerInterface $em): DictationAttemptService
    {
        return new DictationAttemptService($em, new DictationCourseProgress($em, new SectionCompletionService()), new DictationCorrector(), new DictationTokenizer());
    }
}
