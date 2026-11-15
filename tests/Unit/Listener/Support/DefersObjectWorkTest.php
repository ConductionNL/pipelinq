<?php

/**
 * Behavioural tests for the DefersObjectWork trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener\Support;

use OCA\Pipelinq\BackgroundJob\DeferredObjectListenerJob;
use OCA\Pipelinq\Listener\Support\DefersObjectWork;
use OCA\Pipelinq\Tests\Unit\Listener\RecordingDeferralService;
use PHPUnit\Framework\TestCase;

/**
 * One deferred job per object, keyed by the host's handler key.
 *
 * @covers \OCA\Pipelinq\Listener\Support\DefersObjectWork
 */
class DefersObjectWorkTest extends TestCase {

	/**
	 * @return void
	 */
	public function testDefersTheObjectUnderTheHostsHandlerKey(): void {
		$deferral = new RecordingDeferralService();
		$host = new class($deferral) {
			use DefersObjectWork;

			private const HANDLER_KEY = 'probe';

			/**
			 * @param RecordingDeferralService $deferral The deferral service.
			 */
			public function __construct(
				private RecordingDeferralService $deferral,
			) {
			}

			/**
			 * @param string $uuid The object UUID.
			 *
			 * @return void
			 */
			public function queue(string $uuid): void {
				$this->deferObjectWork(uuid: $uuid);
			}
		};

		$host->queue(uuid: 'o-1');

		self::assertSame([['handler' => 'probe', 'uuid' => 'o-1']], $deferral->entries);
		self::assertSame([DeferredObjectListenerJob::class], $deferral->jobClasses);
		self::assertSame(['probe|o-1'], $deferral->dedupeKeys);
	}//end testDefersTheObjectUnderTheHostsHandlerKey()
}//end class
