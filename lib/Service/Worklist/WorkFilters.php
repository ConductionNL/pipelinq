<?php

/**
 * Pipelinq WorkFilters.
 *
 * The priority and pipeline filters of My work, applied to the work items
 * before they are sorted into a list.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Worklist
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Worklist;

use InvalidArgumentException;

/**
 * Keeps the work items whose priority is one of the chosen ones and, for
 * leads, whose pipeline is the chosen one. Tickets and follow-ups have no
 * pipeline, so the pipeline filter leaves them in the list.
 *
 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
 */
class WorkFilters {

	/**
	 * The priorities a filter may name.
	 *
	 * @var array<int, string>
	 */
	public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

	/**
	 * The chosen priorities; empty for all of them.
	 *
	 * @var array<int, string>
	 */
	private array $priorities;

	/**
	 * The chosen pipeline id; empty for every pipeline.
	 *
	 * @var string
	 */
	private string $pipeline;

	/**
	 * Build the filters from a comma-separated priority list (`urgent,high`)
	 * and a pipeline id, as the page sends them.
	 *
	 * @param string $priorities The comma-separated priorities; empty for all of them.
	 * @param string $pipeline   The pipeline id; empty for every pipeline.
	 *
	 * @throws InvalidArgumentException When a priority is not one of {@see self::PRIORITIES}.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	public function __construct(string $priorities='', string $pipeline='') {
		$chosen = [];
		foreach (explode(',', strtolower($priorities)) as $priority) {
			$priority = trim($priority);
			if ($priority === '') {
				continue;
			}

			if (in_array($priority, self::PRIORITIES, true) === false) {
				throw new InvalidArgumentException('Unknown priority: '.$priority);
			}

			$chosen[$priority] = $priority;
		}

		$this->priorities = array_values($chosen);
		$this->pipeline   = trim($pipeline);
	}//end __construct()

	/**
	 * How many filters are set: each chosen priority and the pipeline count
	 * as one, as "Filters (N)" reads it.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	public function activeCount(): int {
		$count = count($this->priorities);
		if ($this->pipeline !== '') {
			$count++;
		}

		return $count;
	}//end activeCount()

	/**
	 * The rows that pass the filters, in their original order.
	 *
	 * @param array<int, array<string, mixed>> $rows The work items (`kind`, `priority`, `record`).
	 *
	 * @return array<int, array<string, mixed>> The rows kept.
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	public function keep(array $rows): array {
		if ($this->activeCount() === 0) {
			return $rows;
		}

		return array_values(array_filter($rows, fn (array $row): bool => $this->passes(row: $row)));
	}//end keep()

	/**
	 * Whether one row passes the priority and the pipeline filter.
	 *
	 * @param array<string, mixed> $row The work item.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-11-25-my-work-roadmap/specs/my-work/spec.md#requirement-priority-and-pipeline-filters-req-mw-200
	 */
	private function passes(array $row): bool {
		$priority = (string)($row['priority'] ?? '');
		if ($priority === '') {
			$priority = 'normal';
		}

		if ($this->priorities !== [] && in_array($priority, $this->priorities, true) === false) {
			return false;
		}

		if ($this->pipeline === '' || ($row['kind'] ?? '') !== 'lead') {
			return true;
		}

		$record = $row['record'] ?? [];
		return is_array($record) === true && (string)($record['pipeline'] ?? '') === $this->pipeline;
	}//end passes()
}//end class
