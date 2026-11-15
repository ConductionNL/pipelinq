<?php

/**
 * Characterisation test for the stage picking shared by the two lead placers.
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
use OCA\Pipelinq\Service\OrphanedLeadPlacer;
use PHPUnit\Framework\TestCase;

/**
 * Pins the lowest-order pick among unordered, unnamed and malformed stages, the
 * won and lost closed stages, the open-stage fallback and the entry moment.
 */
class LeadStagePickingTest extends TestCase {
	/**
	 * A pipeline with stages out of order, with gaps and junk.
	 *
	 * @return array<string, mixed>
	 */
	private function pipeline(): array {
		return [
			'id' => 'p-1',
			'isDefault' => true,
			'entityType' => 'lead',
			'stages' => [
				['name' => 'Won B', 'order' => 9, 'isClosed' => true, 'isWon' => true],
				'junk',
				['name' => '  ', 'order' => 0],
				['name' => 'Second', 'order' => '3'],
				['name' => 'Lost', 'order' => 8, 'isClosed' => true],
				['name' => 'First'],
				['name' => 'Won A', 'order' => 7, 'isClosed' => true, 'isWon' => true],
				['name' => 'Lost early', 'order' => 2, 'isClosed' => true, 'isWon' => false],
			],
		];
	}//end pipeline()

	/**
	 * The first open stage is the lowest order among named, open stages; a missing order sorts as 0 but reads as 1.
	 *
	 * @return void
	 */
	public function testFirstOpenStage(): void {
		$placer = new LeadStagePlacer();
		$this->assertSame(['name' => 'First', 'order' => 1], $placer->firstOpenStage(pipeline: $this->pipeline()));
		$this->assertNull($placer->firstOpenStage(pipeline: ['stages' => [['name' => 'Done', 'isClosed' => true]]]));
		$this->assertNull($placer->firstOpenStage(pipeline: []));
	}//end testFirstOpenStage()

	/**
	 * Won and lost leads go to the lowest closed stage of their kind; an open lead to the first open stage.
	 *
	 * @return void
	 */
	public function testOrphanedLeadStages(): void {
		$placer = new OrphanedLeadPlacer();
		$now = '2026-10-07T10:00:00+00:00';
		$this->assertSame(
			['pipeline' => 'p-1', 'stage' => 'Won A', 'stageOrder' => 7, 'stageEnteredAt' => $now],
			$placer->forOrphanedLead(lead: ['pipeline' => 'gone', 'status' => 'won'], pipelines: [$this->pipeline()], now: $now)
		);
		$this->assertSame(
			['pipeline' => 'p-1', 'stage' => 'Lost early', 'stageOrder' => 2, 'stageEnteredAt' => $now],
			$placer->forOrphanedLead(lead: ['pipeline' => 'gone', 'status' => 'lost'], pipelines: [$this->pipeline()], now: $now)
		);
		$this->assertSame(
			['pipeline' => 'p-1', 'stage' => 'First', 'stageOrder' => 1, 'stageEnteredAt' => $now],
			$placer->forOrphanedLead(lead: ['pipeline' => 'gone', 'status' => 'open'], pipelines: [$this->pipeline()], now: $now)
		);

		$openOnly = ['id' => 'p-2', 'isDefault' => true, 'entityType' => 'lead', 'stages' => [['name' => 'Only', 'order' => 4]]];
		$won = $placer->forOrphanedLead(lead: ['pipeline' => 'gone', 'status' => 'won'], pipelines: [$openOnly], now: '  ');
		$this->assertSame(['pipeline' => 'p-2', 'stage' => 'Only', 'stageOrder' => 4], array_slice($won, 0, 3));
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $won['stageEnteredAt']);

		$noStages = ['id' => 'p-3', 'isDefault' => true, 'entityType' => 'lead', 'stages' => []];
		$this->assertSame(['pipeline' => 'p-3'], $placer->forOrphanedLead(lead: ['pipeline' => 'gone'], pipelines: [$noStages]));
	}//end testOrphanedLeadStages()
}//end class
