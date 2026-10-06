<?php

/**
 * Unit tests for LeadStagePlacer: every lead sits in a pipeline stage.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\LeadStagePlacer;
use PHPUnit\Framework\TestCase;

/**
 * Where a lead without a stage goes.
 */
class LeadStagePlacerTest extends TestCase {

	/**
	 * A sales pipeline whose stages are stored out of order.
	 *
	 * @return array<string, mixed>
	 */
	private function sales(): array {
		return [
			'id' => 'p-sales',
			'isDefault' => true,
			'propertyMappings' => [['schemaSlug' => 'lead']],
			'stages' => [
				['name' => 'Won', 'order' => 5, 'isClosed' => true, 'isWon' => true],
				['name' => 'Contacted', 'order' => 2],
				['name' => 'New', 'order' => 1],
			],
		];
	}//end sales()

	/**
	 * A lead on a pipeline without a stage goes to the first open stage.
	 *
	 * @return void
	 */
	public function testLeadOnAPipelineGetsTheFirstOpenStage(): void {
		$patch = (new LeadStagePlacer())->forNewLead(
			lead: ['title' => 'Tender', 'pipeline' => 'p-sales'],
			pipelines: [$this->sales()],
			now: '2026-10-06T10:00:00+00:00',
		);

		$this->assertSame(
			['stage' => 'New', 'stageOrder' => 1, 'stageEnteredAt' => '2026-10-06T10:00:00+00:00'],
			$patch
		);
	}//end testLeadOnAPipelineGetsTheFirstOpenStage()

	/**
	 * A lead with neither pipeline nor stage goes on the default lead pipeline.
	 *
	 * @return void
	 */
	public function testLeadWithoutPipelineGoesOnTheDefaultLeadPipeline(): void {
		$requests = ['id' => 'p-req', 'isDefault' => true, 'propertyMappings' => [['schemaSlug' => 'request']], 'stages' => [['name' => 'Open', 'order' => 1]]];
		$patch = (new LeadStagePlacer())->forNewLead(
			lead: ['title' => 'Enquiry'],
			pipelines: [$requests, $this->sales()],
		);

		$this->assertSame('p-sales', $patch['pipeline']);
		$this->assertSame('New', $patch['stage']);
	}//end testLeadWithoutPipelineGoesOnTheDefaultLeadPipeline()

	/**
	 * A lead that has a stage keeps it; only the missing order is filled in.
	 *
	 * @return void
	 */
	public function testExistingStageIsKeptAndItsOrderFilled(): void {
		$patch = (new LeadStagePlacer())->forNewLead(
			lead: ['pipeline' => 'p-sales', 'stage' => 'Contacted', 'stageEnteredAt' => '2026-10-01T00:00:00+00:00'],
			pipelines: [$this->sales()],
		);

		$this->assertSame(['stageOrder' => 2], $patch);
	}//end testExistingStageIsKeptAndItsOrderFilled()

	/**
	 * The repair does not restart aging for a lead that already had a stage.
	 *
	 * @return void
	 */
	public function testNoEntryStampForAnExistingStageWhenAskedNotTo(): void {
		$patch = (new LeadStagePlacer())->forStoredLead(
			lead: ['pipeline' => 'p-sales', 'stage' => 'New', 'stageOrder' => 1],
			pipelines: [$this->sales()],
		);

		$this->assertSame([], $patch);
	}//end testNoEntryStampForAnExistingStageWhenAskedNotTo()

	/**
	 * A lead on an unknown pipeline is left alone rather than moved.
	 *
	 * @return void
	 */
	public function testUnknownPipelineIsLeftAlone(): void {
		$patch = (new LeadStagePlacer())->forNewLead(
			lead: ['pipeline' => 'p-gone'],
			pipelines: [$this->sales()],
		);

		$this->assertSame([], $patch);
	}//end testUnknownPipelineIsLeftAlone()

	/**
	 * Closed stages are never chosen, and an unscoped pipeline holds leads.
	 *
	 * @return void
	 */
	public function testClosedStagesAreSkippedAndUnscopedPipelinesCount(): void {
		$placer = new LeadStagePlacer();
		$this->assertNull($placer->firstOpenStage(['stages' => [['name' => 'Lost', 'order' => 1, 'isClosed' => true]]]));
		$this->assertSame('p-any', $placer->defaultPipeline([['id' => 'p-any', 'stages' => []]])['id']);
	}//end testClosedStagesAreSkippedAndUnscopedPipelinesCount()
}//end class
