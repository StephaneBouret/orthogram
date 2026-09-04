<?php

declare(strict_types=1);

namespace App\Tests\Services;

use App\Services\LearningReminderDispatchLock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LearningReminderDispatchLockTest extends KernelTestCase
{
    /** @var list<LearningReminderDispatchLock> */
    private array $locks = [];

    protected function tearDown(): void
    {
        foreach ($this->locks as $lock) {
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }

        $this->locks = [];
        parent::tearDown();
    }

    public function testTwoConnectionsAreMutuallyExclusiveAndCanAcquireAfterRelease(): void
    {
        self::bootKernel();

        $baseConnection = self::getContainer()->get(Connection::class);
        $firstConnection = DriverManager::getConnection($baseConnection->getParams());
        $secondConnection = DriverManager::getConnection($baseConnection->getParams());
        $environment = 'test_'.bin2hex(random_bytes(5));
        $firstLock = $this->track(new LearningReminderDispatchLock($firstConnection, $environment));
        $secondLock = $this->track(new LearningReminderDispatchLock($secondConnection, $environment));

        try {
            self::assertTrue($firstLock->acquire());
            self::assertFalse($secondLock->acquire());
            self::assertTrue($firstLock->release());
            self::assertTrue($secondLock->acquire());
        } finally {
            $firstLock->release();
            $secondLock->release();
            $firstConnection->close();
            $secondConnection->close();
        }
    }

    public function testSecondAcquireOnSameInstanceDoesNotExecuteAnotherSqlQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $queries = 0;
        $connection
            ->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$queries): string {
                ++$queries;
                self::assertSame('orthogram.test.learning-reminders.dispatch', $parameters['name']);

                if (1 === $queries) {
                    self::assertSame('SELECT GET_LOCK(:name, 0)', $sql);
                } else {
                    self::assertSame('SELECT RELEASE_LOCK(:name)', $sql);
                }

                return '1';
            });
        $lock = $this->track(new LearningReminderDispatchLock($connection, 'test'));

        self::assertTrue($lock->acquire());
        self::assertTrue($lock->acquire());
        self::assertSame(1, $queries);
        self::assertTrue($lock->release());
    }

    public function testReleaseWithoutAcquisitionIsSuccessfulAndDoesNotQueryDatabase(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchOne');
        $lock = $this->track(new LearningReminderDispatchLock($connection, 'test'));

        self::assertTrue($lock->release());
    }

    #[DataProvider('refusedAcquisitionProvider')]
    public function testZeroOrNullMeansAcquisitionWasRefused(mixed $databaseResult): void
    {
        $connection = $this->createConfiguredStub(Connection::class, [
            'fetchOne' => $databaseResult,
        ]);
        $lock = $this->track(new LearningReminderDispatchLock($connection, 'test'));

        self::assertFalse($lock->acquire());
        self::assertTrue($lock->release());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedAcquisitionProvider(): iterable
    {
        yield 'integer zero' => [0];
        yield 'string zero' => ['0'];
        yield 'null' => [null];
    }

    public function testUnexpectedAcquisitionValueIsRejected(): void
    {
        $connection = $this->createConfiguredStub(Connection::class, [
            'fetchOne' => false,
        ]);
        $lock = $this->track(new LearningReminderDispatchLock($connection, 'test'));

        $this->expectException(\UnexpectedValueException::class);

        $lock->acquire();
    }

    public function testUnexpectedReleaseValueIsReportedAsFailure(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(1, 0);
        $lock = $this->track(new LearningReminderDispatchLock($connection, 'test'));

        self::assertTrue($lock->acquire());
        self::assertFalse($lock->release());
    }

    public function testLockNameIncludesEnvironment(): void
    {
        $connection = $this->createMock(Connection::class);
        $names = [];
        $connection
            ->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql, array $parameters) use (&$names): int {
                self::assertSame('SELECT GET_LOCK(:name, 0)', $sql);
                $names[] = $parameters['name'];

                return 0;
            });
        $preprodLock = $this->track(new LearningReminderDispatchLock($connection, 'preprod'));
        $testLock = $this->track(new LearningReminderDispatchLock($connection, 'test'));

        self::assertFalse($preprodLock->acquire());
        self::assertFalse($testLock->acquire());
        self::assertSame([
            'orthogram.preprod.learning-reminders.dispatch',
            'orthogram.test.learning-reminders.dispatch',
        ], $names);
    }

    private function track(LearningReminderDispatchLock $lock): LearningReminderDispatchLock
    {
        $this->locks[] = $lock;

        return $lock;
    }
}
