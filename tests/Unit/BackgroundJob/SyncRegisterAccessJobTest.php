<?php

/**
 * The job applies a CRM group or read group changed outside the settings screen.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\SyncRegisterAccessJob;
use OCA\Pipelinq\Service\Access\RegisterAccessSync;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * @covers \OCA\Pipelinq\BackgroundJob\SyncRegisterAccessJob
 *
 * @spec openspec/changes/close-pipelinq-register-to-staff/specs/register-access/spec.md#requirement-a-configured-read-group-reads-the-parties-and-contact-moments-and-writes-nothing
 */
class SyncRegisterAccessJobTest extends TestCase {

	/**
	 * A run is an unforced sync, which reads nothing while nothing changed.
	 *
	 * @return void
	 */
	public function testARunIsAnUnforcedSync(): void {
		$sync = $this->createMock(RegisterAccessSync::class);
		$sync->expects($this->once())->method('sync')->with();

		$job = new SyncRegisterAccessJob($this->createMock(ITimeFactory::class), $sync);
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end testARunIsAnUnforcedSync()

	/**
	 * The job is registered, so it runs on every instance.
	 *
	 * @return void
	 */
	public function testTheJobIsRegistered(): void {
		$info = (string)file_get_contents(dirname(__DIR__, 3).'/appinfo/info.xml');

		$this->assertStringContainsString('<job>OCA\Pipelinq\BackgroundJob\SyncRegisterAccessJob</job>', $info);
	}//end testTheJobIsRegistered()
}//end class
