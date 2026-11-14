<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use LogicException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\Pipelinq\Event\PosStockMovedEvent;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosTenderService;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Fake OR ObjectService for the coverage tests: answers find() from a queue
 * (falling back to a default object), answers findAll() from a callback and
 * records every save.
 */
class TxCovObjectService {

	/**
	 * Queued find() answers; a Throwable entry is thrown, anything else returned.
	 *
	 * @var array<int, mixed>
	 */
	public array $findQueue = [];

	/**
	 * Answer for find() once the queue is drained (Throwable is thrown).
	 *
	 * @var mixed
	 */
	public mixed $defaultFind = null;

	/**
	 * Per-id answers that take precedence over the queue (Throwable is thrown).
	 *
	 * @var array<string, mixed>
	 */
	public array $findById = [];

	/**
	 * Answer for findAll() (Throwable is thrown).
	 *
	 * @var mixed
	 */
	public mixed $findAllResult = [];

	/**
	 * Every find() id, in call order.
	 *
	 * @var array<int, string>
	 */
	public array $findCalls = [];

	/**
	 * Every findAll() config, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $findAllCalls = [];

	/**
	 * Every saveObject() call, in call order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saveCalls = [];

	/**
	 * Answer a find() lookup.
	 *
	 * @param string $id The object id.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return mixed The configured answer.
	 */
	public function find(string $id, string $register = '', string $schema = ''): mixed {
		$this->findCalls[] = $id;
		if (array_key_exists($id, $this->findById) === true) {
			$answer = $this->findById[$id];
		} elseif ($this->findQueue !== []) {
			$answer = array_shift($this->findQueue);
		} else {
			$answer = $this->defaultFind;
		}

		if ($answer instanceof Throwable) {
			throw $answer;
		}

		return $answer;
	}//end find()

	/**
	 * Answer a findAll() query.
	 *
	 * @param array<string, mixed> $config The query config.
	 *
	 * @return mixed The configured rows.
	 */
	public function findAll(array $config = []): mixed {
		$this->findAllCalls[] = $config;
		if ($this->findAllResult instanceof Throwable) {
			throw $this->findAllResult;
		}

		return $this->findAllResult;
	}//end findAll()

	/**
	 * Record and echo a save.
	 *
	 * @param array<string, mixed> $object The payload.
	 * @param array<string, mixed>|null $extend Extend list.
	 * @param string|int|null $register The register id.
	 * @param string|int|null $schema The schema id.
	 * @param string|null $uuid The uuid.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
	): array {
		$this->saveCalls[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
		return $object;
	}//end saveObject()
}//end class

/**
 * Fake OR TransitionEngine recording each transition and answering or throwing.
 */
class TxCovTransitionEngine {

	/**
	 * Recorded [objectId, action] pairs.
	 *
	 * @var array<int, array<int, string>>
	 */
	public array $calls = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>|Throwable $answer What transition() yields.
	 */
	public function __construct(
		public array|Throwable $answer = [],
	) {
	}//end __construct()

	/**
	 * Apply (record) a transition.
	 *
	 * @param string $objectId The object id.
	 * @param string $action The action.
	 *
	 * @return array<string, mixed> The configured answer.
	 */
	public function transition(string $objectId, string $action): array {
		$this->calls[] = [$objectId, $action];
		if ($this->answer instanceof Throwable) {
			throw $this->answer;
		}

		return $this->answer;
	}//end transition()
}//end class

/**
 * Coverage tests for PosTransactionService: transition wrappers, error
 * mapping, tax report, settle side effects and config fallbacks.
 *
 * @covers \OCA\Pipelinq\Service\PosTransactionService
 * @uses \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 * @uses \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 * @uses \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @uses \OCA\Pipelinq\Event\PosStockMovedEvent
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\SerialisedPayload
 */
class PosTransactionServiceCoverageTest extends TestCase {

	/**
	 * The fake ObjectService.
	 *
	 * @var TxCovObjectService
	 */
	private TxCovObjectService $objects;

	/**
	 * The fake TransitionEngine.
	 *
	 * @var TxCovTransitionEngine
	 */
	private TxCovTransitionEngine $engine;

	/**
	 * Container services by id (Throwable entries are thrown).
	 *
	 * @var array<string, mixed>
	 */
	private array $services = [];

