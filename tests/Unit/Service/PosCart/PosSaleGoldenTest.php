<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/till-server-priced-lines/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\PosCart;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Listener\PosLinePricingListener;
use OCA\Pipelinq\Service\PosCart\PosCartAccess;
use OCA\Pipelinq\Service\PosCart\PosCartObjects;
use OCA\Pipelinq\Service\PosCart\PosCartRepricer;
use OCA\Pipelinq\Service\PosCart\PosLinePricer;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Golden comparison: a sale priced line by line on the server books the same figures as before.
 *
 * tests/fixtures/pos-sale-golden-expected.json was written by development's
 * PosTransactionService (recalculateLine, computeTotals, buildTaxReport) before
 * the till moved its pricing to the server. Every path the till now takes must
 * land on exactly those figures: the line totals, the VAT per rate, the totals,
 * the booked confirm event and the BTW report.
 */
class PosSaleGoldenTest extends TestCase {
	/**
	 * Schema and register ids by app config key.
	 */
	private const IDS = ['register' => '7', 'posTransaction_schema' => 'tx', 'posTransactionLine_schema' => 'line', 'cashShift_schema' => 'shift'];

	private PosSaleStore $store;

	private PosTransactionService $pos;

	private PosCartRepricer $repricer;

	/**
	 * The confirm event payloads dispatched.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $booked = [];

	protected function setUp(): void {
		$this->store = new PosSaleStore();
		$appConfig   = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => (self::IDS[$key] ?? $default));
		$policy    = new PosAccessPolicy(appConfig: $appConfig, groupManager: $this->createMock(IGroupManager::class));
		$this->pos = new PosTransactionService($this->container(), $appConfig, $policy, $this->createMock(LoggerInterface::class), $this->createMock(IEventDispatcher::class));
		$pricer    = new PosLinePricer(pos: $this->pos, objects: new PosCartObjects(objects: $this->contractObjects(), appConfig: $appConfig));
		$this->store->hook = [new PosLinePricingListener(pricer: $pricer), 'handle'];
		$this->repricer    = new PosCartRepricer(pricer: $pricer, access: new PosCartAccess(policy: $policy, userSession: $this->session()));
	}//end setUp()

	/**
	 * The fixture sales.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function sales(): array {
		return array_map(static fn (string $name): array => [$name], array_combine(array_keys(self::input()), array_keys(self::input())));
	}//end sales()

	#[DataProvider('sales')]
	public function testDevelopmentArithmeticStillGivesTheGolden(string $sale): void {
		$input = self::input()[$sale];
		$lines = array_map(fn (array $line): array => array_intersect_key($this->pos->recalculateLine(lineData: $line, priceMode: $input['priceMode']), ['taxAmount' => 0, 'lineTotal' => 0]), $input['lines']);

		$this->assertSame(self::golden()['sales'][$sale]['lines'], $lines);
		$this->assertSame(self::golden()['sales'][$sale]['totals'], $this->pos->computeTotals(lines: $input['lines'], priceMode: $input['priceMode']));
	}//end testDevelopmentArithmeticStillGivesTheGolden()

	#[DataProvider('sales')]
	public function testLinesStoredWithClientFiguresGetTheGoldenFigures(string $sale): void {
		$this->ringUp(sale: $sale, priceMode: self::input()[$sale]['priceMode']);

		$this->assertSame(self::golden()['sales'][$sale]['lines'], $this->storedLineFigures(sale: $sale));
	}//end testLinesStoredWithClientFiguresGetTheGoldenFigures()

	#[DataProvider('sales')]
	public function testRecalculateStoresTheGoldenTotals(string $sale): void {
		$this->ringUp(sale: $sale, priceMode: self::input()[$sale]['priceMode']);

		$priced = $this->repricer->reprice(transactionId: $sale);

		$golden = self::golden()['sales'][$sale]['totals'];
		$this->assertSame($golden, array_combine(array_keys($golden), array_map(static fn (string $key): mixed => ($priced['transaction'][$key] ?? null), array_keys($golden))));
		$this->assertCount(count(self::input()[$sale]['lines']), $priced['lines']);
	}//end testRecalculateStoresTheGoldenTotals()

	#[DataProvider('sales')]
	public function testSwitchingThePriceDisplayRepricesTheStoredLines(string $sale): void {
		$mode = self::input()[$sale]['priceMode'];
		$this->ringUp(sale: $sale, priceMode: $mode === 'incl' ? 'excl' : 'incl');
		$this->store->save(object: array_merge($this->store->row($sale), ['priceMode' => $mode]), schema: 'tx', uuid: $sale);

		$this->repricer->reprice(transactionId: $sale);

		$this->assertSame(self::golden()['sales'][$sale]['lines'], $this->storedLineFigures(sale: $sale));
	}//end testSwitchingThePriceDisplayRepricesTheStoredLines()

	#[DataProvider('sales')]
	public function testConfirmBooksTheGoldenFigures(string $sale): void {
		$this->ringUp(sale: $sale, priceMode: self::input()[$sale]['priceMode']);
		$this->repricer->reprice(transactionId: $sale);

		$this->pos->confirmTransaction(id: $sale, userId: 'pieter');

		$totals = self::golden()['sales'][$sale]['totals'];
		$booked = $this->booked[0]['data'];
		$this->assertSame([$totals['total'], $totals['totalTax'], $totals['priceMode'], $totals['taxBreakdown'], $totals['invoiceBreakdown']], [$booked['total'], $booked['totalTax'], $booked['priceMode'], $booked['taxBreakdown'], $booked['invoiceBreakdown']]);
	}//end testConfirmBooksTheGoldenFigures()

	public function testTheTaxReportOverTheConfirmedSalesIsTheGolden(): void {
		foreach (array_keys(self::input()) as $sale) {
			$this->ringUp(sale: $sale, priceMode: self::input()[$sale]['priceMode']);
			$this->pos->confirmTransaction(id: $sale, userId: 'pieter');
		}

		$confirmed = $this->store->findAll(config: ['filters' => ['schema' => 'tx']]);
		$this->assertSame(self::golden()['taxReport'], $this->pos->buildTaxReport(transactions: $confirmed));
	}//end testTheTaxReportOverTheConfirmedSalesIsTheGolden()

	/**
	 * Store a draft sale and its lines the way the till does, each line carrying figures a client made up.
	 *
	 * @param string $sale      The fixture name, used as the sale's id.
	 * @param string $priceMode The sale's price display.
	 *
	 * @return void
	 */
	private function ringUp(string $sale, string $priceMode): void {
		$this->store->save(object: ['status' => 'draft', 'cashier' => 'pieter', 'priceMode' => $priceMode, 'total' => 1.0], schema: 'tx', uuid: $sale);
		foreach (self::input()[$sale]['lines'] as $order => $line) {
			$this->store->save(object: array_merge($line, ['transaction' => $sale, 'sortOrder' => $order, 'taxAmount' => 999.0, 'lineTotal' => 999.0]), schema: 'line');
		}
	}//end ringUp()

