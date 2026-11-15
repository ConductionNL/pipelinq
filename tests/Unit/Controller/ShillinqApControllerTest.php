<?php

/**
 * Unit tests for ShillinqApController.
 *
 * The admin retry re-dispatches a failed expense to the Shillinq AP webhook
 * and records each step of the outcome on the expense. The tests pin the
 * refusals (400 / 404), the rule that only a failed sync may be retried, and
 * the pending → synced / failed writes that follow a dispatch.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ShillinqApController;
use OCA\Pipelinq\Service\ShillinqApService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ShillinqApController.
 */
#[CoversClass(ShillinqApController::class)]
class ShillinqApControllerTest extends TestCase {

	/**
	 * The AP service.
	 *
	 * @var ShillinqApService&MockObject
	 */
	private ShillinqApService&MockObject $ap;

	/**
	 * The recording object service the container hands out.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg-1', 'expense_schema' => 'sch-exp'];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->ap = $this->createMock(ShillinqApService::class);
		$this->ap->method('now')->willReturn('2026-11-13T10:00:00+00:00');
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->objects = new class {
			/**
			 * The stored entry, or null; a Throwable is thrown.
			 *
			 * @var mixed
			 */
			public mixed $entry = null;

			/**
			 * Whether a save throws.
			 *
			 * @var bool
			 */
			public bool $saveFails = false;

			/**
			 * The find calls.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $finds = [];

			/**
			 * The saved payloads.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saves = [];

			/**
			 * Find an object.
			 *
			 * @param string $id       The id.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return object|null
			 */
			public function find(string $id, string $register, string $schema): ?object {
				$this->finds[] = ['id' => $id, 'register' => $register, 'schema' => $schema];
				if ($this->entry instanceof \Throwable) {
					throw $this->entry;
				}

				if ($this->entry === null) {
					return null;
				}

				$data = $this->entry;
				return new class ($data) {
					/**
					 * Constructor.
					 *
					 * @param array<string, mixed> $data The data.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * The data.
					 *
					 * @return array<string, mixed>
					 */
					public function getObject(): array {
						return $this->data;
					}
				};
			}//end find()

