<?php

namespace App\Tests\Support;

use App\Entity\Courses;
use App\Entity\Program;
use App\Entity\Quiz;
use App\Entity\Sections;
use App\Entity\User;
use App\Enum\CourseContentType;
use Doctrine\ORM\EntityManagerInterface;
use libphonenumber\PhoneNumber;

final class QuizPlayerFactory
{
    /** @return array{User, Courses} */
    public static function create(EntityManagerInterface $em): array
    {
        $user = self::user('quiz-player@example.test');
        $program = (new Program())->setName('Quiz formation')->setSlug('quiz-formation')->setDescription('Test isolé')->setPrice(0);
        $section = (new Sections())->setName('Quiz section')->setSlug('quiz-section')->setProgram($program);
        $quiz = (new Quiz())->setTitle('Quiz <img src=x onerror="alert(1)">');
        $first = QuizFactory::question()->setPosition(0)->setExplanation('SECRET première correction');
        $second = QuizFactory::question(true, [true, true, false])->setPosition(1)->setExplanation('SECRET deuxième correction');
        $quiz->addQuestion($first)->addQuestion($second);
        $course = (new Courses())->setName('Quiz cours')->setSlug('quiz-cours')->setSection($section)->setContentType(CourseContentType::Quiz)->setQuiz($quiz);
        foreach ([$user, $program, $section, $quiz, $course] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        return [$user, $course];
    }

    public static function user(string $email): User
    {
        return (new User())->setEmail($email)->setPassword('test-password')
            ->setFirstname('Camille')->setLastname('Test')->setAddress('1 rue du Test')
            ->setPostalCode('75001')->setCity('Paris')
            ->setPhone((new PhoneNumber())->setCountryCode(33)->setNationalNumber('612345678'))
            ->setRoles(['ROLE_ADMIN']);
    }
}
