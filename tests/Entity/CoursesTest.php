<?php

namespace App\Tests\Entity;

use App\Entity\Courses;
use App\Entity\Quiz;
use App\Enum\CourseContentType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

final class CoursesTest extends TestCase
{
    public function testNewCourseIsReserved(): void
    {
        self::assertFalse((new Courses())->isFree());
    }

    /** @return iterable<string, array{CourseContentType, bool, bool}> */
    public static function freeAccessCases(): iterable
    {
        foreach (CourseContentType::cases() as $type) {
            yield $type->value.' reserved' => [$type, false, true];
            yield $type->value.' free' => [$type, true, !in_array($type, [CourseContentType::Quiz, CourseContentType::Exercise], true)];
        }
    }

    #[DataProvider('freeAccessCases')]
    public function testFreeAccessValidation(CourseContentType $type, bool $free, bool $valid): void
    {
        $course = (new Courses())->setName('Cours de test')->setContentType($type)->setIsFree($free);
        if (CourseContentType::Quiz === $type) {
            $course->setQuiz((new Quiz())->setTitle('Quiz de test'));
        }
        $violations = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator()->validate($course);
        self::assertCount($valid ? 0 : 1, $violations);
        if (!$valid) {
            self::assertSame('isFree', $violations->get(0)->getPropertyPath());
            self::assertStringContainsString('Décochez « Accès gratuit »', (string) $violations->get(0)->getMessage());
        }
        self::assertSame($free, $course->isFree(), 'Validation must never silently change the choice.');
    }
}
