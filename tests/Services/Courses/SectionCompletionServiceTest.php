<?php

namespace App\Tests\Services\Courses;

use App\Entity\Courses;
use App\Entity\Sections;
use App\Enum\CourseContentType;
use App\Services\Courses\SectionCompletionService;
use PHPUnit\Framework\TestCase;

final class SectionCompletionServiceTest extends TestCase
{
    public function testEmptyIncompleteCompleteAndNewCourse(): void
    {
        $service = new SectionCompletionService();
        $section = new Sections();
        (new \ReflectionProperty(Sections::class, 'id'))->setValue($section, 10);
        self::assertSame([10 => false], $service->calculate([$section], []));

        // Every existing content type counts, regardless of position or access.
        foreach (CourseContentType::cases() as $index => $type) {
            $course = (new Courses())->setContentType($type)->setPosition(10 - $index);
            (new \ReflectionProperty(Courses::class, 'id'))->setValue($course, $index + 1);
            $section->addCourse($course);
        }
        self::assertSame([10 => false], $service->calculate([$section], [6, 5, 4, 3, 2]));
        self::assertSame([10 => true], $service->calculate([$section], [6, 5, 4, 3, 2, 1, 1, 999]));
        self::assertSame([10 => false], $service->calculate([$section], []));

        $newCourse = new Courses();
        (new \ReflectionProperty(Courses::class, 'id'))->setValue($newCourse, 7);
        $section->addCourse($newCourse);
        self::assertSame([10 => false], $service->calculate([$section], [1, 2, 3, 4, 5, 6]));
    }

    public function testSectionsAreEvaluatedIndependently(): void
    {
        $sections = [];
        foreach ([10, 20] as $id) {
            $section = new Sections();
            (new \ReflectionProperty(Sections::class, 'id'))->setValue($section, $id);
            $course = new Courses();
            (new \ReflectionProperty(Courses::class, 'id'))->setValue($course, $id);
            $section->addCourse($course);
            $sections[] = $section;
        }

        self::assertSame([10 => true, 20 => false], (new SectionCompletionService())->calculate($sections, [10]));
    }
}
