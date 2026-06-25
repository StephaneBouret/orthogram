<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testHoneypotRejectsSpam(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/identifiant-oublie');
        $form = $crawler->selectButton('Envoyer la demande')->form();

        $form['forgot_identifier_request_form[requestedIdentifier]'] = 'jean@test.fr';
        $form['forgot_identifier_request_form[firstname]'] = 'Jean';
        $form['forgot_identifier_request_form[lastname]'] = 'Dupont';
        $form['forgot_identifier_request_form[phone]'] = '0102030405';
        $form['forgot_identifier_request_form[message]'] = 'Bonjour';
        $form['forgot_identifier_request_form[website]'] = 'spam';

        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertRouteSame('app_forgot_identifier');
        self::assertSelectorExists('.alert-danger');
        self::assertSelectorTextContains('h1', 'Identifiant oublié');
    }
}
