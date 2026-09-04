<?php

declare(strict_types=1);

namespace App\Tests\Templates;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class LearningReminderEmailTest extends KernelTestCase
{
    public function testTemplateRendersReminderAndAbsoluteTrainingUrl(): void
    {
        self::bootKernel();

        $router = self::getContainer()->get(UrlGeneratorInterface::class);
        $twig = self::getContainer()->get(Environment::class);
        $trainingUrl = $router->generate(
            'app_user_training',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $html = $twig->render('emails/learning_reminder.html.twig', [
            'firstname' => 'Camille',
            'summary' => 'Tous les jours à 8 h',
            'trainingUrl' => $trainingUrl,
        ]);

        self::assertSame('https://orthogram.example.test/ma-formation', $trainingUrl);
        self::assertStringContainsString('Bonjour Camille,', $html);
        self::assertStringContainsString('Tous les jours à 8 h', $html);
        self::assertStringContainsString('Poursuivre ma formation', $html);
        self::assertGreaterThanOrEqual(3, substr_count($html, $trainingUrl));
        self::assertStringNotContainsString('__CHANGE_ME__', $html);
        self::assertStringNotContainsString('<script', strtolower($html));
    }

    public function testTemplateUsesGenericGreetingWithoutFirstname(): void
    {
        self::bootKernel();

        $html = self::getContainer()->get(Environment::class)->render(
            'emails/learning_reminder.html.twig',
            [
                'firstname' => null,
                'summary' => 'Chaque semaine',
                'trainingUrl' => 'https://orthogram.example.test/ma-formation',
            ],
        );

        self::assertStringContainsString('Bonjour,', $html);
    }
}
