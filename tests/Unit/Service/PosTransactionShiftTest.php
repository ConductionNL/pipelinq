<?php

/**
 * Unit tests for the open-shift lookup a confirmed sale is linked through.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A sale confirmed at a till with an open shift is linked to that shift.
 *
 * @spec openspec/changes/pos-sale-in-shift/specs/pos-transaction-core/spec.md
 */
class PosTransactionShiftTest extends TestCase {
	/**
	 * The filters the lookup sent to OpenRegister.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $sentFilters = null;

	/**
	 * A service whose OpenRegister returns the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows findAll returns.
	 *
	 * @return PosTransactionService The service.
	 */
	private function serviceReturning(array $rows): PosTransactionService {
		$objectService = $this->createMock(ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(function (array $config) use ($rows): array {
			$this->sentFilters = $config['filters'];
			return $rows;
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('7');

		return new PosTransactionService(
			$container,
			$appConfig,
			new PosAccessPolicy(appConfig: $appConfig, groupManager: $this->createMock(IGroupManager::class)),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IEventDispatcher::class),
		);
	}//end serviceReturning()

	/**
	 * Call the private lookup.
	 *
	 * @param PosTransactionService $service     The service.
	 * @param array<string, mixed>  $transaction The transaction.
	 *
	 * @return string|null The shift id.
	 */
	private function lookup(PosTransactionService $service, array $transaction): ?string {
		$method = new \ReflectionMethod($service, 'findOpenShiftId');
		$method->setAccessible(true);
		return $method->invoke($service, $transaction, 'pieter');
	}//end lookup()

	/**
	 * A sale at a till finds the open shift on that till.
	 *
	 * @return void
	 */
	public function testSaleAtATillFindsTheOpenShiftOnThatTill(): void {
		$service = $this->serviceReturning(rows: [['id' => 'shift-1']]);
		$this->assertSame('shift-1', $this->lookup(service: $service, transaction: ['terminalId' => 'Balie 2']));
		$this->assertSame('open', $this->sentFilters['status']);
		$this->assertSame('Balie 2', $this->sentFilters['drawer']);
		$this->assertArrayNotHasKey('operator', $this->sentFilters);
	}//end testSaleAtATillFindsTheOpenShiftOnThatTill()

	/**
	 * A sale without a till finds the cashier's open shift.
	 *
	 * @return void
	 */
	public function testSaleWithoutATillFindsTheCashiersOpenShift(): void {
		$service = $this->serviceReturning(rows: [['@self' => ['id' => 'shift-2']]]);
		$this->assertSame('shift-2', $this->lookup(service: $service, transaction: []));
		$this->assertSame('pieter', $this->sentFilters['operator']);
		$this->assertArrayNotHasKey('drawer', $this->sentFilters);
	}//end testSaleWithoutATillFindsTheCashiersOpenShift()

	/**
	 * No open shift leaves the sale unlinked.
	 *
	 * @return void
	 */
	public function testNoOpenShiftLeavesTheSaleUnlinked(): void {
		$this->assertNull($this->lookup(service: $this->serviceReturning(rows: []), transaction: ['terminalId' => 'Balie 2']));
	}//end testNoOpenShiftLeavesTheSaleUnlinked()
}//end class
