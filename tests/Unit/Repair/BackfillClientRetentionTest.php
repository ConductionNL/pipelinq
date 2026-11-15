<?php

/**
 * Unit tests for the BackfillClientRetention repair step.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\BackfillClientRetention;
use OCA\Pipelinq\Service\RetentionMetadataBackfillService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The step back-fills clients and contact persons and reports the outcome.
 */
#[CoversClass(BackfillClientRetention::class)]
class BackfillClientRetentionTest extends TestCase {

	/**
	 * Run the step over a service answering the given result.
	 *
	 * @param array<string, mixed> $result  The service's answer.
	 * @param IOutput              $output  The output double.
	 *
	 * @return void
	 */
	private function runWith(array $result, IOutput $output): void {
		$service = $this->createMock(RetentionMetadataBackfillService::class);
		$service->expects($this->once())->method('backfill')
			->with(['client_schema', 'contact_schema'])
			->willReturn($result);

		(new BackfillClientRetention($service))->run($output);
	}//end runWith()

	/**
	 * A finished run reports its counts.
	 *
	 * @return void
	 */
	public function testAFinishedRunReportsItsCounts(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('warning');
		$output->expects($this->once())->method('info')->with(
			'BackfillClientRetention: 4 record(s) given retention metadata, 2 left as they were, 1 could not be written.'
		);

		$this->runWith(result: ['status' => 'done', 'applied' => 4, 'skipped' => 2, 'failed' => 1], output: $output);
	}//end testAFinishedRunReportsItsCounts()

	/**
	 * An unconfigured app and a missing OpenRegister service each warn, and report no counts.
	 *
	 * @return void
	 */
	public function testANoOpRunWarnsWhy(): void {
		$expected = [
			'not-configured' => 'BackfillClientRetention: nothing back-filled, because the client and contact schemas are not configured yet.',
			'openregister-unavailable' => 'BackfillClientRetention: nothing back-filled, because OpenRegister\'s retention service is not available.',
		];
		foreach ($expected as $status => $message) {
			$output = $this->createMock(IOutput::class);
			$output->expects($this->never())->method('info');
			$output->expects($this->once())->method('warning')->with($message);

			$this->runWith(result: ['status' => $status, 'applied' => 0, 'skipped' => 0, 'failed' => 0], output: $output);
		}
	}//end testANoOpRunWarnsWhy()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepNamesWhatItDoes(): void {
		$step = new BackfillClientRetention($this->createMock(RetentionMetadataBackfillService::class));

		$this->assertSame('Give existing clients and contact persons their retention term', $step->getName());
	}//end testTheStepNamesWhatItDoes()
}//end class
