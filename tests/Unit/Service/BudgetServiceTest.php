<?php

/**
 * Unit tests for BudgetService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BudgetService — hard-stop, soft-alert, period reset,
 * cost accumulation.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#8.4
 */
class BudgetServiceTest extends TestCase
{
    private ContainerInterface $container;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;
    private NotificationService $notificationService;
    private object $objectService;
    private BudgetService $service;

    /**
     * setUp — minimal OR mock + notification stub.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container           = $this->createMock(ContainerInterface::class);
        $this->appConfig           = $this->createMock(IAppConfig::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->notificationService = $this->createMock(NotificationService::class);

        $this->objectService = new class {
            /** @var array<string, array<string, mixed>> */
            public array $store = [];

            /** @var int */
            public int $nextId = 0;

            /**
             * Mock saveObject.
             *
             * @param array       $object   Payload.
             * @param mixed       $register Register.
             * @param mixed       $schema   Schema.
             * @param string|null $uuid     Id.
             *
             * @return array<string, mixed>
             */
            public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array
            {
                if ($uuid === null || $uuid === '') {
                    $uuid = (string) ($object['uuid'] ?? '');
                }
                if ($uuid === '') {
                    $uuid = ('budget-' . (++$this->nextId));
                }
                $object['uuid']        = $uuid;
                $this->store[$uuid]    = $object;
                return $object;
            }

            /**
             * Mock findAll — mirrors OR's real ObjectService::findAll(array $config).
             *
             * The register/schema context travels INSIDE $config['filters']; OR
             * treats both as reserved params, never as object-field filters.
             *
             * @param array<string, mixed> $config Config with a `filters` map.
             *
             * @return array<int, array<string, mixed>>
             */
            public function findAll(array $config = []): array
            {
                $filters = $config['filters'] ?? [];
                unset($filters['register'], $filters['schema']);

                $out = [];
                foreach ($this->store as $row) {
                    foreach ($filters as $k => $v) {
                        if (($row[$k] ?? null) !== $v) {
                            continue 2;
                        }
                    }
                    $out[] = $row;
                }
                return $out;
            }
        };

        $this->container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objectService;
                }
                throw new \RuntimeException('not registered: ' . $id);
            }
        );

        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default) {
                return match ($key) {
                    'register'                  => 'pipelinq',
                    'messageSendBudget_schema'  => 'messageSendBudget',
                    default                     => $default,
                };
            }
        );

        $this->service = new BudgetService(
            $this->container,
            $this->appConfig,
            $this->notificationService,
            $this->logger,
        );
    }//end setUp()

    /**
     * canSend returns true when no budget row exists.
     *
     * @return void
     */
    public function testCanSendDefaultsToTrueWithoutBudget(): void
    {
        $this->assertTrue($this->service->canSend('tenant-1', 'provider-1'));
    }//end testCanSendDefaultsToTrueWithoutBudget()

    /**
     * canSend respects a hard-stop cap on messages.
     *
     * @return void
     */
    public function testCanSendBlocksOnHardStopMessageCap(): void
    {
        $this->objectService->saveObject([
            'tenantId'              => 'tenant-1',
            'providerId'            => 'provider-1',
            'period'                => 'monthly',
            'maxMessages'           => 5,
            'hardStop'              => true,
            'currentPeriodMessages' => 5,
        ]);

        $this->assertFalse($this->service->canSend('tenant-1', 'provider-1'));
    }//end testCanSendBlocksOnHardStopMessageCap()

    /**
     * canSend allows when hardStop is false even at the cap (alert-only).
     *
     * @return void
     */
    public function testCanSendAllowsSoftLimit(): void
    {
        $this->objectService->saveObject([
            'tenantId'              => 'tenant-1',
            'providerId'            => 'provider-1',
            'period'                => 'monthly',
            'maxMessages'           => 5,
            'hardStop'              => false,
            'currentPeriodMessages' => 5,
        ]);

        $this->assertTrue($this->service->canSend('tenant-1', 'provider-1'));
    }//end testCanSendAllowsSoftLimit()

    /**
     * recordSend on a soft-limit budget fires exactly one alert per
     * period.
     *
     * @return void
     */
    public function testRecordSendFiresAlertOncePerPeriod(): void
    {
        $this->objectService->saveObject([
            'tenantId'              => 'tenant-1',
            'providerId'            => 'provider-1',
            'period'                => 'monthly',
            'maxMessages'           => 10,
            'alertThresholdPct'     => 0.8,
            'hardStop'              => false,
            'currentPeriodMessages' => 7,
        ]);

        $this->notificationService->expects($this->once())
            ->method('sendNotification');

        // Crosses 8 — should alert.
        $this->service->recordSend('tenant-1', 'provider-1');
        // Stays above, but already alerted.
        $this->service->recordSend('tenant-1', 'provider-1');
        $this->service->recordSend('tenant-1', 'provider-1');
    }//end testRecordSendFiresAlertOncePerPeriod()

    /**
     * recordSend advances the running counters.
     *
     * @return void
     */
    public function testRecordSendAdvancesCounters(): void
    {
        $this->objectService->saveObject([
            'uuid'                  => 'bud-1',
            'tenantId'              => 'tenant-1',
            'providerId'            => 'provider-1',
            'period'                => 'monthly',
            'currentPeriodMessages' => 0,
            'currentPeriodCostEur'  => 0.0,
        ]);

        $this->service->recordSend('tenant-1', 'provider-1', 0.5);

        $row = $this->objectService->store['bud-1'];
        $this->assertSame(1, $row['currentPeriodMessages']);
        $this->assertEqualsWithDelta(0.5, $row['currentPeriodCostEur'], 0.000001);
    }//end testRecordSendAdvancesCounters()

    /**
     * resetPeriods rolls a budget whose periodResetAt has passed.
     *
     * @return void
     */
    public function testResetPeriodsRollsExpiredBudget(): void
    {
        $this->objectService->saveObject([
            'uuid'                  => 'bud-1',
            'tenantId'              => 'tenant-1',
            'providerId'            => 'provider-1',
            'period'                => 'monthly',
            'currentPeriodMessages' => 42,
            'currentPeriodCostEur'  => 12.5,
            'alertedAtPeriodStart'  => '2026-05-15T00:00:00Z',
            'periodResetAt'         => '2026-05-15T00:00:00Z',
        ]);

        $reset = $this->service->resetPeriods();

        $this->assertSame(1, $reset);
        $row = $this->objectService->store['bud-1'];
        $this->assertSame(0, $row['currentPeriodMessages']);
        $this->assertSame(0, $row['currentPeriodCostEur']);
        $this->assertSame('', $row['alertedAtPeriodStart']);
        $this->assertNotSame('2026-05-15T00:00:00Z', $row['periodResetAt']);
    }//end testResetPeriodsRollsExpiredBudget()

    /**
     * resetPeriods leaves a future budget untouched.
     *
     * @return void
     */
    public function testResetPeriodsSkipsFutureBudget(): void
    {
        $future = gmdate('Y-m-d\TH:i:s\Z', (time() + (86400 * 30)));

        $this->objectService->saveObject([
            'uuid'                  => 'bud-2',
            'tenantId'              => 'tenant-1',
            'providerId'            => 'provider-1',
            'period'                => 'monthly',
            'currentPeriodMessages' => 5,
            'periodResetAt'         => $future,
        ]);

        $reset = $this->service->resetPeriods();
        $this->assertSame(0, $reset);
        $this->assertSame(5, $this->objectService->store['bud-2']['currentPeriodMessages']);
    }//end testResetPeriodsSkipsFutureBudget()
}//end class
