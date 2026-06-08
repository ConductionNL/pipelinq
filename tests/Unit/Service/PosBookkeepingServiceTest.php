<?php

/**
 * Unit tests for PosBookkeepingService.
 *
 * Exercises the four-stage end-of-day pipeline (aggregate -> stage -> POST ->
 * event emit) end-to-end against in-memory fakes of OpenRegister's
 * ObjectService + WebhookService, a stubbed IClientService + IResponse, a
 * fake IJobList and a memoryless IMailer. The real PosAccessPolicy is used
 * so the manager-gate is exercised against a mocked group manager.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#7.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosBookkeepingService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake of the OR ObjectService.
 */
class BookkeepingFakeObjectService
{

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $store = [];

    /**
     * @var integer
     */
    private int $seq = 0;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return $this->store[$schema][$id] ?? null;
    }//end find()

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = $config['filters'] ?? [];
        $schema  = (string) ($filters['schema'] ?? '');
        $rows    = array_values($this->store[$schema] ?? []);

        $filterKeys = ['zReport', 'status'];
        return array_values(
            array_filter(
                $rows,
                function (array $row) use ($filters, $filterKeys): bool {
                    foreach ($filterKeys as $key) {
                        if (isset($filters[$key]) === true && ($row[$key] ?? null) !== $filters[$key]) {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );
    }//end findAll()

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array
    {
        if ($uuid === '') {
            $this->seq++;
            $uuid = $schema.'-'.$this->seq;
        }

        $object['id'] = $uuid;
        $this->store[$schema][$uuid] = $object;
        return $object;
    }//end saveObject()
}//end class

/**
 * Fake WebhookService capturing dispatched CloudEvents.
 */
class BookkeepingFakeWebhookService
{

    /**
     * @var array<int, array{eventName: string, payload: array<string, mixed>}>
     */
    public array $events = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchEvent(object $_event, string $eventName, array $payload): void
    {
        $this->events[] = ['eventName' => $eventName, 'payload' => $payload];
    }//end dispatchEvent()
}//end class

/**
 * Tests for PosBookkeepingService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PosBookkeepingServiceTest extends TestCase
{

    private PosBookkeepingService $service;

    private BookkeepingFakeObjectService $objects;

    private BookkeepingFakeWebhookService $webhooks;

    private IGroupManager $groupManager;

    private IAppConfig $appConfig;

    /**
     * @var array<int, array{uri: string, options: array<string, mixed>}>
     */
    private array $httpCalls = [];

    /**
     * @var integer
     */
    private int $nextHttpStatus = 202;

    /**
     * @var array<string, mixed>
     */
    private array $nextHttpBody = ['message' => 'Accepted', 'eventId' => 'evt-shillinq-test'];

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $nextHttpThrow = null;

    /**
     * @var array<int, string>
     */
    private array $admins = [];

    /**
     * @var array<int, array{className: string, argument: array<string, mixed>}>
     */
    private array $jobScheduled = [];

    /**
     * @var array<int, array{to: string, subject: string, body: string}>
     */
    private array $emailsSent = [];

    /**
     * @var array<string, string>
     */
    private array $appConfigStore = [
        'register'                   => 'reg',
        'pos_eod.shillinq_endpoint'  => 'https://shillinq.example.org',
        'pos_eod.shillinq_token'     => 'sk_test_xyz',
        'pos_eod.alert_email'        => 'accounting@example.org',
        'pos_eod.max_retry_attempts' => '5',
    ];

    protected function setUp(): void
    {
        $this->objects  = new BookkeepingFakeObjectService();
        $this->webhooks = new BookkeepingFakeWebhookService();

        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') {
                if (isset($this->appConfigStore[$key])) {
                    return $this->appConfigStore[$key];
                }

                if ($key === PosAccessPolicy::POS_GROUP_KEY) {
                    return $default !== '' ? $default : PosAccessPolicy::POS_GROUP_DEFAULT;
                }

                if ($key === PosAccessPolicy::MANAGER_GROUP_KEY) {
                    return $default;
                }

                // Every *_schema key resolves to the key itself as a stable id.
                return $key;
            }
        );

        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('isAdmin')->willReturnCallback(
            fn (string $uid): bool => in_array($uid, $this->admins, true)
        );
        $this->groupManager->method('isInGroup')->willReturn(false);

        $policy = new PosAccessPolicy(
            appConfig: $this->appConfig,
            groupManager: $this->groupManager,
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
                function (string $id) {
                    if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                        return $this->objects;
                    }

                    if ($id === 'OCA\OpenRegister\Service\WebhookService') {
                        return $this->webhooks;
                    }

                    throw new \RuntimeException('unknown service '.$id);
                }
                );

        $httpClient = $this->createMock(IClient::class);
        $httpClient->method('post')->willReturnCallback(
            function (string $uri, array $options): IResponse {
                $this->httpCalls[] = ['uri' => $uri, 'options' => $options];
                if ($this->nextHttpThrow !== null) {
                    $err = $this->nextHttpThrow;
                    $this->nextHttpThrow = null;
                    throw $err;
                }

                $response = $this->createMock(IResponse::class);
                $response->method('getStatusCode')->willReturn($this->nextHttpStatus);
                $response->method('getBody')->willReturn(json_encode($this->nextHttpBody));
                return $response;
            }
        );

        $clientService = $this->createMock(IClientService::class);
        $clientService->method('newClient')->willReturn($httpClient);

        $jobList = $this->createMock(IJobList::class);
        $jobList->method('add')->willReturnCallback(
            function (string $className, $argument): void {
                $this->jobScheduled[] = [
                    'className' => $className,
                    'argument'  => is_array($argument) ? $argument : [],
                ];
            }
        );

        $mailer   = $this->createMock(IMailer::class);
        $message  = $this->createMock(IMessage::class);
        $captured = ['to' => '', 'subject' => '', 'body' => ''];
        $message->method('setTo')->willReturnCallback(
            function (array $to) use (&$captured, $message) {
                $captured['to'] = $to[0] ?? '';
                return $message;
            }
        );
        $message->method('setSubject')->willReturnCallback(
            function (string $subject) use (&$captured, $message) {
                $captured['subject'] = $subject;
                return $message;
            }
        );
        $message->method('setPlainBody')->willReturnCallback(
            function (string $body) use (&$captured, $message) {
                $captured['body'] = $body;
                return $message;
            }
        );
        $mailer->method('createMessage')->willReturn($message);
        $mailer->method('send')->willReturnCallback(
            function () use (&$captured): array {
                $this->emailsSent[] = $captured;
                return [];
            }
        );

        $this->service = new PosBookkeepingService(
            $container,
            $this->appConfig,
            $clientService,
            $jobList,
            $mailer,
            $policy,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Make a UID a Nextcloud admin (so the manager predicate passes).
     */
    private function asAdmin(string $uid='boss'): void
    {
        $this->admins[] = $uid;
    }//end asAdmin()

    /**
     * Seed a default GL mapping covering 9% + 21% with a bank clearing account.
     */
    private function seedMapping(): void
    {
        $this->objects->store['glAccountMapping_schema']['mapping-1'] = [
            'id'              => 'mapping-1',
            'name'            => 'Standaard',
            'isDefault'       => true,
            'taxRateMappings' => [
                ['taxRate' => 0,  'debitAccount' => '1200', 'creditAccount' => '5100'],
                ['taxRate' => 9,  'debitAccount' => '1200', 'creditAccount' => '5010'],
                ['taxRate' => 21, 'debitAccount' => '1200', 'creditAccount' => '5000'],
            ],
            'bankAccount'     => '1000',
        ];
    }//end seedMapping()

    /**
     * Seed two confirmed transactions on the same date.
     */
    private function seedTransactions(): void
    {
        $this->objects->store['posTransaction_schema']['txn-1'] = [
            'id'            => 'txn-1',
            'status'        => 'confirmed',
            'terminalId'    => 'kassa-01',
            'subtotal'      => 100.00,
            'discountTotal' => 0.0,
            'totalTax'      => 9.00,
            'total'         => 109.00,
            'taxBreakdown'  => [['rate' => 9, 'base' => 100.00, 'tax' => 9.00]],
            'paymentMethod' => 'cash',
            'settledAt'     => '2026-05-20T12:00:00+00:00',
        ];
        $this->objects->store['posTransaction_schema']['txn-2'] = [
            'id'            => 'txn-2',
            'status'        => 'settled',
            'terminalId'    => 'kassa-01',
            'subtotal'      => 200.00,
            'discountTotal' => 0.0,
            'totalTax'      => 42.00,
            'total'         => 242.00,
            'taxBreakdown'  => [['rate' => 21, 'base' => 200.00, 'tax' => 42.00]],
            'paymentMethod' => 'card',
            'settledAt'     => '2026-05-20T15:00:00+00:00',
        ];
    }//end seedTransactions()

    /**
     * Seed a ready Z-report ready for outbound staging.
     *
     * @return string The Z-report id.
     */
    private function seedZReport(): string
    {
        $this->objects->store['posZReport_schema']['zr-1'] = [
            'id'           => 'zr-1',
            'reference'    => 'Z-2026-05-20-KAS01',
            'reportDate'   => '2026-05-20',
            'terminalId'   => 'kassa-01',
            'subtotal'     => 300.00,
            'totalTax'     => 51.00,
            'total'        => 351.00,
            'taxBreakdown' => [
                ['rate' => 9,  'base' => 100.00, 'tax' => 9.00],
                ['rate' => 21, 'base' => 200.00, 'tax' => 42.00],
            ],
            'status'       => 'ready',
        ];
        return 'zr-1';
    }//end seedZReport()

    /**
     * generateZReport aggregates the day's confirmed/settled transactions.
     *
     * @return void
     */
    public function testGenerateZReportAggregatesConfirmedAndSettled(): void
    {
        $this->seedTransactions();

        $report = $this->service->generateZReport(reportDate: '2026-05-20', terminalId: 'kassa-01');

        $this->assertSame(2, $report['transactionCount']);
        $this->assertSame(300.00, $report['subtotal']);
        $this->assertSame(51.00, $report['totalTax']);
        $this->assertSame(351.00, $report['total']);
        $this->assertSame('ready', $report['status']);
        $this->assertSame('Z-2026-05-20-KASSA-01', $report['reference']);

        // CloudEvent emitted.
        $this->assertNotEmpty($this->webhooks->events);
        $this->assertSame(PosBookkeepingService::EVENT_ZREPORT_SUBMITTED, $this->webhooks->events[0]['eventName']);
    }//end testGenerateZReportAggregatesConfirmedAndSettled()

    /**
     * generateZReport produces a draft zero-value report when no transactions exist.
     *
     * @return void
     */
    public function testGenerateZReportEmptyDayProducesDraft(): void
    {
        $report = $this->service->generateZReport(reportDate: '2026-05-23');

        $this->assertSame(0, $report['transactionCount']);
        $this->assertSame(0.0, $report['total']);
        $this->assertSame('draft', $report['status']);
    }//end testGenerateZReportEmptyDayProducesDraft()

    /**
     * generateZReport excludes draft / refunded transactions.
     *
     * @return void
     */
    public function testGenerateZReportExcludesNonConfirmed(): void
    {
        $this->seedTransactions();
        // Add a draft txn on the same day; must NOT contribute to the Z-report.
        $this->objects->store['posTransaction_schema']['txn-3'] = [
            'id'           => 'txn-3',
            'status'       => 'draft',
            'terminalId'   => 'kassa-01',
            'total'        => 99.99,
            'taxBreakdown' => [],
            'settledAt'    => '2026-05-20T16:00:00+00:00',
        ];

        $report = $this->service->generateZReport(reportDate: '2026-05-20', terminalId: 'kassa-01');

        $this->assertSame(2, $report['transactionCount']);
        $this->assertSame(351.00, $report['total']);
    }//end testGenerateZReportExcludesNonConfirmed()

    /**
     * createOutboundMessage stages balanced GL line items and a deterministic key.
     *
     * @return void
     */
    public function testCreateOutboundMessageStagesBalancedGlLines(): void
    {
        $this->seedMapping();
        $zid = $this->seedZReport();

        $outbound = $this->service->createOutboundMessage(zReportId: $zid);

        $this->assertSame('draft', $outbound['status']);
        $this->assertSame(0, $outbound['attemptCount']);
        $this->assertSame('2026-05-20', $outbound['postingDate']);
        $this->assertStringStartsWith('sha256:', $outbound['idempotencyKey']);

        $lines = $outbound['ledgerLineItems'];
        $this->assertNotEmpty($lines);

        $debit  = 0.0;
        $credit = 0.0;
        foreach ($lines as $l) {
            $debit  += (float) ($l['debit'] ?? 0);
            $credit += (float) ($l['credit'] ?? 0);
        }

        $this->assertEqualsWithDelta($debit, $credit, 0.001, 'GL lines must balance');
    }//end testCreateOutboundMessageStagesBalancedGlLines()

    /**
     * createOutboundMessage refuses when GL mapping for a rate is missing.
     *
     * @return void
     */
    public function testCreateOutboundMessageMissingMappingThrows(): void
    {
        // Mapping covers only 9% — the Z-report's 21% rate is unmapped.
        $this->objects->store['glAccountMapping_schema']['mapping-1'] = [
            'id'              => 'mapping-1',
            'name'            => 'Partial',
            'isDefault'       => true,
            'taxRateMappings' => [
                ['taxRate' => 9, 'debitAccount' => '1200', 'creditAccount' => '5010'],
            ],
            'bankAccount'     => '1000',
        ];
        $this->seedZReport();

        $this->expectException(OCSBadRequestException::class);
        $this->service->createOutboundMessage('zr-1');
    }//end testCreateOutboundMessageMissingMappingThrows()

    /**
     * computeIdempotencyKey is deterministic for the same Z-report and date.
     *
     * @return void
     */
    public function testComputeIdempotencyKeyIsDeterministicAndUnique(): void
    {
        $a = ['id' => 'zr-1', 'reportDate' => '2026-05-20'];
        $b = ['id' => 'zr-1', 'reportDate' => '2026-05-20'];
        $c = ['id' => 'zr-2', 'reportDate' => '2026-05-20'];

        $keyA = $this->service->computeIdempotencyKey($a);
        $keyB = $this->service->computeIdempotencyKey($b);
        $keyC = $this->service->computeIdempotencyKey($c);

        $this->assertSame($keyA, $keyB, 'idempotency key MUST be deterministic for same input');
        $this->assertNotSame($keyA, $keyC, 'different Z-reports MUST produce different keys');
        $this->assertStringStartsWith('sha256:', $keyA);
    }//end testComputeIdempotencyKeyIsDeterministicAndUnique()

    /**
     * postToShillinq fails closed for a non-manager.
     *
     * @return void
     */
    public function testPostToShillinqRequiresManager(): void
    {
        $this->seedMapping();
        $this->seedZReport();
        $out = $this->service->createOutboundMessage('zr-1');

        $this->expectException(OCSForbiddenException::class);
        $this->service->postToShillinq($out['id'], 'clerk');
    }//end testPostToShillinqRequiresManager()

    /**
     * postToShillinq with 202 transitions to posted and emits the CloudEvent.
     *
     * @return void
     */
    public function testPostToShillinqSuccessTransitionsToPosted(): void
    {
        $this->asAdmin();
        $this->seedMapping();
        $this->seedZReport();
        $out = $this->service->createOutboundMessage('zr-1');

        $this->nextHttpStatus = 202;
        $this->nextHttpBody   = [
            'message'        => 'Accepted',
            'eventId'        => 'evt-shillinq-001',
            'journalEntryId' => 'je-shillinq-001',
            'glReference'    => 'GL-2026-05-20-001',
        ];

        $result = $this->service->postToShillinq($out['id'], 'boss');

        $this->assertSame('posted', $result['status']);
        $this->assertSame(1, $result['attemptCount']);
        $this->assertSame('evt-shillinq-001', $result['shillinqEventId']);
        $this->assertSame('je-shillinq-001', $result['shillinqJournalEntryId']);
        $this->assertSame('GL-2026-05-20-001', $result['glReference']);

        // Idempotency + Bearer headers were sent.
        $this->assertNotEmpty($this->httpCalls);
        $headers = $this->httpCalls[0]['options']['headers'] ?? [];
        $this->assertSame($out['idempotencyKey'], $headers['X-Idempotency-Key'] ?? null);
        $this->assertSame('Bearer sk_test_xyz', $headers['Authorization'] ?? null);

        // Z-report transitioned to posted.
        $this->assertSame('posted', $this->objects->store['posZReport_schema']['zr-1']['status']);

        // pipelinq.PosJournalEntry.posted CloudEvent emitted.
        $eventNames = array_column($this->webhooks->events, 'eventName');
        $this->assertContains(PosBookkeepingService::EVENT_JOURNAL_POSTED, $eventNames);
    }//end testPostToShillinqSuccessTransitionsToPosted()

    /**
     * postToShillinq with 422 marks failed terminally and sends alert.
     *
     * @return void
     */
    public function testPostToShillinq422IsTerminalFailureWithAlert(): void
    {
        $this->asAdmin();
        $this->seedMapping();
        $this->seedZReport();
        $out = $this->service->createOutboundMessage('zr-1');

        $this->nextHttpStatus = 422;
        $this->nextHttpBody   = ['message' => 'Unprocessable Entity: GL account 1999 does not exist'];

        $result = $this->service->postToShillinq($out['id'], 'boss');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('422', $result['lastErrorCode']);
        $this->assertNull($result['nextRetryAt']);
        $this->assertSame('failed', $this->objects->store['posZReport_schema']['zr-1']['status']);

        // No retry job scheduled, alert sent.
        $this->assertEmpty($this->jobScheduled);
        $this->assertNotEmpty($this->emailsSent);
        $this->assertSame('accounting@example.org', $this->emailsSent[0]['to']);
    }//end testPostToShillinq422IsTerminalFailureWithAlert()

    /**
     * postToShillinq with 503 schedules an exponential-backoff retry.
     *
     * @return void
     */
    public function testPostToShillinq503SchedulesBackoffRetry(): void
    {
        $this->asAdmin();
        $this->seedMapping();
        $this->seedZReport();
        $out = $this->service->createOutboundMessage('zr-1');

        $this->nextHttpStatus = 503;
        $this->nextHttpBody   = ['message' => 'Service Unavailable'];

        $result = $this->service->postToShillinq($out['id'], 'boss');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('503', $result['lastErrorCode']);
        $this->assertNotEmpty($result['nextRetryAt']);
        $this->assertCount(1, $this->jobScheduled);
        $this->assertSame(
            'OCA\Pipelinq\BackgroundJob\PosRetryBackoffJob',
            $this->jobScheduled[0]['className']
        );
        $this->assertSame($out['id'], $this->jobScheduled[0]['argument']['outboundMessageId']);
    }//end testPostToShillinq503SchedulesBackoffRetry()

    /**
     * Exponential backoff schedule matches the 1min/5min/15min/1hr table.
     *
     * @return void
     */
    public function testScheduleNextRetryFollowsBackoffSchedule(): void
    {
        $now = time();

        $next1 = strtotime($this->service->scheduleNextRetry(1));
        $next2 = strtotime($this->service->scheduleNextRetry(2));
        $next3 = strtotime($this->service->scheduleNextRetry(3));
        $next4 = strtotime($this->service->scheduleNextRetry(4));
        $next5 = strtotime($this->service->scheduleNextRetry(5));

        // Allow 5s slack for execution time.
        $this->assertGreaterThanOrEqual($now + 55, $next1);
        $this->assertLessThan($now + 70, $next1);
        $this->assertGreaterThanOrEqual($now + 295, $next2);
        $this->assertLessThan($now + 310, $next2);
        $this->assertGreaterThanOrEqual($now + 895, $next3);
        $this->assertLessThan($now + 910, $next3);
        $this->assertGreaterThanOrEqual($now + 3595, $next4);
        $this->assertLessThan($now + 3610, $next4);
        // 5th retry caps at the last entry (1hr).
        $this->assertGreaterThanOrEqual($now + 3595, $next5);
    }//end testScheduleNextRetryFollowsBackoffSchedule()

    /**
     * After max attempts a transient failure becomes terminal.
     *
     * @return void
     */
    public function testPostToShillinqMaxAttemptsBecomesTerminal(): void
    {
        $this->asAdmin();
        $this->seedMapping();
        $this->seedZReport();
        $out = $this->service->createOutboundMessage('zr-1');

        // Pre-stamp 4 prior attempts so this 5th attempt hits the cap.
        $out['attemptCount']       = 4;
        $out['submissionAttempts'] = [];
        $this->objects->store['posJournalEntryOutbound_schema'][$out['id']] = $out;

        $this->nextHttpStatus = 503;
        $this->nextHttpBody   = ['message' => 'Service Unavailable'];

        $result = $this->service->postToShillinq($out['id'], 'boss');

        $this->assertSame('failed', $result['status']);
        $this->assertSame(5, $result['attemptCount']);
        $this->assertNull($result['nextRetryAt']);
        $this->assertEmpty($this->jobScheduled, 'no further retries after max attempts');
        $this->assertNotEmpty($this->emailsSent, 'alert sent on max-attempts');
    }//end testPostToShillinqMaxAttemptsBecomesTerminal()

    /**
     * postToShillinq refuses when no Shillinq endpoint is configured.
     *
     * @return void
     */
    public function testPostToShillinqRequiresEndpoint(): void
    {
        $this->asAdmin();
        $this->appConfigStore['pos_eod.shillinq_endpoint'] = '';
        $this->seedMapping();
        $this->seedZReport();
        $out = $this->service->createOutboundMessage('zr-1');

        $this->expectException(OCSBadRequestException::class);
        $this->service->postToShillinq($out['id'], 'boss');
    }//end testPostToShillinqRequiresEndpoint()

    /**
     * postToShillinq treats a thrown exception as transient (NETWORK_ERROR) by default.
     *
     * @return void
     */
    public function testPostToShillinqNetworkExceptionIsTransient(): void
    {
        $this->asAdmin();
        $this->seedMapping();
        $this->seedZReport();
        $out = $this->service->createOutboundMessage('zr-1');

        $this->nextHttpThrow = new \RuntimeException('Connection reset');

        $result = $this->service->postToShillinq($out['id'], 'boss');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('NETWORK_ERROR', $result['lastErrorCode']);
        $this->assertNotEmpty($result['nextRetryAt']);
        $this->assertNotEmpty($this->jobScheduled);
    }//end testPostToShillinqNetworkExceptionIsTransient()

    /**
     * Missing default GL mapping triggers an alert and refuses staging.
     *
     * @return void
     */
    public function testCreateOutboundMessageWithoutMappingAlerts(): void
    {
        $this->seedZReport();

        $this->expectException(OCSNotFoundException::class);
        try {
            $this->service->createOutboundMessage('zr-1');
        } finally {
            $this->assertNotEmpty($this->emailsSent, 'an alert email must be sent when no default mapping exists');
        }
    }//end testCreateOutboundMessageWithoutMappingAlerts()
}//end class
