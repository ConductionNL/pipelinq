<?php

/**
 * Contract tests for BookingAdminController.
 *
 * Covers the two money/lifecycle endpoints of the staff booking surface:
 * `POST /api/bookings/{id}/complete` (markCompleted) and
 * `POST /api/bookings/{id}/confirm-deposit` (confirmDeposit).
 *
 * The controller is built for real; only the OpenRegister ObjectService is
 * replaced by an in-memory double that mirrors the real service's documented
 * behaviour (see the class docblock on the double for the exact upstream
 * source lines it reproduces). BookingService, WalkInQueueService and
 * ContractService are the REAL classes so that the assertions below measure
 * product behaviour rather than mock behaviour.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BookingAdminController;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\BookingService;
use OCA\Pipelinq\Service\EligibilityService;
use OCA\Pipelinq\Service\WalkInQueueService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * BookingAdminController contract coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A controller contract test
 *  necessarily wires the whole collaborator graph the endpoint touches.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   One happy path plus one
 *  contract-relevant failure path per endpoint, plus the fan-out measurement.
 */
class BookingAdminControllerTest extends TestCase
{
    /**
     * In-memory OpenRegister ObjectService double.
     *
     * @var object
     */
    private object $objects;

    /**
     * The real walk-in queue service (rebalance seam under measurement).
     *
     * @var WalkInQueueService
     */
    private WalkInQueueService $walkIn;

    /**
     * The real booking service.
     *
     * @var BookingService
     */
    private BookingService $bookings;

    /**
     * The user session double.
     *
     * @var IUserSession
     */
    private IUserSession $userSession;

