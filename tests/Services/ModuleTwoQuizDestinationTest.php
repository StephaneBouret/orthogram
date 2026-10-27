<?php

namespace App\Tests\Services;

use App\Services\ModuleTwoQuizDestination;
use App\Services\QuizCommandDestination;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Work-database identities are stubs only: no automated test connects to orthogram. */
final class ModuleTwoQuizDestinationTest extends TestCase
{
    /** @return array<string, mixed> */
    private function options(): array
    {
        return ['apply' => true, 'expect-env' => 'dev', 'expect-database' => 'orthogram', 'expect-host' => '127.0.0.1', 'expect-port' => '3306', 'expect-server' => 'local-test'];
    }

    private function connection(string $database = 'orthogram', string $host = '127.0.0.1', int $serverPort = 3306): Connection
    {
        $db = $this->createStub(Connection::class);
        $db->method('getParams')->willReturn(['host' => $host, 'port' => 3306]);
        $db->method('fetchAssociative')->willReturn(['db' => $database, 'server' => 'local-test', 'server_port' => $serverPort]);
        $db->method('fetchOne')->willReturn($serverPort);

        return $db;
    }

    /** @return iterable<string, array{string}> */
    public static function actions(): iterable
    {
        foreach (['import', 'enable', 'disable'] as $action) {
            yield $action => [$action];
        }
    }

    #[DataProvider('actions')]
    public function testWorkOperationsRequireConsentAndPreviewDoesNotWrite(string $action): void
    {
        $guard = new ModuleTwoQuizDestination(new QuizCommandDestination('dev'));
        $db = $this->connection();
        $options = $this->options();
        self::assertSame('orthogram', $guard->verify($db, array_replace($options, ['apply' => false]), $action)['database']);
        try {
            $guard->verify($db, $options, $action);
            self::fail('Explicit consent required');
        } catch (\DomainException $error) {
            self::assertStringContainsString('--allow-work-database', $error->getMessage());
        }
        $allowed = $guard->verify($db, $options + ['allow-work-database' => true], $action);
        self::assertSame(['environment' => 'dev', 'database' => 'orthogram', 'host' => '127.0.0.1', 'port' => 3306, 'server' => 'local-test'], $allowed);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        foreach (['import', 'enable', 'disable'] as $action) {
            foreach (['database', 'host', 'port', 'server', 'env', 'missing-host', 'missing-env', 'server-port', 'remote', 'prod', 'other-database'] as $mode) {
                yield $action.'/'.$mode => [$mode, $action];
            }
        }
    }

    #[DataProvider('refused')]
    public function testExplicitConsentNeverBypassesOtherProtections(string $mode, string $action): void
    {
        $options = $this->options() + ['allow-work-database' => true];
        $environment = 'dev';
        $db = $this->connection();
        if (in_array($mode, ['database', 'host', 'port', 'server', 'env'], true)) {
            $options['expect-'.$mode] = 'port' === $mode ? '3307' : 'different';
        } elseif (str_starts_with($mode, 'missing-')) {
            unset($options['expect-'.substr($mode, 8)]);
        } elseif ('server-port' === $mode) {
            $db = $this->connection(serverPort: 3307);
        } elseif ('remote' === $mode) {
            $db = $this->connection(host: 'remote.invalid');
            $options['expect-host'] = 'remote.invalid';
        } elseif ('prod' === $mode) {
            $environment = $options['expect-env'] = 'prod';
        } elseif ('other-database' === $mode) {
            $db = $this->connection(database: 'orthogram_other');
            $options['expect-database'] = 'orthogram_other';
        }
        $this->expectException(\DomainException::class);
        (new ModuleTwoQuizDestination(new QuizCommandDestination($environment)))->verify($db, $options, $action);
    }

    public function testRemoteRefusalHappensBeforeConnection(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('getParams')->willReturn(['host' => 'remote.invalid']);
        $db->expects(self::never())->method('fetchAssociative');
        $db->expects(self::never())->method('fetchOne');
        $this->expectException(\DomainException::class);
        (new ModuleTwoQuizDestination(new QuizCommandDestination('prod')))->verify($db, $this->options());
    }
}
