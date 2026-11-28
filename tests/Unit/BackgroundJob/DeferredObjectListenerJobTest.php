<?php

/**
 * Unit tests for DeferredObjectListenerJob: how it resolves, guards and runs each deferred entry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\DealCreatedListener;
use OCA\Pipelinq\Listener\DeferredObjectWork;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins every warning, skip and run of a deferred pass.
 */
final class DeferredObjectListenerJobTest extends TestCase {
	/**
	 * Every listener run and warning, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * Reset the process-wide re-entry guard.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		DeferredWorkGuard::reset();
	}//end tearDown()

	/**
	 * A listener that records its runs, and fails for the uuid `boom`.
	 *
	 * @return DeferredObjectWork The listener.
	 */
	private function listener(): DeferredObjectWork {
		$events = &$this->events;
		return new class ($events) implements DeferredObjectWork {
			/**
			 * @param array<int, array<int, mixed>> $events The event log.
			 */
			public function __construct(private array &$events) {
			}

			/**
			 * @param array<string, mixed> $entry The entry.
			 *
			 * @return void
			 */
			public function runDeferredWork(array $entry): void {
				$key = DeferredWorkGuard::key(handler: (string)$entry['handler'], uuid: (string)($entry['uuid'] ?? ''));
				$this->events[] = ['run', $entry, DeferredWorkGuard::isRunning(key: $key)];
				if (($entry['uuid'] ?? '') === 'boom') {
					throw new RuntimeException('write failed');
				}
			}
		};
	}//end listener()

	/**
	 * Run one deferred pass over the given entries.
	 *
	 * @param array<int, array<string, mixed>> $entries  The entries.
	 * @param mixed                            $resolved What the container answers, or a Throwable to throw.
	 *
	 * @return array<int, array<int, mixed>> The events.
	 */
	private function pass(array $entries, mixed $resolved): array {
		$this->events = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($resolved): mixed {
				if ($resolved instanceof \Throwable) {
					throw $resolved;
				}

				return $resolved;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->events[] = ['warning', $message, $context];
			}
		);
		$job = new DeferredObjectListenerJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IUserManager::class),
			$this->getMockBuilder(OrganisationService::class)->disableOriginalConstructor()->getMock(),
			$logger,
			$container,
		);
		$context = new DeferredListenerContext(userId: 'tester', orgUuid: null, entries: $entries);
		(new \ReflectionMethod(DeferredObjectListenerJob::class, 'runDeferred'))->invoke($job, $context);
		return $this->events;
	}//end pass()

	/**
	 * Known entries run guarded; a failing run is logged and the next entry still runs.
	 *
	 * @return void
	 */
	public function testRunsEveryKnownEntryUnderTheGuard(): void {
		$handler = DealCreatedListener::HANDLER_KEY;
		$first = ['handler' => $handler, 'uuid' => 'u-1'];
		$failing = ['handler' => $handler, 'uuid' => 'boom'];
		$noUuid = ['handler' => $handler];
		$this->assertSame(
			[
				['run', $first, true],
				['run', $failing, true],
				['warning', 'Pipelinq: deferred listener work failed', ['handler' => $handler, 'uuid' => 'boom', 'exception' => 'write failed']],
				['run', $noUuid, true],
			],
			$this->pass(entries: [$first, $failing, $noUuid], resolved: $this->listener())
		);
		$this->assertFalse(DeferredWorkGuard::isRunning(key: DeferredWorkGuard::key(handler: $handler, uuid: 'boom')), 'the guard is left after a failure');
	}//end testRunsEveryKnownEntryUnderTheGuard()

	/**
	 * An entry already on the stack is skipped silently.
	 *
	 * @return void
	 */
	public function testSkipsAnEntryAlreadyRunning(): void {
		$handler = DealCreatedListener::HANDLER_KEY;
		DeferredWorkGuard::enter(key: DeferredWorkGuard::key(handler: $handler, uuid: 'u-1'));
		$this->assertSame([], $this->pass(entries: [['handler' => $handler, 'uuid' => 'u-1']], resolved: $this->listener()));
	}//end testSkipsAnEntryAlreadyRunning()

	/**
	 * Unknown handlers, unresolvable listeners and listeners that cannot defer are logged and skipped.
	 *
	 * @return void
	 */
	public function testRefusedEntriesAreLogged(): void {
		$handler = DealCreatedListener::HANDLER_KEY;
		$this->assertSame(
			[
				['warning', 'Pipelinq: deferred listener entry names no known handler', ['handler' => 'nope']],
				['warning', 'Pipelinq: deferred listener entry names no known handler', ['handler' => 7]],
				['warning', 'Pipelinq: deferred listener entry names no known handler', ['handler' => '']],
			],
			$this->pass(entries: [['handler' => 'nope'], ['handler' => 7], []], resolved: $this->listener())
		);
		$this->assertSame(
			[['warning', 'Pipelinq: deferred listener could not be resolved', ['handler' => $handler, 'exception' => 'no service']]],
			$this->pass(entries: [['handler' => $handler, 'uuid' => 'u-1']], resolved: new RuntimeException('no service'))
		);
		$this->assertSame(
			[['warning', 'Pipelinq: deferred listener does not implement DeferredObjectWork', ['handler' => $handler]]],
			$this->pass(entries: [['handler' => $handler, 'uuid' => 'u-1']], resolved: new \stdClass())
		);
	}//end testRefusedEntriesAreLogged()
}//end class
