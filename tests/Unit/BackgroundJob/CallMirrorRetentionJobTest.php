<?php

/**
 * Tests for CallMirrorRetentionJob, from its registration to the purge.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\Pipelinq\BackgroundJob\CallMirrorRetentionJob;
use OCA\Pipelinq\Service\Calls\CallMirror;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The retention job is registered where pipelinq registers jobs, and it purges.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\CallMirrorRetentionJob
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 */
class CallMirrorRetentionJobTest extends TestCase {
	/**
	 * `appinfo/info.xml` lists the job, and the class it names is a timed job.
	 *
	 * Nextcloud adds exactly the classes of `<background-jobs>` to the job
	 * list on install and upgrade. A job missing there never runs.
	 *
	 * @return void
	 */
	public function testInfoXmlRegistersTheJob(): void {
		$xml = (string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml');
		$this->assertSame(1, preg_match('#<background-jobs>(.*?)</background-jobs>#s', $xml, $block), 'info.xml has no background-jobs block.');
		preg_match_all('#<job>([^<]+)</job>#', $block[1], $jobs);

		$this->assertContains(CallMirrorRetentionJob::class, $jobs[1]);
		$this->assertTrue(is_subclass_of(CallMirrorRetentionJob::class, TimedJob::class));
		$this->assertNotContains('OCA\Pipelinq\BackgroundJob\NoSuchJob', $jobs[1], 'Control: an unregistered job is absent.');
	}//end testInfoXmlRegistersTheJob()

	/**
	 * A run purges the mirror once and logs how many rows went.
	 *
	 * @return void
	 */
	public function testARunPurgesTheMirror(): void {
		$mirror = $this->createMock(CallMirror::class);
		$mirror->expects($this->once())->method('purgeExpired')->willReturn(3);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with($this->anything(), ['removed' => 3]);

		$job = new CallMirrorRetentionJob($this->createMock(ITimeFactory::class), $mirror, $logger);
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end testARunPurgesTheMirror()
}//end class