	/**
	 * The stored taxAmount and lineTotal per line of a sale, in till order.
	 *
	 * @param string $sale The sale's id.
	 *
	 * @return array<int, array{taxAmount: float, lineTotal: float}> The figures.
	 */
	private function storedLineFigures(string $sale): array {
		$lines = $this->store->findAll(config: ['filters' => ['schema' => 'line', 'transaction' => $sale]]);
		usort($lines, static fn (array $a, array $b): int => $a['sortOrder'] <=> $b['sortOrder']);
		return array_map(static fn (array $line): array => ['taxAmount' => $line['taxAmount'], 'lineTotal' => $line['lineTotal']], $lines);
	}//end storedLineFigures()

	/**
	 * The DI container PosTransactionService reaches OpenRegister through.
	 *
	 * @return ContainerInterface The container.
	 */
	private function container(): ContainerInterface {
		$services  = [
			'OCA\OpenRegister\Service\ObjectService'               => $this->legacyObjects(),
			'OCA\OpenRegister\Service\Lifecycle\TransitionEngine' => $this->engine(),
			'OCA\OpenRegister\Service\WebhookService'              => $this->webhook(),
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => ($services[$id] ?? throw new RuntimeException('no '.$id)));
		return $container;
	}//end container()

	/**
	 * OpenRegister's object service as PosTransactionService calls it.
	 *
	 * @return ObjectService The double.
	 */
	private function legacyObjects(): ObjectService {
		return $this->objectsDouble(class: ObjectService::class);
	}//end legacyObjects()

	/**
	 * OpenRegister's object service contract as PosCartObjects calls it.
	 *
	 * @return ObjectServiceInterface The double.
	 */
	private function contractObjects(): ObjectServiceInterface {
		return $this->objectsDouble(class: ObjectServiceInterface::class);
	}//end contractObjects()

	/**
	 * An object service double over the store.
	 *
	 * @param class-string $class The class or interface to double.
	 *
	 * @return object The double.
	 */
	private function objectsDouble(string $class): object {
		$objects = $this->createMock($class);
		$objects->method('find')->willReturnCallback(fn (string|int $id): ?object => $this->store->entity((string)$id));
		$objects->method('findAll')->willReturnCallback(fn (array $config = []): array => $this->store->findAll(config: $config));
		$objects->method('saveObject')->willReturnCallback(fn (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): object => PosSaleStore::makeEntity(object: $this->store->save(object: $object, schema: (string)$schema, uuid: $uuid), schema: (string)$schema));
		return $objects;
	}//end objectsDouble()

	/**
	 * The lifecycle engine: confirm moves the stored sale to confirmed.
	 *
	 * @return TransitionEngine The double.
	 */
	private function engine(): TransitionEngine {
		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('transition')->willReturnCallback(fn (string $objectId): object => PosSaleStore::makeEntity(object: $this->store->save(object: array_merge($this->store->row($objectId), ['status' => 'confirmed']), schema: 'tx', uuid: $objectId), schema: 'tx'));
		return $engine;
	}//end engine()

	/**
	 * The webhook service: records the booked confirm event.
	 *
	 * @return WebhookService The double.
	 */
	private function webhook(): WebhookService {
		$webhook = $this->createMock(WebhookService::class);
		$webhook->method('dispatchEvent')->willReturnCallback(function ($event, string $name, array $payload): void {
			$this->booked[] = $payload;
		});
		return $webhook;
	}//end webhook()

	/**
	 * The signed-in cashier.
	 *
	 * @return IUserSession The session.
	 */
	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('pieter');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return $session;
	}//end session()

	/**
	 * The fixture sales.
	 *
	 * @return array<string, array{priceMode: string, lines: array<int, array<string, mixed>>}>
	 */
	private static function input(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../fixtures/pos-sale-golden-input.json'), true);
	}//end input()

	/**
	 * The figures development's code produced for the fixture sales.
	 *
	 * @return array<string, mixed>
	 */
	private static function golden(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../fixtures/pos-sale-golden-expected.json'), true);
	}//end golden()
}//end class
