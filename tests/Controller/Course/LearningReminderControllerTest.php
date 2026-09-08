<?php

declare(strict_types=1);

namespace App\Tests\Controller\Course;

use App\Controller\Course\LearningReminderController;
use App\Dto\LearningReminderPayload;
use App\Entity\LearningReminder;
use App\Entity\Program;
use App\Entity\User;
use App\Enum\LearningReminderFrequency;
use App\Repository\LearningReminderRepository;
use App\Services\LearningReminderNextRunCalculator;
use App\Services\LearningReminderViewService;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use libphonenumber\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

final class LearningReminderControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private LearningReminderRepository $repository;
    private User $user;
    private Program $program;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame('test', static::$kernel->getEnvironment());
        self::assertStringEndsWith('_test', $this->entityManager->getConnection()->getDatabase());
        $this->repository = static::getContainer()->get(LearningReminderRepository::class);

        $suffix = bin2hex(random_bytes(8));
        $phone = (new PhoneNumber())
            ->setCountryCode(33)
            ->setNationalNumber('612345678');

        $this->user = (new User())
            ->setEmail(sprintf('learning-reminder-%s@example.test', $suffix))
            ->setPassword('test-password')
            ->setFirstname('Camille')
            ->setLastname('Test')
            ->setAddress('1 rue du Test')
            ->setPostalCode('75001')
            ->setCity('Paris')
            ->setPhone($phone)
            ->setRoles(['ROLE_ADMIN']);

        $this->program = (new Program())
            ->setName('Programme test')
            ->setSlug(sprintf('programme-test-%s', $suffix))
            ->setDescription('Programme réservé aux tests fonctionnels.')
            ->setPrice(100);

        $this->entityManager->persist($this->user);
        $this->entityManager->persist($this->program);
        $this->entityManager->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->isOpen()) {
            $reminder = $this->repository->findOneByUser($this->user);

            if (null !== $reminder) {
                $this->entityManager->remove($reminder);
            }

            if ($this->entityManager->contains($this->program)) {
                $this->entityManager->remove($this->program);
            }

            if ($this->entityManager->contains($this->user)) {
                $this->entityManager->remove($this->user);
            }

            $this->entityManager->flush();
        }

        parent::tearDown();
    }

    public function testInvalidJsonReturnsBadRequest(): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->requestRaw(
            $this->upsertUrl(),
            '{"frequency":',
            $tokens['upsert'],
            'application/json',
        );

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[DataProvider('unsupportedContentTypeProvider')]
    public function testUnsupportedContentTypeReturns415(?string $contentType): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->requestRaw(
            $this->upsertUrl(),
            json_encode($this->dailyPayload(), \JSON_THROW_ON_ERROR),
            $tokens['upsert'],
            $contentType,
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function unsupportedContentTypeProvider(): iterable
    {
        yield 'missing content type' => [null];
        yield 'text plain' => ['text/plain'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('denormalizationErrorProvider')]
    public function testDenormalizationErrorsReturn422(array $payload): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->requestJson($this->upsertUrl(), $payload, $tokens['upsert']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertJson((string) $this->client->getResponse()->getContent());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function denormalizationErrorProvider(): iterable
    {
        $valid = [
            'frequency' => 'daily',
            'reminderTime' => '08:30',
            'weekdays' => [],
            'scheduledDate' => null,
            'timezone' => 'Europe/Paris',
        ];

        yield 'additional attribute' => [
            $valid + ['unexpected' => true],
        ];

        $missingTimezone = $valid;
        unset($missingTimezone['timezone']);

        yield 'missing property' => [$missingTimezone];

        yield 'wrong property type' => [
            array_replace($valid, ['weekdays' => 'monday']),
        ];
    }

    public function testInconsistentPayloadReturns422(): void
    {
        $tokens = $this->loadPageAndReadTokens();
        $payload = [
            'frequency' => 'weekly',
            'reminderTime' => '18:30',
            'weekdays' => [],
            'scheduledDate' => null,
            'timezone' => 'Europe/Paris',
        ];

        $this->requestJson($this->upsertUrl(), $payload, $tokens['upsert']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testEmptyOnceDateReturns422(): void
    {
        $tokens = $this->loadPageAndReadTokens();
        $payload = [
            'frequency' => 'once',
            'reminderTime' => '08:30',
            'weekdays' => [],
            'scheduledDate' => '',
            'timezone' => 'Europe/Paris',
        ];

        $this->requestJson($this->upsertUrl(), $payload, $tokens['upsert']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testPastOnceOccurrenceReturns422(): void
    {
        $tokens = $this->loadPageAndReadTokens();
        $payload = [
            'frequency' => 'once',
            'reminderTime' => '08:30',
            'weekdays' => [],
            'scheduledDate' => '2000-01-01',
            'timezone' => 'Europe/Paris',
        ];

        $this->requestJson($this->upsertUrl(), $payload, $tokens['upsert']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(
            'scheduledDate',
            $this->responseData()['violations'][0]['propertyPath'],
        );
    }

    public function testItCreatesThenReconfiguresTheSameReminder(): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->requestJson(
            $this->upsertUrl(),
            $this->dailyPayload(),
            $tokens['upsert'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $createdId = $this->responseData()['reminder']['id'];

        self::assertIsInt($createdId);
        self::assertSame(1, $this->repository->count(['user' => $this->user]));

        $this->requestJson(
            $this->upsertUrl(),
            [
                'frequency' => 'weekly',
                'reminderTime' => '18:30',
                'weekdays' => [1, 3, 5],
                'scheduledDate' => null,
                'timezone' => 'Europe/Paris',
            ],
            $tokens['upsert'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $updated = $this->responseData();

        self::assertSame($createdId, $updated['reminder']['id']);
        self::assertSame('weekly', $updated['reminder']['frequency']);
        self::assertSame([1, 3, 5], $updated['reminder']['weekdays']);
        self::assertSame(1, $this->repository->count(['user' => $this->user]));
    }

    public function testDisableKeepsTheReminderAndClearsNextRun(): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->requestJson(
            $this->upsertUrl(),
            $this->dailyPayload(),
            $tokens['upsert'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->responseData()['reminder']['id'];

        $this->requestDisable($tokens['disable']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $reminder = $this->repository->findOneByUser($this->user);

        self::assertNotNull($reminder);
        self::assertSame($id, $reminder->getId());
        self::assertFalse($reminder->isEnabled());
        self::assertNull($reminder->getNextRunAt());
        self::assertSame(1, $this->repository->count(['user' => $this->user]));
    }

    public function testDisableWithoutReminderReturns404(): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->requestDisable($tokens['disable']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[DataProvider('invalidCsrfProvider')]
    public function testMissingOrInvalidUpsertCsrfReturnsJson403(?string $csrfToken): void
    {
        $this->loadPageAndReadTokens();

        $this->requestJson($this->upsertUrl(), $this->dailyPayload(), $csrfToken);

        $this->assertInvalidCsrfResponse();
        self::assertNull($this->repository->findOneByUser($this->user));
    }

    #[DataProvider('invalidCsrfProvider')]
    public function testMissingOrInvalidDisableCsrfReturnsJson403(?string $csrfToken): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->requestJson(
            $this->upsertUrl(),
            $this->dailyPayload(),
            $tokens['upsert'],
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $reminder = $this->repository->findOneByUser($this->user);

        self::assertNotNull($reminder);
        $reminderId = $reminder->getId();
        $updatedAt = $reminder->getUpdatedAt()->format('Y-m-d H:i:sP');
        $nextRunAt = $reminder->getNextRunAt()?->format('Y-m-d H:i:sP');

        $this->requestDisable($csrfToken);

        $this->assertInvalidCsrfResponse();
        $storedReminder = $this->repository->findOneByUser($this->user);

        self::assertNotNull($storedReminder);
        self::assertSame($reminderId, $storedReminder->getId());
        self::assertTrue($storedReminder->isEnabled());
        self::assertSame(
            $nextRunAt,
            $storedReminder->getNextRunAt()?->format('Y-m-d H:i:sP'),
        );
        self::assertSame(
            $updatedAt,
            $storedReminder->getUpdatedAt()->format('Y-m-d H:i:sP'),
        );
    }

    /**
     * @return iterable<string, array{0: ?string}>
     */
    public static function invalidCsrfProvider(): iterable
    {
        yield 'missing token' => [null];
        yield 'invalid token' => ['invalid-token'];
    }

    public function testCourseVoterRefusalReturns403(): void
    {
        $tokens = $this->loadPageAndReadTokens();

        $this->user->setRoles([]);
        $this->entityManager->flush();
        $this->client->loginUser($this->user);

        $this->requestJson(
            $this->upsertUrl(),
            $this->dailyPayload(),
            $tokens['upsert'],
        );

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertNull($this->repository->findOneByUser($this->user));
    }

    public function testUniqueConstraintCollisionReturns409(): void
    {
        $driverException = new class('Duplicate entry', 1062) extends \RuntimeException implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };
        $uniqueConstraintViolation = new UniqueConstraintViolationException(
            $driverException,
            null,
        );

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager
            ->expects(self::once())
            ->method('persist');
        $entityManager
            ->expects(self::once())
            ->method('flush')
            ->willThrowException($uniqueConstraintViolation);

        $clock = $this->createMock(ClockInterface::class);
        $clock
            ->expects(self::once())
            ->method('now')
            ->willReturn(new \DateTimeImmutable('2026-09-04 12:00:00 UTC'));

        $controller = new LearningReminderController(
            $this->repository,
            static::getContainer()->get(LearningReminderNextRunCalculator::class),
            static::getContainer()->get(LearningReminderViewService::class),
            $entityManager,
            $clock,
        );
        $controller->setContainer(static::getContainer());

        $response = $controller->upsert(
            $this->program,
            $this->user,
            new LearningReminderPayload(
                'daily',
                '08:30',
                [],
                null,
                'Europe/Paris',
            ),
        );

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
        self::assertSame(
            'learning_reminder_conflict',
            json_decode(
                (string) $response->getContent(),
                true,
                flags: \JSON_THROW_ON_ERROR,
            )['error']['code'],
        );
    }

    /**
     * @return array{upsert: string, disable: string, calendar: string}
     */
    private function loadPageAndReadTokens(): array
    {
        $crawler = $this->client->request(
            'GET',
            sprintf('/courses/%s', $this->program->getSlug()),
        );

        self::assertResponseIsSuccessful();

        $root = $crawler->filter('[data-controller~="learning-reminder"]');

        self::assertCount(1, $root);

        $upsert = $root->attr('data-learning-reminder-upsert-token-value');
        $disable = $root->attr('data-learning-reminder-disable-token-value');

        self::assertNotNull($upsert);
        self::assertNotNull($disable);
        $calendar = $root->attr('data-learning-reminder-calendar-token-value');
        self::assertNotNull($calendar);

        return [
            'upsert' => $upsert,
            'disable' => $disable,
            'calendar' => $calendar,
        ];
    }

    /**
     * @return array{
     *     frequency: string,
     *     reminderTime: string,
     *     weekdays: list<int>,
     *     scheduledDate: null,
     *     timezone: string
     * }
     */
    private function dailyPayload(): array
    {
        return [
            'frequency' => 'daily',
            'reminderTime' => '08:30',
            'weekdays' => [],
            'scheduledDate' => null,
            'timezone' => 'Europe/Paris',
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function calendarFormats(): iterable
    {
        yield 'google' => ['google'];
        yield 'ics' => ['ics'];
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function calendarCsrfCases(): iterable
    {
        foreach (['google', 'ics'] as $format) {
            yield $format.' absent' => [$format, null];
            yield $format.' invalid' => [$format, 'invalid-token'];
        }
    }

    #[DataProvider('calendarFormats')]
    public function testCalendarAnonymousAccessUsesActualSecurityResponse(string $format): void
    {
        $this->client->getCookieJar()->clear();
        $this->requestJson($this->calendarUrl($format), $this->dailyPayload(), null);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->client->getResponse()->isRedirection());
        self::assertNull($this->client->getResponse()->headers->get('Location'));
        self::assertJson((string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->reminderSnapshot());
    }

    #[DataProvider('calendarFormats')]
    public function testCalendarConnectedUserWithoutAccessIsDenied(string $format): void
    {
        $tokens = $this->loadPageAndReadTokens();
        $this->user->setRoles([]);
        $this->entityManager->flush();
        $this->client->loginUser($this->user);
        $this->requestJson($this->calendarUrl($format), $this->dailyPayload(), $tokens['calendar']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->reminderSnapshot());
    }

    #[DataProvider('calendarCsrfCases')]
    public function testCalendarCsrfIsRequiredForBothFormats(string $format, ?string $token): void
    {
        $this->loadPageAndReadTokens();
        $this->requestJson($this->calendarUrl($format), $this->dailyPayload(), $token);
        $this->assertInvalidCsrfResponse();
        self::assertSame([], $this->reminderSnapshot());
    }

    #[DataProvider('calendarFormats')]
    public function testRecurringCalendarDownloadsCurrentFormWithoutCreatingReminder(string $format): void
    {
        $tokens = $this->loadPageAndReadTokens();
        static::getContainer()->set(ClockInterface::class, new MockClock('2026-09-07T06:00:00Z'));
        $this->requestJson($this->calendarUrl($format), $this->dailyPayload(), $tokens['calendar']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="orthogram.ics"');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertStringContainsString('DTSTART;TZID=Europe/Paris:20260907T083000', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('RRULE:FREQ=DAILY;UNTIL=20310907T063000Z', (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->reminderSnapshot());
        self::assertFalse($this->client->getResponse()->headers->has('X-Orthogram-Separate-First-Notice'));
    }

    #[DataProvider('calendarFormats')]
    public function testSplitCalendarDownloadDoesNotCreateReminderAndCarriesNotice(string $format): void
    {
        $tokens = $this->loadPageAndReadTokens();
        static::getContainer()->set(ClockInterface::class, new MockClock('2026-03-28T23:00:00Z'));
        $this->requestJson($this->calendarUrl($format), array_replace($this->dailyPayload(), ['reminderTime' => '02:30']), $tokens['calendar']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=UTF-8');
        $response = $this->client->getResponse();
        self::assertSame(\App\Services\LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, rawurldecode($response->headers->get('X-Orthogram-Separate-First-Notice')));
        $events = array_values(\Sabre\VObject\Reader::read($response->getContent())->select('VEVENT'));
        self::assertCount(2, $events);
        self::assertNotSame((string) $events[0]->UID, (string) $events[1]->UID);
        self::assertSame('20260329T013000Z', (string) $events[0]->DTSTART);
        self::assertSame('20260330T023000', (string) $events[1]->DTSTART);
        self::assertSame([], $this->reminderSnapshot());
    }

    /** @return iterable<string, array{string, bool}> */
    public static function calendarExistingReminderCases(): iterable
    {
        foreach (['google', 'ics'] as $format) {
            foreach ([true, false] as $enabled) {
                yield $format.($enabled ? ' active' : ' disabled') => [$format, $enabled];
            }
        }
    }

    #[DataProvider('calendarExistingReminderCases')]
    public function testCalendarDoesNotMutateAnyReminderColumn(string $format, bool $enabled): void
    {
        $created = new \DateTimeImmutable('2026-09-01T00:00:00Z');
        $reminder = LearningReminder::create($this->user, LearningReminderFrequency::WEEKLY, new \DateTimeImmutable('18:00:00 UTC'), [1, 5], null, 'Europe/London', new \DateTimeImmutable('2026-09-02T17:00:00Z'), $created);
        // Populate technical dates without dispatching any email.
        $reminder->markSent(new \DateTimeImmutable('2026-09-02T17:00:00Z'), new \DateTimeImmutable('2026-09-04T17:00:00Z'), new \DateTimeImmutable('2026-09-02T17:01:00Z'));
        // Retain a non-null scheduled_date as well as last_sent_at in the snapshot.
        $reminder->reconfigure(LearningReminderFrequency::ONCE, new \DateTimeImmutable('18:00:00 UTC'), [], new \DateTimeImmutable('2026-09-10'), 'Europe/London', new \DateTimeImmutable('2026-09-10T17:00:00Z'), new \DateTimeImmutable('2026-09-03T00:00:00Z'));
        if (!$enabled) {
            $reminder->disable(new \DateTimeImmutable('2026-09-04T00:00:00Z'));
        }
        $this->entityManager->persist($reminder);
        $this->entityManager->flush();
        $tokens = $this->loadPageAndReadTokens();
        $before = $this->reminderSnapshot();
        static::getContainer()->set(ClockInterface::class, new MockClock('2026-09-07T06:00:00Z'));
        // Both a recurring download and Google once preparation must leave every column intact.
        foreach ([$this->dailyPayload(), $this->onceCalendarPayload()] as $payload) {
            $this->requestJson($this->calendarUrl($format), $payload, $tokens['calendar']);
            self::assertResponseIsSuccessful();
            self::assertSame($before, $this->reminderSnapshot());
        }
        $clock = static::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('2026-03-28T23:00:00Z');
        $this->requestJson($this->calendarUrl($format), array_replace($this->dailyPayload(), ['reminderTime' => '02:30']), $tokens['calendar']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->has('X-Orthogram-Separate-First-Notice'));
        self::assertSame($before, $this->reminderSnapshot());
        $clock->modify('2026-10-25T01:00:00Z');
        $this->requestJson($this->calendarUrl($format), array_replace($this->dailyPayload(), ['frequency' => 'weekly', 'weekdays' => [7], 'reminderTime' => '02:30']), $tokens['calendar']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->client->getResponse()->headers->has('X-Orthogram-Separate-First-Notice'));
        self::assertSame($before, $this->reminderSnapshot());
        $storedReminder = $this->repository->findOneByUser($this->user);
        self::assertNotNull($storedReminder);
        self::assertSame($enabled, $storedReminder->isEnabled());
        if (!$enabled) {
            self::assertNull($storedReminder->getNextRunAt());
        }
    }

    #[DataProvider('calendarFormats')]
    public function testBUsesExistingIcsDownloadForBothFormatsWithoutCreatingReminder(string $format): void
    {
        $tokens = $this->loadPageAndReadTokens();
        static::getContainer()->set(ClockInterface::class, new MockClock('2026-10-25T01:00:00Z'));
        $this->requestJson($this->calendarUrl($format), array_replace($this->dailyPayload(), ['frequency' => 'weekly', 'weekdays' => [7], 'reminderTime' => '02:30']), $tokens['calendar']);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=UTF-8');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename="orthogram.ics"');
        self::assertResponseHeaderSame('X-Orthogram-Expires-At', '2026-10-25T01:05:00+00:00');
        $response = $this->client->getResponse();
        self::assertSame(\App\Services\LearningReminderCalendarService::SEPARATE_FIRST_NOTICE, rawurldecode($response->headers->get('X-Orthogram-Separate-First-Notice')));
        $ics = $response->getContent();
        $events = array_values(\Sabre\VObject\Reader::read($ics)->select('VEVENT'));
        self::assertCount(2, $events);
        self::assertNotSame((string) $events[0]->UID, (string) $events[1]->UID);
        self::assertSame('20261025T013000Z', (string) $events[0]->DTSTART);
        self::assertSame('20261025T014500Z', (string) $events[0]->DTEND);
        self::assertSame('20261101T023000', (string) $events[1]->DTSTART);
        self::assertSame('FREQ=WEEKLY;BYDAY=SU;WKST=MO;UNTIL=20311025T013000Z', (string) $events[1]->RRULE);
        foreach (['EXDATE', 'RDATE', 'RECURRENCE-ID'] as $property) {
            self::assertStringNotContainsString($property, $ics);
        }
        self::assertSame([], $this->reminderSnapshot());
    }

    public function testGoogleOncePreparationHasExactDatesAndDoesNotPersist(): void
    {
        $tokens = $this->loadPageAndReadTokens();
        static::getContainer()->set(ClockInterface::class, new MockClock('2026-09-07T06:00:00Z'));
        $this->requestJson($this->calendarUrl('google'), $this->onceCalendarPayload(), $tokens['calendar']);
        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertStringStartsWith('https://calendar.google.com/calendar/render?', $data['url']);
        parse_str(parse_url($data['url'], \PHP_URL_QUERY), $query);
        self::assertSame('20260907T063000Z/20260907T064500Z', $query['dates']);
        self::assertSame('Europe/Paris', $query['stz']);
        self::assertSame('Europe/Paris', $query['etz']);
        self::assertArrayNotHasKey('ctz', $query);
        self::assertSame('2026-09-07T06:05:00+00:00', $data['expiresAt']);
        self::assertSame([], $this->reminderSnapshot());
    }

    /** @return iterable<string, array{string, string}> */
    public static function calendarCloseStarts(): iterable
    {
        foreach (['google', 'ics'] as $format) {
            foreach (['once', 'daily', 'weekly'] as $frequency) {
                yield $format.' '.$frequency => [$format, $frequency];
            }
        }
    }

    #[DataProvider('calendarCloseStarts')]
    public function testCalendarPreparationExpiresAtCloseFirstOccurrence(string $format, string $frequency): void
    {
        $tokens = $this->loadPageAndReadTokens();
        static::getContainer()->set(ClockInterface::class, new MockClock('2026-09-07T06:28:00Z'));
        $payload = array_replace($this->dailyPayload(), [
            'frequency' => $frequency,
            'weekdays' => 'weekly' === $frequency ? [1] : [],
            'scheduledDate' => 'once' === $frequency ? '2026-09-07' : null,
        ]);
        $this->requestJson($this->calendarUrl($format), $payload, $tokens['calendar']);
        self::assertResponseIsSuccessful();
        if ('google' === $format && 'once' === $frequency) {
            self::assertSame('2026-09-07T06:30:00+00:00', $this->responseData()['expiresAt']);
        } else {
            self::assertResponseHeaderSame('X-Orthogram-Expires-At', '2026-09-07T06:30:00+00:00');
        }
        self::assertSame([], $this->reminderSnapshot());
    }

    #[DataProvider('calendarFormats')]
    public function testCalendarRejectsInvalidPayloadAndOnceThatBecamePast(string $format): void
    {
        $tokens = $this->loadPageAndReadTokens();
        $clock = new MockClock('2026-09-07T06:00:00Z');
        static::getContainer()->set(ClockInterface::class, $clock);
        $this->requestJson($this->calendarUrl($format), array_replace($this->dailyPayload(), ['timezone' => 'Invalid/Zone']), $tokens['calendar']);
        self::assertResponseStatusCodeSame(422);
        $this->requestJson($this->calendarUrl($format), $this->onceCalendarPayload(), $tokens['calendar']);
        self::assertResponseIsSuccessful();
        $clock->modify('+31 minutes');
        $this->requestJson($this->calendarUrl($format), $this->onceCalendarPayload(), $tokens['calendar']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('scheduledDate', $this->responseData()['violations'][0]['propertyPath']);
        self::assertSame([], $this->reminderSnapshot());
    }

    /** @return list<array<string, mixed>> */
    private function reminderSnapshot(): array
    {
        // Raw DB read catches changes to every mapped column, including technical dates.
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM learning_reminder WHERE user_id = ? ORDER BY id', [$this->user->getId()]);
    }

    /** @return array{frequency: string, reminderTime: string, weekdays: list<int>, scheduledDate: string, timezone: string} */
    private function onceCalendarPayload(): array
    {
        return array_replace($this->dailyPayload(), ['frequency' => 'once', 'scheduledDate' => '2026-09-07']);
    }

    private function calendarUrl(string $format): string
    {
        return sprintf('/courses/%s/learning-reminder/calendar/%s', $this->program->getSlug(), $format);
    }

    private function upsertUrl(): string
    {
        return sprintf(
            '/courses/%s/learning-reminder',
            $this->program->getSlug(),
        );
    }

    private function disableUrl(): string
    {
        return sprintf(
            '/courses/%s/learning-reminder/disable',
            $this->program->getSlug(),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requestJson(
        string $url,
        array $payload,
        ?string $csrfToken,
    ): void {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if (null !== $csrfToken) {
            $headers['HTTP_X_CSRF_TOKEN'] = $csrfToken;
        }

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            $headers,
            json_encode($payload, \JSON_THROW_ON_ERROR),
        );
    }

    private function requestRaw(
        string $url,
        string $content,
        string $csrfToken,
        ?string $contentType,
    ): void {
        $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
        ];

        if (null !== $contentType) {
            $headers['CONTENT_TYPE'] = $contentType;
        }

        $this->client->request(
            'POST',
            $url,
            [],
            [],
            $headers,
            $content,
        );
    }

    private function requestDisable(?string $csrfToken): void
    {
        $headers = [
            'HTTP_ACCEPT' => 'application/json',
        ];

        if (null !== $csrfToken) {
            $headers['HTTP_X_CSRF_TOKEN'] = $csrfToken;
        }

        $this->client->request(
            'POST',
            $this->disableUrl(),
            [],
            [],
            $headers,
        );
    }

    private function assertInvalidCsrfResponse(): void
    {
        $response = $this->client->getResponse();

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertFalse($response->isRedirection());
        self::assertNull($response->headers->get('Location'));
        self::assertJson((string) $response->getContent());
        self::assertSame(
            'invalid_csrf_token',
            $this->responseData()['error']['code'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
    }
}