    /**
     * The request double.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * Build the in-memory object store plus the real service graph.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects = $this->buildObjectStore();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id): object {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objects;
                }

                throw new \RuntimeException('not registered: '.$id);
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                $map = [
                    'register'            => 'pipelinq',
                    'booking_schema'      => 'booking',
                    'service_schema'      => 'service',
                    'resource_schema'     => 'resource',
                    'contact_schema'      => 'contact',
                    'walkInTicket_schema' => 'walkInTicket',
                ];

                return ($map[$key] ?? $default);
            }
        );

        // One free slot for every resource, on every day: enough for the ETA
        // computation to produce a value that differs from the seeded tickets'
        // (empty) estimatedReadyAt, so every ticket is genuinely rewritten.
        $availability = $this->createMock(AvailabilityService::class);
        $availability->method('computeAvailability')->willReturn(
            [
                [
                    'startTime'       => '09:00',
                    'endTime'         => '09:30',
                    'durationMinutes' => 30,
                ],
            ]
        );

        $logger            = $this->createMock(LoggerInterface::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->request     = $this->createMock(IRequest::class);

        $this->walkIn = new WalkInQueueService(
            container: $container,
            appConfig: $appConfig,
            availabilityService: $availability,
            logger: $logger,
        );

        $this->bookings = new BookingService(
            container: $container,
            appConfig: $appConfig,
            userSession: $this->userSession,
            availabilityService: $availability,
            eligibilityService: $this->createMock(EligibilityService::class),
            logger: $logger,
        );

        // Exactly the wiring Application::wireBookingWalkInRebalance() performs
        // at boot, so completeBooking() fires the real rebalance.
        $this->bookings->setWalkInQueueRebalance(service: $this->walkIn);
    }//end setUp()

    /**
     * Build the in-memory ObjectService double.
     *
     * Reproduces the parts of OpenRegister's ObjectService contract the code
     * under test depends on:
     *  - register/schema context is taken from `$config['filters']['register']`
     *    and `$config['filters']['schema']` (ObjectService::prepareFindAllConfig);
     *  - the remaining filter keys are object-field equality filters, with the
     *    reserved context keys excluded (MagicSearchHandler::getReservedParams);
     *  - a query with no resolvable register/schema context yields an empty list
     *    (MagicMapper::findAll bails out and returns []);
     *  - soft-deleted rows are excluded from reads;
     *  - `find()` and `saveObject()` carry the real parameter names so the
     *    named-argument call sites under test bind identically.
     *
     * @return object The store.
     */
    private function buildObjectStore(): object
    {
        return new class {
            /**
             * When true the double ALSO honours a register/schema supplied at
             * the top level of the config array. The real OpenRegister service
             * does not; this switch exists only so a test can measure what a
             * call site would do once its query construction is corrected.
             *
             * @var bool
             */
            public bool $acceptTopLevelContext = false;

            /**
             * Rows keyed by uuid.
             *
             * @var array<string, array<string, mixed>>
             */
            public array $store = [];

            /**
             * Every saveObject() payload, in call order.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $saves = [];

            /**
             * Every findAll() config, in call order.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $queries = [];

            /**
             * Seed one row.
             *
             * @param string               $uuid     Row uuid.
             * @param string               $register Register slug.
             * @param string               $schema   Schema slug.
             * @param array<string, mixed> $data     Row body.
             *
             * @return void
             */
            public function seed(string $uuid, string $register, string $schema, array $data): void
            {
                $data['id']   = $uuid;
                $data['uuid'] = $uuid;
                $data['@self'] = [
                    'id'       => $uuid,
                    'register' => $register,
                    'schema'   => $schema,
                ];
                $this->store[$uuid] = $data;
            }//end seed()

            /**
             * Read one row by id.
             *
             * @param int|string  $id       Object id.
             * @param array|null  $_extend  Extend list.
             * @param bool        $files    Include files.
             * @param mixed       $register Register context.
             * @param mixed       $schema   Schema context.
             *
             * @return array<string, mixed>|null
             */
            public function find(
                int|string $id,
                ?array $_extend=[],
                bool $files=false,
                mixed $register=null,
                mixed $schema=null
            ): ?array {
                $row = ($this->store[(string) $id] ?? null);
                if ($row === null || ($row['_deleted'] ?? null) !== null) {
                    return null;
                }

                return $row;
            }//end find()

            /**
             * Query rows.
             *
             * @param array<string, mixed> $config        Query config.
             * @param bool                 $_rbac         RBAC posture.
             * @param bool                 $_multitenancy Tenancy posture.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array
            {
                $this->queries[] = $config;

                $filters  = ($config['filters'] ?? []);
                $register = (string) ($filters['register'] ?? '');
                $schema   = (string) ($filters['schema'] ?? '');
                if ($this->acceptTopLevelContext === true) {
                    if ($register === '') {
                        $register = (string) ($config['register'] ?? '');
                    }

                    if ($schema === '') {
                        $schema = (string) ($config['schema'] ?? '');
                    }
                }

                if ($register === '' || $schema === '') {
                    // MagicMapper::findAll() logs a warning and returns [] when
                    // no register/schema context could be resolved.
                    return [];
                }

                $reserved = ['register', 'schema', 'registers', 'schemas', 'extend'];
                $fields   = [];
                foreach ($filters as $key => $value) {
                    if (in_array($key, $reserved, true) === true || str_starts_with((string) $key, '_') === true) {
                        continue;
                    }

                    $fields[$key] = $value;
                }

                $out = [];
                foreach ($this->store as $row) {
                    if (($row['_deleted'] ?? null) !== null) {
                        continue;
                    }

                    if ((string) ($row['@self']['register'] ?? '') !== $register) {
                        continue;
                    }

                    if ((string) ($row['@self']['schema'] ?? '') !== $schema) {
                        continue;
                    }

                    $matches = true;
                    foreach ($fields as $key => $value) {
                        if (($row[$key] ?? null) !== $value) {
                            $matches = false;
                            break;
                        }
                    }

                    if ($matches === true) {
                        $out[] = $row;
                    }
                }

                $offset = (int) ($config['offset'] ?? 0);
                $limit  = ($config['limit'] ?? null);
                if ($limit === null) {
                    return array_slice($out, $offset);
                }

                return array_slice($out, $offset, (int) $limit);
            }//end findAll()

            /**
             * Count rows.
             *
             * @param array<string, mixed> $config Query config.
             *
             * @return int
             */
            public function count(array $config=[]): int
            {
                return count($this->findAll(config: $config));
            }//end count()

            /**
             * Write one row.
             *
             * @param array<string, mixed> $object   Payload.
             * @param array|null           $extend   Extend list.
             * @param mixed                $register Register context.
             * @param mixed                $schema   Schema context.
             * @param string|null          $uuid     Target uuid.
             *
             * @return array<string, mixed>
             */
            public function saveObject(
                array $object,
                ?array $extend=[],
                mixed $register=null,
                mixed $schema=null,
                ?string $uuid=null
            ): array {
                $key = ($uuid ?? ('new-'.(count($this->store) + 1)));
                $object['id']    = $key;
                $object['uuid']  = $key;
                $object['@self'] = [
                    'id'       => $key,
                    'register' => (string) $register,
                    'schema'   => (string) $schema,
                ];
                $this->store[$key] = $object;
                $this->saves[]     = $object;
                return $object;
            }//end saveObject()
        };
    }//end buildObjectStore()

