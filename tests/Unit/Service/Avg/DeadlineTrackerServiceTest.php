<?php

/**
 * Unit tests for DeadlineTrackerService (7-day reminder, escalation, breach).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Avg
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Avg;

use DateTimeImmutable;
use OCA\Pipelinq\Service\Avg\AvgEventService;
use OCA\Pipelinq\Service\Avg\AvgNotificationService;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\DeadlineService;
use OCA\Pipelinq\Service\Avg\DeadlineTrackerService;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__.'/AvgTestSupport.php';

/**
 * Tests for DeadlineTrackerService.
 *
 * Exercises the three deadline milestones (7-day reminder, <72h escalation,
 * breach) over a real AvgRepository backed by the in-memory fake OR. Asserts the
 * compliance invariants: each milestone is recorded exactly once (idempotent
 * across repeated job runs), a breach stamps the request and informs the FG, and
 * a resolved request is never touched.
 */
class DeadlineTrackerServiceTest extends TestCase
{
    /**
     * The fake OR ObjectService.
     *
     * @var FakeAvgObjectService
     */
    private FakeAvgObjectService $objectService;

    /**
     * The repository under test.
     *
     * @var AvgRepository
     */
    private AvgRepository $repository;

    /**
     * The service under test.
     *
     * @var DeadlineTrackerService
     */
    private DeadlineTrackerService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = new FakeAvgObjectService();
        $appConfig           = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static fn (string $app, string $key, string $default = '', bool $lazy = false): string
                => ($key === 'register' ? 'reg' : $key)
        );
        $this->repository = AvgRepositoryFactory::build($this->objectService, $appConfig);
        $events           = new AvgEventService(repository: $this->repository, logger: new NullLogger());

        // A notification manager that returns a no-op notification (the push path
        // is exercised but no transport is asserted here).
        $notificationManager = $this->createMock(IManager::class);
        $notification        = $this->createMock(INotification::class);
        $notification->method($this->anything())->willReturnSelf();
        $notificationManager->method('createNotification')->willReturn($notification);

        $notifications = new AvgNotificationService(
            notificationManager: $notificationManager,
            logger: new NullLogger()
        );

        $this->service = new DeadlineTrackerService(
            repository: $this->repository,
            deadline: new DeadlineService(orGdpr: OrGdprBridgeFactory::build(new FakeOrGdpr())),
            events: $events,
            notifications: $notifications,
            logger: new NullLogger()
        );
    }//end setUp()

    /**
     * Seed an open AVG request with the given deadline.
     *
     * @param string               $deadline  The ISO 8601 deadline.
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed> The stored request.
     */
    private function seedRequest(string $deadline, array $overrides = []): array
    {
        return $this->objectService->saveObject(
            object: array_merge(
                [
                    'status'                    => 'in-behandeling',
                    'behandelaar'               => 'handler1',
                    'wettelijkeTermijnVerloopt' => $deadline,
                ],
                $overrides
            ),
            extend: [],
            register: 'reg',
            schema: AvgRepository::SCHEMA_VERZOEK
        );
    }//end seedRequest()

    /**
     * Count TermijnEvents of a given type across the store.
     *
     * @param string $type The event type.
     *
     * @return int The number of matching events.
     */
    private function countEvents(string $type): int
    {
        return count(
            $this->objectService->findAll(
                ['filters' => ['schema' => AvgRepository::SCHEMA_TERMIJN_EVENT, 'type' => $type]]
            )
        );
    }//end countEvents()

    /**
     * A request due in exactly 7 days gets one reminder, and a second job run is
     * a no-op (idempotent via the recorded event).
     *
     * @return void
     */
    public function testReminderSentOnceAndIdempotent(): void
    {
        $this->seedRequest('2026-05-08T23:59:59+02:00');
        $now = new DateTimeImmutable('2026-05-01T09:00:00+02:00');

        $this->assertSame(1, $this->service->sendReminders(now: $now));
        $this->assertSame(1, $this->countEvents('herinnering-7dagen'));

        // Repeat run: no second reminder.
        $this->assertSame(0, $this->service->sendReminders(now: $now));
        $this->assertSame(1, $this->countEvents('herinnering-7dagen'));
    }//end testReminderSentOnceAndIdempotent()

    /**
     * A request inside the <72h window escalates exactly once.
     *
     * @return void
     */
    public function testEscalationFiresOnceWithinWindow(): void
    {
        $this->seedRequest('2026-05-08T23:59:59+02:00');
        $now = new DateTimeImmutable('2026-05-07T00:00:00+02:00');

        $this->assertSame(1, $this->service->checkEscalations(now: $now));
        $this->assertSame(0, $this->service->checkEscalations(now: $now));
        $this->assertSame(1, $this->countEvents('escalatie-3dagen'));
    }//end testEscalationFiresOnceWithinWindow()

    /**
     * A request comfortably before the deadline does not escalate.
     *
     * @return void
     */
    public function testNoEscalationWellBeforeDeadline(): void
    {
        $this->seedRequest('2026-05-08T23:59:59+02:00');
        $now = new DateTimeImmutable('2026-04-20T12:00:00+02:00');

        $this->assertSame(0, $this->service->checkEscalations(now: $now));
        $this->assertSame(0, $this->countEvents('escalatie-3dagen'));
    }//end testNoEscalationWellBeforeDeadline()

    /**
     * An already-breached request does not also raise an escalation event.
     *
     * @return void
     */
    public function testBreachedRequestDoesNotEscalate(): void
    {
        $this->seedRequest('2026-05-08T23:59:59+02:00');
        $now = new DateTimeImmutable('2026-05-10T12:00:00+02:00');

        $this->assertSame(0, $this->service->checkEscalations(now: $now));
    }//end testBreachedRequestDoesNotEscalate()

    /**
     * A breach is recorded once, stamps the request and informs the FG.
     *
     * @return void
     */
    public function testBreachStampsRequestAndInformsFg(): void
    {
        $request = $this->seedRequest('2026-05-08T23:59:59+02:00');
        $id      = $this->repository->idOf($request);
        $now     = new DateTimeImmutable('2026-05-10T12:00:00+02:00');

        $this->assertSame(1, $this->service->checkBreaches(now: $now));
        $this->assertSame(1, $this->countEvents('termijn-overschreden'));

        $stored = $this->repository->find(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $id);
        $this->assertTrue($stored['termijnOverschreden']);
        $this->assertTrue($stored['fgGeinformeerd']);

        // Repeat run: no second breach event.
        $this->assertSame(0, $this->service->checkBreaches(now: $now));
        $this->assertSame(1, $this->countEvents('termijn-overschreden'));
    }//end testBreachStampsRequestAndInformsFg()

    /**
     * Resolved/archived requests are excluded from all deadline processing.
     *
     * @return void
     */
    public function testResolvedRequestIsIgnored(): void
    {
        $this->seedRequest('2026-05-08T23:59:59+02:00', ['status' => 'afgerond']);
        $now = new DateTimeImmutable('2026-05-10T12:00:00+02:00');

        $this->assertSame(0, $this->service->sendReminders(now: $now));
        $this->assertSame(0, $this->service->checkEscalations(now: $now));
        $this->assertSame(0, $this->service->checkBreaches(now: $now));
    }//end testResolvedRequestIsIgnored()

    /**
     * A request with no parseable deadline is skipped without error.
     *
     * @return void
     */
    public function testMissingDeadlineIsSkipped(): void
    {
        $this->seedRequest('');
        $now = new DateTimeImmutable('2026-05-10T12:00:00+02:00');

        $this->assertSame(0, $this->service->checkBreaches(now: $now));
        $this->assertSame(0, $this->countEvents('termijn-overschreden'));
    }//end testMissingDeadlineIsSkipped()
}//end class
