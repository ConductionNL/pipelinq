<?php

/**
 * Unit tests for BudgetService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the message-send budget service.
 */
class BudgetServiceTest extends TestCase
{
    /**
     * Mock object service.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The service under test.
     *
     * @var BudgetService
     */
    private BudgetService $service;

    /**
     * Build the service with a fixed set of budgets.
     *
     * @param array<int, array<string, mixed>> $budgets The budgets findAll returns.
     *
     * @return void
     */
    private function withBudgets(array $budgets): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $this->objectService->method('findAll')->willReturn($budgets);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnMap(
            [
                ['pipelinq', 'register', '', 'reg'],
                ['pipelinq', 'messageSendBudget_schema', '', 'budget'],
            ]
        );

        $this->service = new BudgetService(
            $container,
            $appConfig,
            $this->createMock(NotificationService::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(LoggerInterface::class)
        );
    }//end withBudgets()

    /**
     * A hard-stop budget refuses the send past its message cap.
     *
     * @return void
     */
    public function testHardStopRefusesAtCap(): void
    {
        $this->withBudgets(
            [
                ['providerId' => 'p1', 'hardStop' => true, 'maxMessages' => 5000, 'currentPeriodMessages' => 5000, 'periodResetAt' => '2026-07-01T00:00:00Z'],
            ]
        );

        $this->assertFalse($this->service->canSend('p1'));
    }//end testHardStopRefusesAtCap()

    /**
     * A hard-stop budget allows the final send within the cap.
     *
     * @return void
     */
    public function testHardStopAllowsLastSend(): void
    {
        $this->withBudgets(
            [
                ['providerId' => 'p1', 'hardStop' => true, 'maxMessages' => 5000, 'currentPeriodMessages' => 4999, 'periodResetAt' => '2026-07-01T00:00:00Z'],
            ]
        );

        $this->assertTrue($this->service->canSend('p1'));
    }//end testHardStopAllowsLastSend()

    /**
     * A soft-limit budget always allows the send (alert-only).
     *
     * @return void
     */
    public function testSoftLimitAlwaysAllows(): void
    {
        $this->withBudgets(
            [
                ['providerId' => 'p1', 'hardStop' => false, 'maxMessages' => 1000, 'currentPeriodMessages' => 1000, 'periodResetAt' => '2026-07-01T00:00:00Z'],
            ]
        );

        $this->assertTrue($this->service->canSend('p1'));
    }//end testSoftLimitAlwaysAllows()

    /**
     * An unbudgeted provider is always allowed.
     *
     * @return void
     */
    public function testUnbudgetedProviderAllowed(): void
    {
        $this->withBudgets([]);

        $this->assertTrue($this->service->canSend('p-unknown'));
    }//end testUnbudgetedProviderAllowed()

    /**
     * recordSend increments counters and fires the soft-limit alert exactly once.
     *
     * @return void
     */
    public function testRecordSendAlertsOncePerPeriod(): void
    {
        $this->withBudgets(
            [
                ['@self' => ['id' => 'b1'], 'providerId' => 'p1', 'hardStop' => false, 'maxMessages' => 1000, 'alertThresholdPct' => 0.8, 'currentPeriodMessages' => 799, 'currentPeriodCostEur' => 0, 'periodResetAt' => '2026-07-01T00:00:00Z', 'alertedAt' => ''],
            ]
        );

        // First crossing persists alertedAt.
        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            static function (array $object) use (&$saved) {
                $saved = $object;
                return $object;
            }
        );

        $this->service->recordSend('p1', 0.0);

        $this->assertSame(800, $saved['currentPeriodMessages']);
        $this->assertNotSame('', $saved['alertedAt']);
    }//end testRecordSendAlertsOncePerPeriod()

    /**
     * resetElapsedPeriods resets counters and advances the reset date.
     *
     * @return void
     */
    public function testResetElapsedPeriodsAdvances(): void
    {
        $this->withBudgets(
            [
                ['@self' => ['id' => 'b1'], 'providerId' => 'p1', 'period' => 'monthly', 'currentPeriodMessages' => 4500, 'currentPeriodCostEur' => 450, 'periodResetAt' => '2026-06-01T00:00:00+00:00', 'alertedAt' => '2026-05-20T00:00:00Z'],
            ]
        );

        $saved = [];
        $this->objectService->method('saveObject')->willReturnCallback(
            static function (array $object) use (&$saved) {
                $saved = $object;
                return $object;
            }
        );

        $count = $this->service->resetElapsedPeriods(new \DateTimeImmutable('2026-06-02T00:00:00+00:00'));

        $this->assertSame(1, $count);
        $this->assertSame(0, $saved['currentPeriodMessages']);
        $this->assertSame(0, $saved['currentPeriodCostEur']);
        $this->assertSame('', $saved['alertedAt']);
        $this->assertStringStartsWith('2026-07-01', $saved['periodResetAt']);
    }//end testResetElapsedPeriodsAdvances()

    /**
     * A future reset date is not reset.
     *
     * @return void
     */
    public function testFuturePeriodNotReset(): void
    {
        $this->withBudgets(
            [
                ['@self' => ['id' => 'b1'], 'providerId' => 'p1', 'period' => 'monthly', 'currentPeriodMessages' => 10, 'periodResetAt' => '2026-12-01T00:00:00+00:00'],
            ]
        );

        $count = $this->service->resetElapsedPeriods(new \DateTimeImmutable('2026-06-02T00:00:00+00:00'));

        $this->assertSame(0, $count);
    }//end testFuturePeriodNotReset()
}//end class