    /**
     * Build the controller under test.
     *
     * @param AppointmentEmailService|null $email Optional email seam double.
     *
     * @return BookingAdminController
     */
    private function buildController(?AppointmentEmailService $email=null): BookingAdminController
    {
        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

        return new BookingAdminController(
            request: $this->request,
            bookings: $this->bookings,
            emailService: ($email ?? $this->createMock(AppointmentEmailService::class)),
            userSession: $this->userSession,
            l10n: $l10n,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end buildController()

    /**
     * Sign a staff user in.
     *
     * @return void
     */
    private function signIn(): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('staff-1');
        $this->userSession->method('getUser')->willReturn($user);
    }//end signIn()

    /**
     * Seed the service + resource rows the walk-in ETA computation reads.
     *
     * @return void
     */
    private function seedScheduleFixtures(): void
    {
        $this->objects->seed(
            'svc-1',
            'pipelinq',
            'service',
            ['name' => 'Haircut', 'durationMinutes' => 30]
        );
        $this->objects->seed(
            'res-1',
            'pipelinq',
            'resource',
            ['name' => 'Chair 1', 'bookable' => true, 'status' => 'active', 'type' => 'chair']
        );
    }//end seedScheduleFixtures()

    /**
     * Seed `$count` waiting walk-in tickets.
     *
     * @param int $count How many tickets to seed.
     *
     * @return void
     */
    private function seedWaitingQueue(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->objects->seed(
                'ticket-'.$i,
                'pipelinq',
                'walkInTicket',
                [
                    'status'           => 'waiting',
                    'serviceId'        => 'svc-1',
                    'estimatedReadyAt' => '',
                    'arrivedAt'        => '2026-01-01T08:00:00+00:00',
                ]
            );
        }
    }//end seedWaitingQueue()

