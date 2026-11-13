<?php

/**
 * Unit tests for TimeApprovalListener.
 *
 * Asserts an approved time entry is marked pending, dispatched to the WIP
 * ledger, and saved as synced or failed (with a failure notice), and that
 * unconfigured, already-synced and failing-save cases end quietly.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Event\TimeEntryApprovedEvent;
use OCA\Pipelinq\Listener\TimeApprovalListener;
use OCA\Pipelinq\Service\ShillinqWipService;
use OCA\Pipelinq\Service\WipSyncNotifier;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for TimeApprovalListener.
 *
 * @covers \OCA\Pipelinq\Listener\TimeApprovalListener
 * @covers \OCA\Pipelinq\Event\TimeEntryApprovedEvent
 */
class TimeApprovalListenerTest extends TestCase {

	/**
	 * The WIP service mock (HTTP boundary).
	 *
	 * @var ShillinqWipService&MockObject
	 */
	private ShillinqWipService $wip;

	/**
	 * The failure notifier mock.
	 *
	 * @var WipSyncNotifier&MockObject
	 */
	private WipSyncNotifier $notifier;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The recorded saveObject() calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saves = [];

	/**
	 * Set up the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->wip      = $this->createMock(originalClassName: ShillinqWipService::class);
		$this->notifier = $this->createMock(originalClassName: WipSyncNotifier::class);
		$this->logger   = $this->createMock(originalClassName: LoggerInterface::class);
		$this->saves    = [];
	}//end setUp()

	/**
	 * Build the listener with the given config and save behaviour.
	 *
	 * @param string          $register The configured register.
	 * @param string          $schema   The configured time entry schema.
	 * @param \Throwable|null $onSave   What saveObject() throws.
	 *
	 * @return TimeApprovalListener
	 */
	private function listener(string $register='reg-1', string $schema='te-schema', ?\Throwable $onSave=null): TimeApprovalListener {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			callback: static fn (string $app, string $key, string $default=''): string => match ($key) {
				'register' => $register,
				'timeEntry_schema' => $schema,
				default => $default,
			}
		);
		$saves   = &$this->saves;
		$objects = new class($saves, $onSave) {
			/**
			 * Constructor.
			 *
			 * @param array           $saves  The shared list of saves.
			 * @param \Throwable|null $onSave What saveObject() throws.
			 */
			public function __construct(private array &$saves, private ?\Throwable $onSave) {
			}//end __construct()

			/**
			 * Record a save.
			 *
			 * @param array       $object   The data.
			 * @param array       $extend   The extend list.
			 * @param string      $register The register.
			 * @param string      $schema   The schema.
			 * @param string|null $uuid     The uuid.
			 *
			 * @return void
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid): void {
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->onSave !== null) {
					throw $this->onSave;
				}
			}//end saveObject()
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn(value: $objects);

		return new TimeApprovalListener(
			wipService: $this->wip,
			notifier: $this->notifier,
			container: $container,
			appConfig: $appConfig,
			logger: $this->logger
		);
	}//end listener()

	/**
	 * An approval event for one time entry.
	 *
	 * @param array<string, mixed> $entry The time entry.
	 * @param string               $uuid  The uuid.
	 *
	 * @return TimeEntryApprovedEvent
	 */
	private function approved(array $entry, string $uuid='te-1'): TimeEntryApprovedEvent {
		return new TimeEntryApprovedEvent(timeEntryUuid: $uuid, timeEntry: $entry, approvedBy: 'alice', approvedAt: '2026-11-01T10:00:00Z');
	}//end approved()

	/**
	 * A successful dispatch saves pending, then synced with the ledger's timestamp.
	 *
	 * @return void
	 */
	public function testASuccessfulDispatchMarksTheEntrySynced(): void {
		$this->wip->method('shouldDispatch')->willReturn(value: true);
		$this->wip->expects($this->once())->method('dispatchWipEvent')->with(
			['title' => 'Work', 'wipSyncStatus' => 'pending'],
			'alice',
			'2026-11-01T10:00:00Z'
		)->willReturn(value: true);
		$this->wip->method('now')->willReturn(value: '2026-11-01T10:00:05Z');
		$this->notifier->expects($this->never())->method('notifyFailure');

		$this->listener()->handle(event: $this->approved(entry: ['title' => 'Work']));

		self::assertSame(
			expected: [
				['object' => ['title' => 'Work', 'wipSyncStatus' => 'pending'], 'register' => 'reg-1', 'schema' => 'te-schema', 'uuid' => 'te-1'],
				[
					'object' => ['title' => 'Work', 'wipSyncStatus' => 'synced', 'wipSyncedAt' => '2026-11-01T10:00:05Z'],
					'register' => 'reg-1',
					'schema' => 'te-schema',
					'uuid' => 'te-1',
				],
			],
			actual: $this->saves
		);
	}//end testASuccessfulDispatchMarksTheEntrySynced()

	/**
	 * A failed dispatch saves failed and sends a failure notice with the title.
	 *
	 * @return void
	 */
	public function testAFailedDispatchMarksTheEntryFailedAndNotifies(): void {
		$this->wip->method('shouldDispatch')->willReturn(value: true);
		$this->wip->method('dispatchWipEvent')->willReturn(value: false);
		$this->notifier->expects($this->once())->method('notifyFailure')->with('Work', 'te-1');

		$this->listener()->handle(event: $this->approved(entry: ['title' => 'Work', 'wipSyncStatus' => 'failed']));

		$statuses = array_map(callback: static fn (array $save): string => $save['object']['wipSyncStatus'], array: $this->saves);
		self::assertSame(expected: ['pending', 'failed'], actual: $statuses);
	}//end testAFailedDispatchMarksTheEntryFailedAndNotifies()

	/**
	 * Other events, an unconfigured integration and an already-synced entry do nothing.
	 *
	 * @return void
	 */
	public function testNothingHappensWhenThereIsNothingToSync(): void {
		$this->wip->method('shouldDispatch')->willReturnOnConsecutiveCalls(false, true);
		$this->wip->expects($this->never())->method('dispatchWipEvent');

		$listener = $this->listener();
		$listener->handle(event: new Event());
		$listener->handle(event: $this->approved(entry: ['title' => 'Off']));
		$listener->handle(event: $this->approved(entry: ['title' => 'Done', 'wipSyncStatus' => 'synced']));

		self::assertSame(expected: [], actual: $this->saves);
	}//end testNothingHappensWhenThereIsNothingToSync()

	/**
	 * Without a configured schema or uuid nothing is saved, but the dispatch still happens.
	 *
	 * @return void
	 */
	public function testNothingIsSavedWhenUnconfigured(): void {
		$this->wip->method('shouldDispatch')->willReturn(value: true);
		$this->wip->expects($this->exactly(count: 2))->method('dispatchWipEvent')->willReturn(value: true);

		$this->listener(schema: '')->handle(event: $this->approved(entry: []));
		$this->listener()->handle(event: $this->approved(entry: [], uuid: ''));

		self::assertSame(expected: [], actual: $this->saves);
	}//end testNothingIsSavedWhenUnconfigured()

	/**
	 * A failing save is logged with its uuid and does not stop the dispatch.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLogged(): void {
		$this->wip->method('shouldDispatch')->willReturn(value: true);
		$this->wip->expects($this->once())->method('dispatchWipEvent')->willReturn(value: true);
		$this->logger->expects($this->exactly(count: 2))->method('warning')->with(
			'Pipelinq: failed to persist time entry WIP sync status',
			['exception' => 'locked', 'uuid' => 'te-1']
		);

		$this->listener(onSave: new RuntimeException('locked'))->handle(event: $this->approved(entry: []));
	}//end testAFailingSaveIsLogged()
}//end class
