<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\CourseFileKind;
use App\Repository\CoursesRepository;
use App\Services\Courses\CourseFileStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:courses:verify-files', description: 'Read-only check of every persisted course filename in private storage.')]
final class VerifyCourseFilesCommand extends Command
{
    public function __construct(private readonly CoursesRepository $courses, private readonly CourseFileStorage $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $checked = 0;
        $missing = 0;
        foreach ($this->courses->createQueryBuilder('course')->getQuery()->toIterable() as $course) {
            foreach (CourseFileKind::cases() as $kind) {
                if (null === $kind->filename($course) || '' === $kind->filename($course)) {
                    continue;
                }
                ++$checked;
                if (null === $this->storage->resolve($course, $kind)) {
                    ++$missing;
                    $output->writeln(sprintf('MISSING/UNSAFE course=%d kind=%s', $course->getId(), $kind->value));
                }
            }
        }
        $output->writeln(sprintf('%d references checked; %d missing or unsafe.', $checked, $missing));

        return 0 === $missing ? Command::SUCCESS : Command::FAILURE;
    }
}