	/**
	 * App-config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Logged messages as "level: message".
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Group manager mock.
	 *
	 * @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * Event dispatcher mock.
	 *
	 * @var IEventDispatcher&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IEventDispatcher $dispatcher;

	/**
	 * Set up the default wiring.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new TxCovObjectService();
		$this->objects->defaultFind = ['id' => 'txn-1', 'consentSyncStatus' => 'pending', 'reference' => 'R-1'];
		$this->engine = new TxCovTransitionEngine(['id' => 'txn-1', 'status' => 'done']);
		$this->services = [
			'OCA\OpenRegister\Service\ObjectService' => $this->objects,
			'OCA\OpenRegister\Service\Lifecycle\TransitionEngine' => $this->engine,
		];
		$this->config = [
			'register' => '16',
			'posTransaction_schema' => '41',
			'posTransactionLine_schema' => '42',
			'cashShift_schema' => '43',
		];
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
	}//end setUp()

	/**
	 * Build the service with the current wiring.
	 *
	 * @return PosTransactionService The service.
	 */
	private function service(): PosTransactionService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if (array_key_exists($id, $this->services) === false) {
					throw new RuntimeException('unknown service ' . $id);
				}

				$service = $this->services[$id];
				if ($service instanceof \Closure) {
					$service = $service();
				}

				if ($service instanceof Throwable) {
					throw $service;
				}

