<?php

/**
 * Unit tests for PosRefundManagerGuard.
 *
 * Covers the manager gate and the over-refund cap on `complete`: missing or
 * unknown original receipt, missing configuration, the sum of sibling
 * completed refunds (excluding the refund itself), the cent tolerance and the
 * fail-closed path when the sibling lookup throws.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Lifecycle;

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Lifecycle\PosRefundManagerGuard;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A minimal ObjectService fake for the refund guard.
 */
class RefundGuardFakeObjectService {
	/**
	 * Transactions by id.
	 *
	 * @var array<string,mixed>
	 */
	public array $transactions = [];

	/**
	 * Completed refunds returned by findAll.
	 *
	 * @var array<int,mixed>
	 */
	public array $refunds = [];

	/**
	 * Exception findAll throws, if any.
	 *
	 * @var \Throwable|null
	 */
	public ?\Throwable $findAllFailure = null;

	/**
	 * Recorded find() arguments.
	 *
	 * @var array<int,array<string,string>>
	 */
	public array $findCalls = [];

	/**
	 * Recorded findAll() configs.
	 *
	 * @var array<int,array>
	 */
	public array $findAllCalls = [];

	/**
	 * Find one object.
	 *
	 * @param string $id       The id.
	 * @param string $register The register.
	 * @param string $schema   The schema.
	 *
	 * @return mixed The object or null.
	 */
	public function find(string $id, string $register, string $schema): mixed {
		$this->findCalls[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
		if ($id === 'throws') {
			throw new RuntimeException('lookup failed');
		}

		return ($this->transactions[$id] ?? null);
	}//end find()

	/**
	 * Find objects.
	 *
	 * @param array $config The query.
	 *
	 * @return array The refunds.
	 */
	public function findAll(array $config): array {
		$this->findAllCalls[] = $config;
		if ($this->findAllFailure !== null) {
			throw $this->findAllFailure;
		}

		return $this->refunds;
	}//end findAll()
}//end class

/**
 * @covers \OCA\Pipelinq\Lifecycle\PosRefundManagerGuard
 * @uses   \OCA\Pipelinq\Lifecycle\PosAccessPolicy
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroupGate
 * @uses   \OCA\Pipelinq\Lifecycle\ConfiguredGroup
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class PosRefundManagerGuardTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string,string>
	 */
	private array $config = [
		'register'              => 'reg',
		'posTransaction_schema' => 'tx',
		'posRefund_schema'      => 'refund',
		'pos_manager_group'     => 'pos-managers',
	];

	/**
	 * The fake ObjectService.
	 *
	 * @var RefundGuardFakeObjectService
	 */
	private RefundGuardFakeObjectService $objects;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up fakes.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = new RefundGuardFakeObjectService();
		$this->objects->transactions['tx-1'] = ['id' => 'tx-1', 'total' => 100.0];
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the guard; `manager` is in the manager group, `clerk` is not.
	 *
	 * @return PosRefundManagerGuard The guard.
	 */
	private function guard(): PosRefundManagerGuard {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => ($uid === 'manager' && $group === 'pos-managers')
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);

