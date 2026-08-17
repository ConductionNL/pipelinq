<?php

/**
 * Test helper: run recorded deferrals through the REAL background job.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Service\Deferral\DeferredListenerContext;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\DeferredObjectWork;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drains a {@see RecordingDeferralService} through {@see DeferredObjectListenerJob}.
 *
 * WHY THE REAL JOB AND NOT A DIRECT CALL TO runDeferredWork(): the job carries
 * the handler allow-list AND the {@see \OCA\Pipelinq\Listener\DeferredWorkGuard}
 * claim that stops a listener's own write from re-entering it and enqueueing
 * another job. A test that called `runDeferredWork()` directly would run the
 * work with the guard NOT held — the one condition production never has — and
 * would report success for a listener whose deferral loops forever in cron.
 */
final class DeferredJobDrain {

	/**
	 * Run every recorded entry through the real job.
	 *
	 * @param TestCase $test The calling test (for building mocks).
	 * @param RecordingDeferralService $deferral The recorder holding the entries.
	 * @param DeferredObjectWork $listener The listener the entries belong to.
	 *
	 * @return void
	 */
	public static function run(
		TestCase $test,
		RecordingDeferralService $deferral,
		DeferredObjectWork $listener,
	): void {
		$entries = $deferral->entries;
		if ($entries === []) {
			return;
		}

		$container = $test->getMockBuilder(ContainerInterface::class)->getMock();
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($listener): object {
				if ($id === $listener::class) {
					return $listener;
				}

				throw new \RuntimeException('unexpected service ' . $id);
			}
		);

		$job = new DeferredObjectListenerJob(
			$test->getMockBuilder(ITimeFactory::class)->getMock(),
			$test->getMockBuilder(IUserSession::class)->getMock(),
			$test->getMockBuilder(IUserManager::class)->getMock(),
			$test->getMockBuilder(OrganisationService::class)->disableOriginalConstructor()->getMock(),
			$test->getMockBuilder(LoggerInterface::class)->getMock(),
			$container,
		);

		// The entries are consumed, so a listener that queues MORE work from
		// inside the deferred pass (the loop this design forbids) shows up as a
		// growing list rather than silently recursing here.
		$deferral->entries = [];

		$context = new DeferredListenerContext(userId: 'tester', orgUuid: null, entries: $entries);

		$run = new \ReflectionMethod(DeferredObjectListenerJob::class, 'runDeferred');
		$run->setAccessible(true);
		$run->invoke($job, $context);
	}//end run()
}//end class