				return $service;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message) use ($level): void {
					$this->logged[] = $level . ': ' . $message;
				}
			);
		}

		return new PosTransactionService(
			$container,
			$appConfig,
			new PosAccessPolicy(appConfig: $appConfig, groupManager: $this->groupManager),
			$logger,
			$this->dispatcher,
		);
	}//end service()

	/**
	 * A rate without a fixed Dutch description gets a generated "X% BTW" label.
	 *
	 * @return void
	 */
	public function testUnlistedRateGetsAGeneratedDescription(): void {
		$totals = $this->service()->computeTotals(
			[
				['quantity' => 1, 'unitPrice' => 100, 'taxRate' => 5.5],
				['quantity' => 1, 'unitPrice' => 100, 'taxRate' => 12],
			]
		);

		$this->assertSame('5.5% BTW', $totals['invoiceBreakdown'][0]['description']);
		$this->assertSame('12% BTW', $totals['invoiceBreakdown'][1]['description']);
		$this->assertSame(17.5, $totals['totalTax']);
	}//end testUnlistedRateGetsAGeneratedDescription()

	/**
	 * Malformed breakdowns and rows are skipped rather than counted.
	 *
	 * @return void
	 */
	public function testBuildTaxReportSkipsMalformedBreakdownsAndRows(): void {
		$report = $this->service()->buildTaxReport(
			[
				['status' => 'settled', 'invoiceBreakdown' => 'not-a-list'],
				['status' => 'confirmed', 'invoiceBreakdown' => ['junk', ['rate' => 9, 'base' => 100, 'tax' => 9]]],
			]
		);

		$this->assertSame(1, $report['transactionCount']);
		$this->assertCount(1, $report['rates']);
		$this->assertSame(9.0, $report['rates'][0]['rate']);
		$this->assertSame(100.0, $report['totalBase']);
		$this->assertSame(9.0, $report['totalTax']);
	}//end testBuildTaxReportSkipsMalformedBreakdownsAndRows()

	/**
	 * A non-manager is refused the BTW report before anything is read.
	 *
	 * @return void
	 */
	public function testTaxReportRefusesNonManager(): void {
		$this->groupManager->expects($this->once())->method('isAdmin')->with('kassa')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(false);

		try {
			$this->service()->taxReport('settled', 'kassa');
			$this->fail('Expected OCSForbiddenException');
		} catch (OCSForbiddenException $e) {
			$this->assertSame('Alleen een beheerder mag het BTW-rapport opvragen.', $e->getMessage());
		}

		$this->assertSame([], $this->objects->findAllCalls);
	}//end testTaxReportRefusesNonManager()

	/**
	 * A manager's report reads with the status filter and aggregates the rows.
	 *
	 * @return void
	 */
	public function testTaxReportForManagerFiltersByStatus(): void {
		$this->groupManager->method('isAdmin')->with('baas')->willReturn(true);
		$this->objects->findAllResult = [
			['status' => 'settled', 'invoiceBreakdown' => [['rate' => 21, 'base' => 100, 'tax' => 21]]],
			['status' => 'refunded', 'invoiceBreakdown' => [['rate' => 21, 'base' => 40, 'tax' => 8.4]]],
		];

		$report = $this->service()->taxReport('settled', 'baas');

		$this->assertSame(
			['@self' => ['register' => '16', 'schema' => '41'], 'status' => 'settled'],
			$this->objects->findAllCalls[0]['filters']
		);
		$this->assertSame(2, $report['transactionCount']);
		$this->assertSame(60.0, $report['totalBase']);
		$this->assertSame(12.6, $report['totalTax']);
		$this->assertSame('Standaardtarief (21%)', $report['rates'][0]['description']);
	}//end testTaxReportForManagerFiltersByStatus()

	/**
	 * Without a status no status filter is sent; a failing read yields an empty report.
	 *
	 * @return void
	 */
	public function testTaxReportWithoutStatusAndFailingReadIsEmpty(): void {
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->objects->findAllResult = new RuntimeException('db down');

		$report = $this->service()->taxReport(null, 'baas');

		$this->assertSame(['@self' => ['register' => '16', 'schema' => '41']], $this->objects->findAllCalls[0]['filters']);
		$this->assertSame(['rates' => [], 'totalBase' => 0.0, 'totalTax' => 0.0, 'transactionCount' => 0], $report);
		$this->assertContains('warning: Pipelinq: failed to fetch POS transactions', $this->logged);
	}//end testTaxReportWithoutStatusAndFailingReadIsEmpty()

	/**
	 * Settle gates on the tender balance, stamps settledAt, transitions and posts tenders.
	 *
	 * @return void
	 */
	public function testSettleChecksTendersTransitionsAndEmits(): void {
		$tenders = $this->createMock(PosTenderService::class);
		$tenders->expects($this->once())->method('assertBalancedForSettle')->with('txn-1');
		$tenders->expects($this->once())->method('emitTendersPosted')->with('txn-1')->willReturn([]);
		$this->services[PosTenderService::class] = $tenders;
		$this->engine->answer = ['id' => 'txn-1', 'status' => 'settled'];

		$result = $this->service()->settleTransaction('txn-1', 'kassa');

		$this->assertSame(['id' => 'txn-1', 'status' => 'settled'], $result);
		$this->assertSame([['txn-1', 'settle']], $this->engine->calls);
		$this->assertArrayHasKey('settledAt', $this->objects->saveCalls[0]['object']);
		$this->assertContains('info: Pipelinq: POS transaction settled', $this->logged);
	}//end testSettleChecksTendersTransitionsAndEmits()

	/**
	 * An unbalanced tender sum aborts settle before anything is persisted.
	 *
	 * @return void
	 */
	public function testSettleAbortsWhenTendersDoNotBalance(): void {
		$tenders = $this->createMock(PosTenderService::class);
		$tenders->method('assertBalancedForSettle')->willThrowException(new OCSBadRequestException('unbalanced'));
		$tenders->expects($this->never())->method('emitTendersPosted');
		$this->services[PosTenderService::class] = $tenders;

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('unbalanced');

		try {
			$this->service()->settleTransaction('txn-1', 'kassa');
		} finally {
			$this->assertSame([], $this->objects->saveCalls);
			$this->assertSame([], $this->engine->calls);
		}
	}//end testSettleAbortsWhenTendersDoNotBalance()

	/**
	 * Failing tender and stock emissions never abort a completed settle.
	 *
	 * @return void
	 */
	public function testSettleSurvivesFailingTenderAndStockEmission(): void {
		$tenders = $this->createMock(PosTenderService::class);
		$tenders->method('emitTendersPosted')->willThrowException(new RuntimeException('shillinq offline'));
		$this->services[PosTenderService::class] = $tenders;
		// Line schema unconfigured and the slug fallback fails -> emitStockMovedEvent throws.
		unset($this->config['posTransactionLine_schema']);
		$this->services['OCA\OpenRegister\Db\SchemaMapper'] = new RuntimeException('mapper down');
		$this->engine->answer = ['id' => 'txn-1', 'status' => 'settled'];

		$result = $this->service()->settleTransaction('txn-1', 'kassa');

		$this->assertSame('settled', $result['status']);
		$this->assertContains('warning: Pipelinq: tender CloudEvent emission failed; retry job will pick up', $this->logged);
		$this->assertContains('warning: Pipelinq: could not resolve POS schema slug to id (config fallback)', $this->logged);
		$this->assertContains('warning: Pipelinq: stock-moved CloudEvent emission failed', $this->logged);
	}//end testSettleSurvivesFailingTenderAndStockEmission()

	/**
	 * Settle proceeds without a tender gate when the container has no usable tender service.
	 *
	 * @return void
	 */
	public function testSettleWithoutUsableTenderService(): void {
		// A non-PosTenderService answer is ignored just like a missing one.
		$this->services[PosTenderService::class] = new \stdClass();
		$this->engine->answer = ['id' => 'txn-1', 'status' => 'settled'];

		$result = $this->service()->settleTransaction('txn-1', 'kassa');
		$this->assertSame('settled', $result['status']);

		unset($this->services[PosTenderService::class]);
		$result = $this->service()->settleTransaction('txn-1', 'kassa');
		$this->assertSame('settled', $result['status']);
		$this->assertSame([['txn-1', 'settle'], ['txn-1', 'settle']], $this->engine->calls);
	}//end testSettleWithoutUsableTenderService()

	/**
	 * Lines whose product cannot be resolved carry an empty sku / unit.
	 *
	 * @return void
	 */
	public function testStockMovedEventWithUnresolvableProducts(): void {
		$this->config['product_schema'] = '9';
		$this->objects->findById['p-gone'] = new RuntimeException('deleted');
		$this->objects->findById['p-null'] = null;
		$this->objects->findAllResult = [
			['product' => '', 'quantity' => 2],
			['product' => 'p-gone', 'quantity' => 1],
			['product' => 'p-null', 'quantity' => 3],
		];

		$captured = null;
		$this->dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function (PosStockMovedEvent $event) use (&$captured): void {
				$captured = $event;
			}
		);

		$eventId = $this->service()->emitStockMovedEvent('txn-1');

		$this->assertNotSame('', $eventId);
		$this->assertInstanceOf(PosStockMovedEvent::class, $captured);
		$this->assertSame($eventId, $captured->getEventId());
		$this->assertSame('default', $captured->getAdministrationId());
		$this->assertSame('R-1', $captured->getTransactionReference());
		$this->assertSame(
			[
				['productRef' => '', 'qty' => 2.0, 'unit' => '', 'location' => ''],
				['productRef' => '', 'qty' => 1.0, 'unit' => '', 'location' => ''],
				['productRef' => '', 'qty' => 3.0, 'unit' => '', 'location' => ''],
			],
			$captured->getLines()
		);
	}//end testStockMovedEventWithUnresolvableProducts()

	/**
	 * Without a configured product schema the product is never looked up.
	 *
	 * @return void
	 */
	public function testStockMovedEventSkipsLookupWithoutProductSchema(): void {
		$this->objects->findAllResult = [['product' => 'p-1', 'quantity' => 1]];
		$this->dispatcher->expects($this->once())->method('dispatchTyped');

		$this->service()->emitStockMovedEvent('txn-1');

		$this->assertSame(['txn-1'], $this->objects->findCalls);
	}//end testStockMovedEventSkipsLookupWithoutProductSchema()

	/**
	 * A blank refund reason is refused before the transaction is read.
	 *
	 * @return void
	 */
	public function testRefundRequiresReason(): void {
		try {
			$this->service()->refundTransaction('txn-1', '   ', 'baas');
			$this->fail('Expected OCSBadRequestException');
		} catch (OCSBadRequestException $e) {
			$this->assertSame('Vul een reden in voor de terugboeking.', $e->getMessage());
		}

		$this->assertSame([], $this->objects->findCalls);
	}//end testRefundRequiresReason()

	/**
	 * Refund stamps the reason and transitions through the engine.
	 *
	 * @return void
	 */
	public function testRefundPersistsReasonAndTransitions(): void {
		$this->engine->answer = ['id' => 'txn-1', 'status' => 'refunded'];

		$result = $this->service()->refundTransaction('txn-1', 'Kapot', 'baas');

		$this->assertSame('refunded', $result['status']);
		$this->assertSame('Kapot', $this->objects->saveCalls[0]['object']['refundReason']);
		$this->assertArrayHasKey('refundedAt', $this->objects->saveCalls[0]['object']);
		$this->assertSame([['txn-1', 'refund']], $this->engine->calls);
		$this->assertContains('info: Pipelinq: POS transaction refunded', $this->logged);
	}//end testRefundPersistsReasonAndTransitions()

	/**
	 * Park stamps parkedAt and transitions.
	 *
	 * @return void
	 */
	public function testParkStampsParkedAt(): void {
		$result = $this->service()->parkTransaction('txn-1', 'kassa');

		$this->assertSame(['id' => 'txn-1', 'status' => 'done'], $result);
		$this->assertArrayHasKey('parkedAt', $this->objects->saveCalls[0]['object']);
		$this->assertSame([['txn-1', 'park']], $this->engine->calls);
		$this->assertContains('info: Pipelinq: POS transaction parked', $this->logged);
	}//end testParkStampsParkedAt()

	/**
	 * Resume clears parkedAt (dropped from the payload) and transitions.
	 *
	 * @return void
	 */
	public function testResumeClearsParkedAt(): void {
		$this->objects->defaultFind = ['id' => 'txn-1', 'consentSyncStatus' => 'pending', 'parkedAt' => '2026-01-01T10:00:00+00:00'];

		$this->service()->resumeTransaction('txn-1', 'kassa');

		$this->assertArrayNotHasKey('parkedAt', $this->objects->saveCalls[0]['object']);
		$this->assertSame([['txn-1', 'resume']], $this->engine->calls);
		$this->assertContains('info: Pipelinq: POS transaction resumed', $this->logged);
	}//end testResumeClearsParkedAt()

	/**
	 * An 'op rekening' sale without a customer is refused before anything is saved.
	 *
	 * @return void
	 */
	public function testConfirmOnAccountRequiresCustomer(): void {
		$this->objects->defaultFind = ['id' => 'txn-1', 'tenderType' => 'onAccount', 'customer' => ''];

		try {
			$this->service()->confirmTransaction('txn-1', 'kassa');
			$this->fail('Expected OCSBadRequestException');
		} catch (OCSBadRequestException $e) {
			$this->assertSame("Klant is verplicht voor 'op rekening' transacties.", $e->getMessage());
		}

		$this->assertSame([], $this->objects->saveCalls);
		$this->assertSame([], $this->engine->calls);
	}//end testConfirmOnAccountRequiresCustomer()

	/**
	 * Confirm survives failing line and shift reads and an absent webhook consumer.
	 *
	 * @return void
	 */
	public function testConfirmWithFailingReadsLeavesSaleUnlinked(): void {
		$this->objects->findAllResult = new RuntimeException('index down');
		$this->engine->answer = ['id' => 'txn-1', 'status' => 'confirmed'];

		$result = $this->service()->confirmTransaction('txn-1', 'kassa');

		$this->assertSame(['id' => 'txn-1', 'status' => 'confirmed'], $result);
		$persisted = $this->objects->saveCalls[0]['object'];
		$this->assertArrayNotHasKey('shift', $persisted);
		$this->assertSame(0.0, $persisted['total']);
		$this->assertContains('warning: Pipelinq: failed to fetch POS lines', $this->logged);
		$this->assertContains('warning: Pipelinq: no open shift looked up for the sale', $this->logged);
		// Only the pre-transition save: no event id came back, so no re-save.
		$this->assertCount(1, $this->objects->saveCalls);
	}//end testConfirmWithFailingReadsLeavesSaleUnlinked()

	/**
	 * Engine failures map to the matching OCS exception.
	 *
	 * @return array<string, array{0: Throwable, 1: class-string, 2: string}>
	 */
	public static function transitionErrorProvider(): array {
		return [
			'RBAC without message' => [new NotAuthorizedException(''), OCSForbiddenException::class, 'Geen toegang tot deze transactie.'],
			'permission message' => [new RuntimeException('No permission to update'), OCSForbiddenException::class, 'No permission to update'],
			'guard denial' => [new RuntimeException('U bent niet gemachtigd'), OCSForbiddenException::class, 'U bent niet gemachtigd'],
			'manager denial' => [new RuntimeException('Alleen een beheerder mag dit'), OCSForbiddenException::class, 'Alleen een beheerder mag dit'],
			'invalid state' => [new RuntimeException("Action 'park' not allowed from 'settled'"), OCSBadRequestException::class, "Action 'park' not allowed from 'settled'"],
			'undeclared' => [new RuntimeException('Action not declared'), OCSBadRequestException::class, 'Action not declared'],
			'empty cart' => [new RuntimeException('Voeg eerst een artikel toe'), OCSBadRequestException::class, 'Voeg eerst een artikel toe'],
			'missing' => [new RuntimeException('Object not found'), OCSNotFoundException::class, 'Object not found'],
			'other' => [new LogicException('boom'), LogicException::class, 'boom'],
		];
	}//end transitionErrorProvider()

	/**
	 * Engine failures map to the matching OCS exception.
	 *
	 * @param Throwable $thrown The engine failure.
	 * @param class-string $expected The expected exception class.
	 * @param string $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider transitionErrorProvider
	 */
	public function testTransitionErrorsAreMapped(Throwable $thrown, string $expected, string $message): void {
		$this->engine->answer = $thrown;

		try {
			$this->service()->parkTransaction('txn-1', 'kassa');
			$this->fail('Expected ' . $expected);
		} catch (Throwable $e) {
			$this->assertSame($expected, get_class($e));
			$this->assertSame($message, $e->getMessage());
		}

		if ($expected === LogicException::class) {
			$this->assertSame($thrown, $e);
		}
	}//end testTransitionErrorsAreMapped()

	/**
	 * A missing TransitionEngine surfaces as an unavailability error.
	 *
	 * @return void
	 */
	public function testMissingTransitionEngine(): void {
		$this->services['OCA\OpenRegister\Service\Lifecycle\TransitionEngine'] = new RuntimeException('no engine');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister TransitionEngine is not available.');

		$this->service()->resumeTransaction('txn-1', 'kassa');
	}//end testMissingTransitionEngine()

	/**
	 * A failed re-read before the transition does not mask the engine call.
	 *
	 * @return void
	 */
	public function testSanitizeSkipsWhenRereadFails(): void {
		$this->objects->findQueue = [
			['id' => 'txn-1', 'consentSyncStatus' => null],
			new RuntimeException('gone'),
		];

		$this->service()->parkTransaction('txn-1', 'kassa');

		// Only the park save: the sanitize step had nothing to coerce.
		$this->assertCount(1, $this->objects->saveCalls);
		$this->assertSame([['txn-1', 'park']], $this->engine->calls);
	}//end testSanitizeSkipsWhenRereadFails()

	/**
	 * A failing find surfaces as a Dutch 404.
	 *
	 * @return void
	 */
	public function testFindFailureIsNotFound(): void {
		$this->objects->defaultFind = new RuntimeException('db down');

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('Transactie niet gevonden.');

		$this->service()->parkTransaction('txn-1', 'kassa');
	}//end testFindFailureIsNotFound()

	/**
	 * An unconfigured register is refused with a 404.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterIsRefused(): void {
		$this->config['register'] = '';

		$this->expectException(OCSNotFoundException::class);
		$this->expectExceptionMessage('POS register of schema is niet geconfigureerd.');

		$this->service()->parkTransaction('txn-1', 'kassa');
	}//end testUnconfiguredRegisterIsRefused()

	/**
	 * An ObjectService that disappears mid-request surfaces as unavailable on save.
	 *
	 * @return void
	 */
	public function testObjectServiceUnavailableOnSave(): void {
		// The fetch resolves the ObjectService; the next lookup (the save) fails.
		$objects = $this->objects;
		$calls = 0;
		$this->services['OCA\OpenRegister\Service\ObjectService'] = static function () use (&$calls, $objects) {
			$calls++;
			if ($calls === 1) {
				return $objects;
			}

			return new RuntimeException('container reset');
		};

		try {
			$this->service()->parkTransaction('txn-1', 'kassa');
			$this->fail('Expected RuntimeException');
		} catch (RuntimeException $e) {
			$this->assertSame('OpenRegister service is not available.', $e->getMessage());
		}

		$this->assertSame(2, $calls);
		$this->assertSame([], $this->objects->saveCalls);
	}//end testObjectServiceUnavailableOnSave()
}//end class
