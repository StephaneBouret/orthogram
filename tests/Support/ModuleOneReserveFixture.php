<?php

namespace App\Tests\Support;

use App\Entity\Courses;
use App\Entity\Program;
use App\Entity\Quiz;
use App\Entity\QuizAnswer;
use App\Entity\QuizQuestion;
use App\Entity\Sections;
use App\Enum\CourseContentType;
use App\Services\ModuleOneReserveManifest;
use Doctrine\ORM\EntityManagerInterface;

final class ModuleOneReserveFixture
{
    /** @return array<string, mixed> */
    public static function create(EntityManagerInterface $em, ModuleOneReserveManifest $manifest): array
    {
        // Deliberately shift quiz IDs: resolution must never assume 1..8.
        $dummy = (new Quiz())->setTitle('Décalage des identifiants');
        $em->persist($dummy);
        $em->flush();
        $em->remove($dummy);
        $em->flush();
        $program = (new Program())->setName('Formation en orthographe')->setSlug('formation-en-orthographe')->setPrice(0)->setDescription('Recette isolée');
        $sections = [];
        $courses = [];
        $quizzes = [];
        $user = QuizPlayerFactory::user('module1-reserves@example.test');
        $em->persist($user);
        $em->persist($program);
        foreach ([...$manifest->data['fundamentals'], ...$manifest->data['quizzes']] as $spec) {
            $slug = $spec['course']['section_slug'];
            if (!isset($sections[$slug])) {
                $sections[$slug] = (new Sections())->setName($slug)->setSlug($slug)->setProgram($program);
                $em->persist($sections[$slug]);
            }
            $quiz = (new Quiz())->setTitle($spec['expected_title']);
            $em->persist($quiz);
            for ($i = 0; $i < $spec['expected_initial_count']; ++$i) {
                if (isset($spec['initial_questions'])) {
                    $p = $spec['initial_questions'][$i]['expected_existing_payload'];
                    $question = (new QuizQuestion())->setTitle($p['title'])->setText($p['text'])->setExplanation($p['explanation'])
                        ->setMultiple($p['multiple'])->setTheme($p['theme'])->setPosition($p['position']);
                    foreach ($p['answers'] as $a) {
                        $question->addAnswer((new QuizAnswer())->setContent($a['content'])->setCorrect($a['correct'])->setPosition($a['position']));
                    }
                } else {
                    $question = QuizFactory::question(false, [true, false, false, false])->setPosition($i)->setText($spec['quiz_key'].' '.$i);
                }
                $question->setQuiz($quiz);
            }
            $course = (new Courses())->setName($spec['expected_title'])->setSlug($spec['course']['slug'])->setSection($sections[$slug])->setContentType(CourseContentType::Quiz)->setQuiz($quiz)->setIsFree(true);
            $em->persist($course);
            $courses[$spec['quiz_key']] = $course;
            $quizzes[$spec['quiz_key']] = $quiz;
        }
        $em->flush();

        return ['user' => $user, 'quizzes' => $quizzes, 'courses' => $courses];
    }
}
