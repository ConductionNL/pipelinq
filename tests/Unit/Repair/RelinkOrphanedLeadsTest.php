<?php

/**
 * Unit tests for RelinkOrphanedLeads.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\RelinkOrphanedLeads;
use OCA\Pipelinq\Service\OrphanedLeadRepairService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step runs the orphaned-lead repair once and reports its counts.
 *
 * @covers \OCA\Pipelinq\Repair\RelinkOrphanedLeads
 */
class RelinkOrphanedLeadsTest extends TestCase {

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		$step = new RelinkOrphanedLeads($this->createMock(OrphanedLeadRepairService::class));

		self::assertSame('Move leads off deleted pipelines onto the default pipeline', $step->getName());
	}//end testTheStepIsNamed()

	/**
	 * The repair runs once and its moved and failed counts are reported.
	 *
	 * @return void
	 */
	public function testRunReportsTheRepairCounts(): void {
		$service = $this->createMock(OrphanedLeadRepairService::class);
		$service->expects(self::once())->method('repair')->willReturn(['moved' => 4, 'failed' => 1]);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(
			'RelinkOrphanedLeads: 4 lead(s) moved off a deleted pipeline, 1 could not be saved'
		);

		(new RelinkOrphanedLeads($service))->run($output);
	}//end testRunReportsTheRepairCounts()
}//end class
