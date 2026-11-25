<?php

/**
 * WorkFilters: the priority and pipeline filters of My work.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Worklist
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Worklist;

use InvalidArgumentException;
use OCA\Pipelinq\Service\Worklist\WorkFilters;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Worklist\WorkFilters
 */
class WorkFiltersTest extends TestCase {

	/**
	 * A mixed worklist: two leads in two pipelines, a ticket and a follow-up.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			['kind' => 'lead', 'id' => 'l1', 'priority' => 'urgent', 'record' => ['pipeline' => 'sales']],
			['kind' => 'lead', 'id' => 'l2', 'priority' => 'high', 'record' => ['pipeline' => 'events']],
			['kind' => 'ticket', 'id' => 't1', 'priority' => 'high', 'record' => []],
			['kind' => 'task', 'id' => 'f1', 'priority' => '', 'record' => []],
		];
	}//end rows()

	/**
	 * The ids of the rows kept.
	 *
	 * @param WorkFilters $filters The filters.
	 *
	 * @return array<int, string>
	 */
	private function kept(WorkFilters $filters): array {
		return array_column($filters->keep(rows: $this->rows()), 'id');
	}//end kept()

	/**
	 * Without filters every row stays, in order.
	 *
	 * @return void
	 */
	public function testNoFiltersKeepEverything(): void {
		$filters = new WorkFilters(priorities: '', pipeline: '');

		$this->assertSame(expected: ['l1', 'l2', 't1', 'f1'], actual: $this->kept(filters: $filters));
		$this->assertSame(expected: 0, actual: $filters->activeCount());
	}//end testNoFiltersKeepEverything()

	/**
	 * Scenario "Urgent and high only": only urgent and high items are listed.
	 *
	 * @return void
	 */
	public function testUrgentAndHighOnly(): void {
		$filters = new WorkFilters(priorities: 'urgent, HIGH', pipeline: '');

		$this->assertSame(expected: ['l1', 'l2', 't1'], actual: $this->kept(filters: $filters));
		$this->assertSame(expected: 2, actual: $filters->activeCount());
	}//end testUrgentAndHighOnly()

	/**
	 * An item without a priority counts as normal, as the list ranks it.
	 *
	 * @return void
	 */
	public function testAMissingPriorityIsNormal(): void {
		$this->assertSame(expected: ['f1'], actual: $this->kept(filters: new WorkFilters(priorities: 'normal', pipeline: '')));
	}//end testAMissingPriorityIsNormal()

	/**
	 * Scenario "One pipeline": the other pipeline's leads go, tickets and follow-ups stay.
	 *
	 * @return void
	 */
	public function testOnePipelineLeavesTicketsAndFollowUps(): void {
		$filters = new WorkFilters(priorities: '', pipeline: 'sales');

		$this->assertSame(expected: ['l1', 't1', 'f1'], actual: $this->kept(filters: $filters));
		$this->assertSame(expected: 1, actual: $filters->activeCount());
	}//end testOnePipelineLeavesTicketsAndFollowUps()

	/**
	 * Both filters together: each must pass, and every chosen value counts once.
	 *
	 * @return void
	 */
	public function testPriorityAndPipelineTogether(): void {
		$filters = new WorkFilters(priorities: 'high,high', pipeline: 'events');

		$this->assertSame(expected: ['l2', 't1'], actual: $this->kept(filters: $filters));
		$this->assertSame(expected: 2, actual: $filters->activeCount());
	}//end testPriorityAndPipelineTogether()

	/**
	 * A lead without a pipeline is not in any chosen pipeline.
	 *
	 * @return void
	 */
	public function testALeadWithoutAPipelineIsDroppedByThePipelineFilter(): void {
		$filters = new WorkFilters(priorities: '', pipeline: 'sales');

		$lead = ['kind' => 'lead', 'id' => 'l3', 'priority' => 'low', 'record' => []];
		$this->assertSame(expected: [], actual: $filters->keep(rows: [$lead]));
	}//end testALeadWithoutAPipelineIsDroppedByThePipelineFilter()

	/**
	 * An unknown priority is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownPriorityIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);

		new WorkFilters(priorities: 'urgent,critical', pipeline: '');
	}//end testAnUnknownPriorityIsRefused()
}//end class