			/**
			 * Save an object.
			 *
			 * @param array<string, mixed> $object   The data.
			 * @param array<int, string>   $extend   The extend list.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The uuid.
			 *
			 * @return void
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): void {
				if ($this->saveFails === true) {
					throw new RuntimeException('disk full');
				}

				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
			}//end saveObject()
		};
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return ShillinqApController
	 */
	private function controller(): ShillinqApController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($this->config[$key] ?? $default)
		);

		return new ShillinqApController(
			$this->createMock(IRequest::class),
			$this->ap,
			$container,
			$appConfig,
			$this->logger
		);
	}//end controller()

	/**
	 * Without Shillinq the retry is refused before anything is read.
	 *
	 * @return void
	 */
	public function testRefusesWhenShillinqIsNotInstalled(): void {
		$this->ap->method('shouldDispatch')->willReturn(false);
		$this->ap->expects($this->never())->method('dispatchApEvent');

		$response = $this->controller()->retry('exp-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Shillinq is not installed.'], $response->getData());
		$this->assertSame([], $this->objects->finds);
	}//end testRefusesWhenShillinqIsNotInstalled()

	/**
	 * A missing register, schema or id is an incomplete configuration.
	 *
	 * @return void
	 */
	public function testRefusesAnIncompleteConfiguration(): void {
		$this->ap->method('shouldDispatch')->willReturn(true);

		$this->config['register'] = '';
		$noRegister = $this->controller()->retry('exp-1');
		$this->config = ['register' => 'reg-1', 'expense_schema' => 'sch-exp'];
		$noId = $this->controller()->retry('');

		foreach ([$noRegister, $noId] as $response) {
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'Expense schema configuration is incomplete.'], $response->getData());
		}

		$this->assertSame([], $this->objects->finds);
	}//end testRefusesAnIncompleteConfiguration()

	/**
	 * An expense that is not found, or whose read throws, is a 404; the throw is logged.
	 *
	 * @return void
	 */
	public function testAnUnknownOrUnreadableExpenseIsNotFound(): void {
		$this->ap->method('shouldDispatch')->willReturn(true);
		$this->ap->expects($this->never())->method('dispatchApEvent');

		$missing = $this->controller()->retry('exp-1');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Expense not found.'], $missing->getData());
		$this->assertSame([['id' => 'exp-1', 'register' => 'reg-1', 'schema' => 'sch-exp']], $this->objects->finds);

		$this->objects->entry = new RuntimeException('db down');
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: failed to load expense for AP retry',
			['exception' => 'db down', 'uuid' => 'exp-2']
		);
		$this->assertSame(404, $this->controller()->retry('exp-2')->getStatus());
		$this->assertSame([], $this->objects->saves);
	}//end testAnUnknownOrUnreadableExpenseIsNotFound()

	/**
	 * Only a failed sync may be retried; the current status is echoed back.
	 *
	 * @return void
	 */
	public function testOnlyAFailedSyncMayBeRetried(): void {
		$this->ap->method('shouldDispatch')->willReturn(true);
		$this->ap->expects($this->never())->method('dispatchApEvent');

		$this->objects->entry = ['apSyncStatus' => 'synced'];
		$synced = $this->controller()->retry('exp-1');
		$this->objects->entry = ['amount' => 12];
		$unset = $this->controller()->retry('exp-1');

		$this->assertSame(400, $synced->getStatus());
		$this->assertSame(['error' => 'Only failed AP syncs can be retried.', 'apSyncStatus' => 'synced'], $synced->getData());
		$this->assertSame(400, $unset->getStatus());
		$this->assertSame(['error' => 'Only failed AP syncs can be retried.', 'apSyncStatus' => null], $unset->getData());
		$this->assertSame([], $this->objects->saves);
	}//end testOnlyAFailedSyncMayBeRetried()

	/**
	 * A successful dispatch sends the expense with its uuid, then marks it synced.
	 *
	 * @return void
	 */
	public function testASuccessfulDispatchMarksTheExpenseSynced(): void {
		$this->ap->method('shouldDispatch')->willReturn(true);
		$this->objects->entry = ['apSyncStatus' => 'failed', 'amount' => 12, 'approvedBy' => 'anna', 'approvedAt' => '2026-11-12'];
		$this->ap->expects($this->once())->method('dispatchApEvent')->with(
			['apSyncStatus' => 'pending', 'amount' => 12, 'approvedBy' => 'anna', 'approvedAt' => '2026-11-12', 'uuid' => 'exp-1'],
			'anna',
			'2026-11-12'
		)->willReturn(true);

		$response = $this->controller()->retry('exp-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['apSyncStatus' => 'synced', 'apSyncedAt' => '2026-11-13T10:00:00+00:00'], $response->getData());
		$this->assertSame(['pending', 'synced'], array_map(fn (array $save): string => $save['object']['apSyncStatus'], $this->objects->saves));
		$this->assertArrayNotHasKey('uuid', $this->objects->saves[1]['object']);
		$this->assertSame(['reg-1', 'sch-exp', 'exp-1'], [$this->objects->saves[1]['register'], $this->objects->saves[1]['schema'], $this->objects->saves[1]['uuid']]);
	}//end testASuccessfulDispatchMarksTheExpenseSynced()

	/**
	 * A failed dispatch marks the expense failed again and answers 502; the
	 * approval time defaults to now and the approver to ''.
	 *
	 * @return void
	 */
	public function testAFailedDispatchMarksTheExpenseFailed(): void {
		$this->ap->method('shouldDispatch')->willReturn(true);
		$this->objects->entry = ['apSyncStatus' => 'failed'];
		$this->ap->expects($this->once())->method('dispatchApEvent')
			->with(['apSyncStatus' => 'pending', 'uuid' => 'exp-1'], '', '2026-11-13T10:00:00+00:00')
			->willReturn(false);

		$response = $this->controller()->retry('exp-1');

		$this->assertSame(502, $response->getStatus());
		$this->assertSame(['apSyncStatus' => 'failed', 'error' => 'AP dispatch failed.'], $response->getData());
		$this->assertSame(['pending', 'failed'], array_map(fn (array $save): string => $save['object']['apSyncStatus'], $this->objects->saves));
	}//end testAFailedDispatchMarksTheExpenseFailed()

	/**
	 * A save that throws is logged and does not stop the dispatch.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndTheDispatchStillRuns(): void {
		$this->ap->method('shouldDispatch')->willReturn(true);
		$this->objects->entry = ['apSyncStatus' => 'failed'];
		$this->objects->saveFails = true;
		$this->ap->expects($this->once())->method('dispatchApEvent')->willReturn(true);
		$this->logger->expects($this->exactly(2))->method('warning')->with(
			'Pipelinq: failed to persist expense AP retry status',
			['exception' => 'disk full', 'uuid' => 'exp-1']
		);

		$this->assertSame(200, $this->controller()->retry('exp-1')->getStatus());
	}//end testAFailedSaveIsLoggedAndTheDispatchStillRuns()
}//end class
