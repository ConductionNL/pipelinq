<?php

/**
 * Unit tests for TimeEntryWipController.
 *
 * The admin retry re-dispatches a time entry to the Shillinq WIP ledger and
 * records each step of the outcome on the entry. The tests pin the refusals
 * (400 / 404), the idempotent answer for an already synced entry, and the
 * pending → synced / failed writes that follow a dispatch.
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

use OCA\Pipelinq\Controller\TimeEntryWipController;
use OCA\Pipelinq\Service\ShillinqWipService;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for TimeEntryWipController.
 */
#[CoversClass(TimeEntryWipController::class)]
class TimeEntryWipControllerTest extends TestCase {

	/**
	 * The WIP service.
	 *
	 * @var ShillinqWipService&MockObject
	 */
	private ShillinqWipService&MockObject $wip;

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
	private array $config = ['register' => 'reg-1', 'timeEntry_schema' => 'sch-te'];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wip = $this->createMock(ShillinqWipService::class);
		$this->wip->method('now')->willReturn('2026-11-13T10:00:00+00:00');
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
	 * @return TimeEntryWipController
	 */
	private function controller(): TimeEntryWipController {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->objects);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($this->config[$key] ?? $default)
		);

		return new TimeEntryWipController(
			$this->createMock(IRequest::class),
			$this->wip,
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
		$this->wip->method('shouldDispatch')->willReturn(false);
		$this->wip->expects($this->never())->method('dispatchWipEvent');

		$response = $this->controller()->retry('te-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame(['error' => 'Shillinq is not installed.'], $response->getData());
		$this->assertSame([], $this->objects->finds);
	}//end testRefusesWhenShillinqIsNotInstalled()

	/**
	 * A missing schema, register or uuid is an incomplete configuration.
	 *
	 * @return void
	 */
	public function testRefusesAnIncompleteConfiguration(): void {
		$this->wip->method('shouldDispatch')->willReturn(true);

		$this->config['timeEntry_schema'] = '';
		$noSchema = $this->controller()->retry('te-1');
		$this->config = ['register' => 'reg-1', 'timeEntry_schema' => 'sch-te'];
		$noUuid = $this->controller()->retry('');

		foreach ([$noSchema, $noUuid] as $response) {
			$this->assertSame(400, $response->getStatus());
			$this->assertSame(['error' => 'Time entry configuration is incomplete.'], $response->getData());
		}

		$this->assertSame([], $this->objects->finds);
	}//end testRefusesAnIncompleteConfiguration()

	/**
	 * An entry that is not found, or whose read throws, is a 404; the throw is logged.
	 *
	 * @return void
	 */
	public function testAnUnknownOrUnreadableEntryIsNotFound(): void {
		$this->wip->method('shouldDispatch')->willReturn(true);
		$this->wip->expects($this->never())->method('dispatchWipEvent');

		$missing = $this->controller()->retry('te-1');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Time entry not found.'], $missing->getData());
		$this->assertSame([['id' => 'te-1', 'register' => 'reg-1', 'schema' => 'sch-te']], $this->objects->finds);

		$this->objects->entry = new RuntimeException('db down');
		$this->logger->expects($this->once())->method('warning')->with(
			'Pipelinq: failed to load time entry for WIP retry',
			['exception' => 'db down', 'uuid' => 'te-2']
		);
		$this->assertSame(404, $this->controller()->retry('te-2')->getStatus());
		$this->assertSame([], $this->objects->saves);
	}//end testAnUnknownOrUnreadableEntryIsNotFound()

	/**
	 * An already synced entry is answered as-is and not dispatched again.
	 *
	 * @return void
	 */
	public function testAnAlreadySyncedEntryIsNotDispatchedAgain(): void {
		$this->wip->method('shouldDispatch')->willReturn(true);
		$this->wip->expects($this->never())->method('dispatchWipEvent');
		$this->objects->entry = ['wipSyncStatus' => 'synced', 'wipSyncedAt' => '2026-11-01T09:00:00+00:00'];

		$response = $this->controller()->retry('te-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['status' => 'synced', 'wipSyncStatus' => 'synced', 'wipSyncedAt' => '2026-11-01T09:00:00+00:00'],
			$response->getData()
		);
		$this->assertSame([], $this->objects->saves);
	}//end testAnAlreadySyncedEntryIsNotDispatchedAgain()

	/**
	 * A successful dispatch marks the entry pending, then synced with the time.
	 *
	 * @return void
	 */
	public function testASuccessfulDispatchMarksTheEntrySynced(): void {
		$this->wip->method('shouldDispatch')->willReturn(true);
		$this->objects->entry = ['wipSyncStatus' => 'failed', 'hours' => 3, 'approvedBy' => 'anna', 'approvedAt' => '2026-11-12'];
		$this->wip->expects($this->once())->method('dispatchWipEvent')->with(
			['wipSyncStatus' => 'pending', 'hours' => 3, 'approvedBy' => 'anna', 'approvedAt' => '2026-11-12'],
			'anna',
			'2026-11-12'
		)->willReturn(true);

		$response = $this->controller()->retry('te-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['status' => 'synced', 'wipSyncStatus' => 'synced', 'wipSyncedAt' => '2026-11-13T10:00:00+00:00'],
			$response->getData()
		);
		$this->assertSame(['pending', 'synced'], array_map(fn (array $save): string => $save['object']['wipSyncStatus'], $this->objects->saves));
		$this->assertSame('2026-11-13T10:00:00+00:00', $this->objects->saves[1]['object']['wipSyncedAt']);
		$this->assertSame(['reg-1', 'sch-te', 'te-1'], [$this->objects->saves[1]['register'], $this->objects->saves[1]['schema'], $this->objects->saves[1]['uuid']]);
	}//end testASuccessfulDispatchMarksTheEntrySynced()

	/**
	 * A failed dispatch marks the entry failed and answers 502; approval fields default to ''.
	 *
	 * @return void
	 */
	public function testAFailedDispatchMarksTheEntryFailed(): void {
		$this->wip->method('shouldDispatch')->willReturn(true);
		$this->objects->entry = ['hours' => 1];
		$this->wip->expects($this->once())->method('dispatchWipEvent')
			->with(['hours' => 1, 'wipSyncStatus' => 'pending'], '', '')
			->willReturn(false);

		$response = $this->controller()->retry('te-1');

		$this->assertSame(502, $response->getStatus());
		$this->assertSame(
			['status' => 'failed', 'wipSyncStatus' => 'failed', 'message' => 'WIP dispatch failed.'],
			$response->getData()
		);
		$this->assertSame(['pending', 'failed'], array_map(fn (array $save): string => $save['object']['wipSyncStatus'], $this->objects->saves));
	}//end testAFailedDispatchMarksTheEntryFailed()

	/**
	 * A save that throws is logged and does not stop the dispatch.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndTheDispatchStillRuns(): void {
		$this->wip->method('shouldDispatch')->willReturn(true);
		$this->objects->entry = ['hours' => 1];
		$this->objects->saveFails = true;
		$this->wip->expects($this->once())->method('dispatchWipEvent')->willReturn(true);
		$this->logger->expects($this->exactly(2))->method('warning')->with(
			'Pipelinq: failed to persist time entry WIP retry status',
			['exception' => 'disk full', 'uuid' => 'te-1']
		);

		$this->assertSame(200, $this->controller()->retry('te-1')->getStatus());
	}//end testAFailedSaveIsLoggedAndTheDispatchStillRuns()
}//end class