		return new PosRefundManagerGuard(
			policy: new PosAccessPolicy(appConfig: $appConfig, groupManager: $groupManager),
			container: $container,
			appConfig: $appConfig,
			logger: $this->logger,
		);
	}//end guard()

	/**
	 * A non-manager is refused before anything is looked up.
	 *
	 * @return void
	 */
	public function testNonManagerIsDenied(): void {
		$result = $this->guard()->check(['originalTransaction' => 'tx-1', 'refundAmount' => 1], 'complete', 'clerk');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('Alleen een beheerder mag een retour verwerken.', $result->getMessage());
		$this->assertSame([], $this->objects->findCalls);
	}//end testNonManagerIsDenied()

	/**
	 * A manager passes any action other than complete without a cap check.
	 *
	 * @return void
	 */
	public function testManagerPassesNonCompleteActions(): void {
		$result = $this->guard()->check(['refundAmount' => 999], 'cancel', 'manager');

		$this->assertTrue($result->isAllowed());
		$this->assertSame([], $this->objects->findCalls);
	}//end testManagerPassesNonCompleteActions()

	/**
	 * Completing without an original receipt is refused.
	 *
	 * @return void
	 */
	public function testCompleteWithoutOriginalTransactionIsDenied(): void {
		$result = $this->guard()->check(['refundAmount' => 1], 'complete', 'manager');

		$this->assertFalse($result->isAllowed());
		$this->assertSame('Originele kassabon ontbreekt; retour kan niet worden bevestigd.', $result->getMessage());
	}//end testCompleteWithoutOriginalTransactionIsDenied()

	/**
	 * An unknown, unreadable or unconfigured original receipt is refused.
	 *
	 * @return void
	 */
	public function testCompleteWithUnresolvableOriginalIsDenied(): void {
		$expected = 'Originele kassabon niet gevonden. Kan retour niet verwerken.';

		$unknown = $this->guard()->check(['originalTransaction' => 'nope'], 'complete', 'manager');
		$this->assertFalse($unknown->isAllowed());
		$this->assertSame($expected, $unknown->getMessage());
		$this->assertSame(['id' => 'nope', 'register' => 'reg', 'schema' => 'tx'], $this->objects->findCalls[0]);

		$throws = $this->guard()->check(['originalTransaction' => 'throws'], 'complete', 'manager');
		$this->assertSame($expected, $throws->getMessage());

		$this->config['posTransaction_schema'] = '';
		$calls = count($this->objects->findCalls);
		$unconfigured = $this->guard()->check(['originalTransaction' => 'tx-1'], 'complete', 'manager');
		$this->assertSame($expected, $unconfigured->getMessage());
		$this->assertCount($calls, $this->objects->findCalls);
	}//end testCompleteWithUnresolvableOriginalIsDenied()

	/**
	 * A refund within the original total is allowed; siblings are queried with the exact filters.
	 *
	 * @return void
	 */
	public function testRefundWithinTheOriginalTotalIsAllowed(): void {
		$this->objects->refunds = [
			['id' => 'r-old', 'refundAmount' => 30, 'totalTax' => 6.3],
		];

		$result = $this->guard()->check(
			['id' => 'r-new', 'originalTransaction' => 'tx-1', 'refundAmount' => 50, 'totalTax' => 10.5],
			'complete',
			'manager'
		);

		$this->assertTrue($result->isAllowed());
		$this->assertSame(
			[
				[
					'filters' => [
						'register'            => 'reg',
						'schema'              => 'refund',
						'originalTransaction' => 'tx-1',
						'status'              => 'completed',
					],
				],
			],
			$this->objects->findAllCalls
		);
	}//end testRefundWithinTheOriginalTotalIsAllowed()

	/**
	 * Siblings plus this refund above the original total are refused with both amounts.
	 *
	 * @return void
	 */
	public function testRefundsAboveTheOriginalTotalAreDenied(): void {
		$this->objects->refunds = [
			['id' => 'r-old', 'refundAmount' => 50, 'totalTax' => 10],
			['uuid' => 'r-older', 'refundAmount' => 1000, 'totalTax' => 0],
		];

		$result = $this->guard()->check(
			['id' => 'r-new', 'originalTransaction' => 'tx-1', 'refundAmount' => 40, 'totalTax' => 0],
			'complete',
			'manager'
		);

		$this->assertFalse($result->isAllowed());
		$this->assertSame(
			'Retourvolume (€1.100,00) overschrijdt originele totaal (€100,00).',
			$result->getMessage()
		);
	}//end testRefundsAboveTheOriginalTotalAreDenied()

	/**
	 * The refund being completed is not counted twice when it is already among the siblings.
	 *
	 * @return void
	 */
	public function testTheRefundItselfIsExcludedFromTheSiblingSum(): void {
		$this->objects->refunds = [
			['uuid' => 'r-self', 'refundAmount' => 80, 'totalTax' => 0],
		];

		$result = $this->guard()->check(
			['uuid' => 'r-self', 'originalTransaction' => 'tx-1', 'refundAmount' => 80, 'totalTax' => 0],
			'complete',
			'manager'
		);

		$this->assertTrue($result->isAllowed());
	}//end testTheRefundItselfIsExcludedFromTheSiblingSum()

	/**
	 * Exceeding the total by at most one cent is tolerated; more is not.
	 *
	 * @return void
	 */
	public function testOneCentToleranceOnTheCap(): void {
		$within = $this->guard()->check(
			['id' => 'r', 'originalTransaction' => 'tx-1', 'refundAmount' => 100.0, 'totalTax' => 0.01],
			'complete',
			'manager'
		);
		$this->assertTrue($within->isAllowed());

		$over = $this->guard()->check(
			['id' => 'r', 'originalTransaction' => 'tx-1', 'refundAmount' => 100.0, 'totalTax' => 0.02],
			'complete',
			'manager'
		);
		$this->assertFalse($over->isAllowed());
	}//end testOneCentToleranceOnTheCap()

	/**
	 * A failing sibling lookup is logged and fails closed.
	 *
	 * @return void
	 */
	public function testSiblingLookupFailureFailsClosed(): void {
		$this->objects->findAllFailure = new RuntimeException('db down');
		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Pipelinq: refund guard could not sum completed refunds (fail closed)',
				['exception' => 'db down', 'transaction' => 'tx-1']
			);

		$result = $this->guard()->check(
			['id' => 'r', 'originalTransaction' => 'tx-1', 'refundAmount' => 1],
			'complete',
			'manager'
		);

		$this->assertFalse($result->isAllowed());
	}//end testSiblingLookupFailureFailsClosed()

	/**
	 * Without a refund schema the sibling sum is unknowable and the refund is refused.
	 *
	 * @return void
	 */
	public function testMissingRefundSchemaFailsClosed(): void {
		$this->config['posRefund_schema'] = '';

		$result = $this->guard()->check(
			['id' => 'r', 'originalTransaction' => 'tx-1', 'refundAmount' => 1],
			'complete',
			'manager'
		);

		$this->assertFalse($result->isAllowed());
		$this->assertSame([], $this->objects->findAllCalls);
	}//end testMissingRefundSchemaFailsClosed()
}//end class