    /**
     * POST /api/bookings/{id}/complete returns 200 with `{completed: true}`
     * and persists the new status.
     *
     * @return void
     */
    public function testMarkCompletedReturnsOkAndPersistsCompletedStatus(): void
    {
        $this->signIn();
        $this->objects->seed(
            'bk-1',
            'pipelinq',
            'booking',
            ['status' => 'confirmed', 'customerId' => 'cust-1', 'serviceId' => 'svc-1']
        );

        $response = $this->buildController()->markCompleted(id: 'bk-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['completed' => true], $response->getData());
        $this->assertSame('completed', $this->objects->store['bk-1']['status']);
        $this->assertNotEmpty($this->objects->store['bk-1']['statusHistory']);
    }//end testMarkCompletedReturnsOkAndPersistsCompletedStatus()

    /**
     * Completing an already-completed booking is refused by the state machine
     * with 422 and does not rewrite the row.
     *
     * @return void
     */
    public function testMarkCompletedRejectsTerminalBookingWith422(): void
    {
        $this->signIn();
        $this->objects->seed('bk-2', 'pipelinq', 'booking', ['status' => 'completed']);

        $response = $this->buildController()->markCompleted(id: 'bk-2');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertSame([], $this->objects->saves);
    }//end testMarkCompletedRejectsTerminalBookingWith422()

    /**
     * Unauthenticated completion is refused with 401 and writes nothing.
     *
     * @return void
     */
    public function testMarkCompletedRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->markCompleted(id: 'bk-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Authentication required'], $response->getData());
        $this->assertSame([], $this->objects->saves);
    }//end testMarkCompletedRequiresAuthentication()

    /**
     * MEASUREMENT (as shipped) — with a deep waiting queue seeded, the
     * completion request currently issues exactly ONE write: the booking.
     *
     * The walk-in rebalance is invoked inline by completeBooking(), but every
     * query it makes supplies its register/schema outside `filters`, which the
     * ObjectService contract does not read — so the queue read resolves no
     * context and yields nothing. The fan-out is therefore latent, not absent.
     * See the sibling test for the write count it produces once the query keys
     * are corrected.
     *
     * @return void
     */
    public function testMarkCompletedIssuesOnlyTheBookingWriteWhileTheQueueReadIsMisKeyed(): void
    {
        $this->signIn();
        $this->seedScheduleFixtures();
        $this->seedWaitingQueue(count: WalkInQueueService::QUEUE_PAGE_SIZE);
        $this->objects->seed('bk-3', 'pipelinq', 'booking', ['status' => 'confirmed', 'serviceId' => 'svc-1']);

        $response = $this->buildController()->markCompleted(id: 'bk-3');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(1, $this->objects->saves);
        $this->assertSame('completed', $this->objects->saves[0]['status']);

        // Not one waiting ticket was re-read or rewritten, even though 200 of
        // them are sitting in the store.
        foreach ($this->objects->store as $uuid => $row) {
            if (str_starts_with((string) $uuid, 'ticket-') === true) {
                $this->assertSame('', $row['estimatedReadyAt']);
            }
        }
    }//end testMarkCompletedIssuesOnlyTheBookingWriteWhileTheQueueReadIsMisKeyed()

    /**
     * MEASUREMENT (once the queue query keys are corrected) — the synchronous
     * walk-in rebalance fan-out inside a single completion request.
     *
     * completeBooking() calls the rebalance seam inline, which walks every
     * waiting walk-in ticket (capped at WalkInQueueService::QUEUE_PAGE_SIZE)
     * and issues one schedule query plus one write per ticket, all inside the
     * HTTP request that completes the booking. This test pins the resulting
     * write count for a full queue so a later asynchronous fix can be proven to
     * change it.
     *
     * @return void
     */
    public function testMarkCompletedFansOutOneWritePerWaitingTicketInsideTheRequest(): void
    {
        $this->signIn();
        $this->objects->acceptTopLevelContext = true;
        $this->seedScheduleFixtures();
        $this->seedWaitingQueue(count: WalkInQueueService::QUEUE_PAGE_SIZE);
        $this->objects->seed('bk-3', 'pipelinq', 'booking', ['status' => 'confirmed', 'serviceId' => 'svc-1']);

        $response = $this->buildController()->markCompleted(id: 'bk-3');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());

        $ticketWrites = 0;
        foreach ($this->objects->saves as $payload) {
            if (($payload['status'] ?? '') === 'waiting') {
                $ticketWrites++;
            }
        }

        // One write for the booking itself, plus one for every waiting ticket.
        $this->assertSame(WalkInQueueService::QUEUE_PAGE_SIZE, $ticketWrites);
        $this->assertCount((WalkInQueueService::QUEUE_PAGE_SIZE + 1), $this->objects->saves);

        // And at least one schedule query per ticket on top of the writes.
        $this->assertGreaterThanOrEqual(
            WalkInQueueService::QUEUE_PAGE_SIZE,
            count($this->objects->queries)
        );
    }//end testMarkCompletedFansOutOneWritePerWaitingTicketInsideTheRequest()

    /**
     * A rebalance that throws must not change what the completion endpoint
     * reports: the booking really was completed, so 200 `{completed: true}` is
     * the honest answer. This test records what the caller actually observes
     * when the queue fan-out fails half-way.
     *
     * @return void
     */
    public function testMarkCompletedStillReportsSuccessWhenTheQueueRebalanceThrows(): void
    {
        $this->signIn();
        $this->objects->seed('bk-4', 'pipelinq', 'booking', ['status' => 'confirmed']);

        $exploding = new class {
            /**
             * Always fail.
             *
             * @return int
             */
            public function rebalance(): int
            {
                throw new \RuntimeException('queue backend down');
            }
        };
        $this->bookings->setWalkInQueueRebalance(service: $exploding);

        $response = $this->buildController()->markCompleted(id: 'bk-4');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['completed' => true], $response->getData());
        // The booking write itself succeeded before the seam ran.
        $this->assertSame('completed', $this->objects->store['bk-4']['status']);
    }//end testMarkCompletedStillReportsSuccessWhenTheQueueRebalanceThrows()

    /**
     * POST /api/bookings/{id}/confirm-deposit returns 200 `{confirmed: true}`
     * and stamps the confirmation without ever reading an amount from the
     * request body.
     *
     * @return void
     */
    public function testConfirmDepositReturnsOkAndUsesServerSideBookingRecord(): void
    {
        $this->signIn();
        $this->objects->seed(
            'bk-5',
            'pipelinq',
            'booking',
            [
                'status'        => 'pending-deposit',
                'depositAmount' => 25.0,
                'customerId'    => 'cust-1',
            ]
        );

        // A client that tries to dictate the deposit amount.
        $this->request->method('getParam')->willReturnCallback(
            static function (string $key, mixed $default=null): mixed {
                $body = [
                    'reason'        => 'Cash at counter',
                    'depositAmount' => 0.01,
                    'amount'        => 0.01,
                ];

                return ($body[$key] ?? $default);
            }
        );

        $response = $this->buildController()->confirmDeposit(id: 'bk-5');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['confirmed' => true], $response->getData());

        $saved = $this->objects->store['bk-5'];
        $this->assertSame('confirmed', $saved['status']);
        // The stored amount is the one the server already held, never the body's.
        $this->assertSame(25.0, $saved['depositAmount']);
        $this->assertNotEmpty($saved['confirmationSentAt']);
    }//end testConfirmDepositReturnsOkAndUsesServerSideBookingRecord()

    /**
     * Confirming a deposit twice must be idempotent — the second call must not
     * append a second confirmation entry nor rewrite the row.
     *
     * @return void
     */
    public function testConfirmDepositIsIdempotentOnASecondCall(): void
    {
        $this->signIn();
        $this->objects->seed('bk-6', 'pipelinq', 'booking', ['status' => 'pending-deposit', 'depositAmount' => 40.0]);
        $this->request->method('getParam')->willReturnCallback(
            static fn (string $key, mixed $default=null): mixed => ($key === 'reason' ? 'Cash at counter' : $default)
        );

        $controller = $this->buildController();

        $first = $controller->confirmDeposit(id: 'bk-6');
        $this->assertSame(Http::STATUS_OK, $first->getStatus());
        $writesAfterFirst  = count($this->objects->saves);
        $historyAfterFirst = count($this->objects->store['bk-6']['statusHistory']);

        $second = $controller->confirmDeposit(id: 'bk-6');

        $this->assertSame(Http::STATUS_OK, $second->getStatus());
        $this->assertSame(['confirmed' => true], $second->getData());
        $this->assertCount($writesAfterFirst, $this->objects->saves);
        $this->assertCount($historyAfterFirst, $this->objects->store['bk-6']['statusHistory']);
        $this->assertSame(40.0, $this->objects->store['bk-6']['depositAmount']);
    }//end testConfirmDepositIsIdempotentOnASecondCall()

    /**
     * Confirming an unknown booking maps to a 500 error envelope carrying no
     * internal detail (the service raises RuntimeException, not
     * InvalidArgumentException, for a missing row).
     *
     * @return void
     */
    public function testConfirmDepositOnUnknownBookingReturnsErrorEnvelope(): void
    {
        $this->signIn();
        $this->request->method('getParam')->willReturn('');

        $response = $this->buildController()->confirmDeposit(id: 'does-not-exist');

        $this->assertGreaterThanOrEqual(400, $response->getStatus());
        $this->assertArrayHasKey('error', $response->getData());
        $this->assertSame([], $this->objects->saves);
    }//end testConfirmDepositOnUnknownBookingReturnsErrorEnvelope()

    /**
     * Unauthenticated deposit confirmation is refused with 401 and writes
     * nothing.
     *
     * @return void
     */
    public function testConfirmDepositRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->confirmDeposit(id: 'bk-5');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame(['error' => 'Authentication required'], $response->getData());
        $this->assertSame([], $this->objects->saves);
    }//end testConfirmDepositRequiresAuthentication()
}//end class
