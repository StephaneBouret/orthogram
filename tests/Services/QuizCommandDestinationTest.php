<?php

namespace App\Tests\Services;

use App\Services\QuizCommandDestination;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class QuizCommandDestinationTest extends TestCase
{
    public function testWorkDatabaseStillRequiresItsOwnAuthorization(): void
    {
        // Mock only: these tests never connect to orthogram or a remote host.
        $db = $this->createStub(Connection::class);
        $db->method('getParams')->willReturn(['host' => '127.0.0.1', 'port' => 3306]);
        $db->method('fetchAssociative')->willReturn(['db' => 'orthogram', 'server' => 'local-server']);
        $guard = new QuizCommandDestination('dev');
        foreach ([[], ['allow-production-database' => true]] as $extra) {
            try {
                $guard->verify($db, $extra + ['apply' => true, 'expect-database' => 'orthogram']);
                self::fail('Authorization required');
            } catch (\DomainException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
        self::assertSame('orthogram', $guard->verify($db, ['apply' => true, 'expect-database' => 'orthogram', 'allow-work-database' => true])['database']);
    }

    public function testNonlocalConnectionsAreStillRefusedInDevelopmentBeforeConnecting(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getParams')->willReturn(['host' => 'example.invalid', 'port' => 3306]);
        $db->expects(self::never())->method('fetchAssociative');
        $this->expectException(\DomainException::class);
        (new QuizCommandDestination('dev'))->verify($db, ['expect-database' => 'unapproved']);
    }

    public function testExplicitProductionAssertionsSupportNonlocalHostsWithoutOpeningAConnection(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getParams')->willReturn(['host' => 'mysql.example.invalid', 'port' => 3306]);
        $db->method('fetchAssociative')->willReturn(['db' => 'explicit_fixture', 'server' => 'mysql-node-fixture', 'server_port' => 3306]);
        $context = (new QuizCommandDestination('prod'))->verify($db, ['expect-env' => 'prod', 'expect-database' => 'explicit_fixture',
            'expect-host' => 'mysql.example.invalid', 'expect-port' => '3306', 'expect-server' => 'mysql-node-fixture', 'apply' => true, 'allow-production-database' => true]);
        self::assertSame('mysql.example.invalid', $context['host']);
    }

    public function testWorkAuthorizationDoesNotAllowArbitraryLocalDatabases(): void
    {
        $db = $this->createStub(Connection::class);
        $db->method('getParams')->willReturn(['host' => '127.0.0.1']);
        $db->method('fetchAssociative')->willReturn(['db' => 'another_local_database', 'server' => 'local-server']);
        $this->expectException(\DomainException::class);
        (new QuizCommandDestination('dev'))->verify($db, ['expect-database' => 'another_local_database', 'apply' => true, 'allow-work-database' => true]);
    }
}
