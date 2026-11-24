<?php

/**
 * Pipelinq NaviRowCounts.
 *
 * Counting rules behind the Navi chart and table answers: leads per ISO
 * week, leads per status with the won rate, and requests per category.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Stateless row counters for Navi answers.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
 */
final class NaviRowCounts {
	/**
	 * Count rows per ISO week of their creation date, oldest week first.
	 *
	 * Rows without a parseable `createdAt` / `created` value are skipped.
	 *
	 * @param array<int, array<string, mixed>> $rows Lead rows.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
	 *
	 * @return array<string, int>
	 */
	public function weeklyCounts(array $rows): array {
		$buckets = [];
		foreach ($rows as $row) {
			$timestamp = strtotime((string)($row['createdAt'] ?? $row['created'] ?? ''));
			if ($timestamp !== false) {
				$week = date('o-\WW', $timestamp);
				$buckets[$week] = ($buckets[$week] ?? 0) + 1;
			}
		}

		ksort($buckets);
		return $buckets;
	}//end weeklyCounts()


	/**
	 * Percentage of won leads, one decimal; 0.0 when nothing was counted.
	 *
	 * @param int $won   Won leads.
	 * @param int $total Open, won and lost leads.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
	 *
	 * @return float
	 */
	public function wonRate(int $won, int $total): float {
		if ($total === 0) {
			return 0.0;
		}

		return round(($won * 100.0) / $total, 1);
	}//end wonRate()


	/**
	 * Count open / won / lost rows; other statuses are ignored.
	 *
	 * @param array<int, array<string, mixed>> $rows Lead rows.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
	 *
	 * @return array{open: int, won: int, lost: int}
	 */
	public function statusCounts(array $rows): array {
		$counts = ['open' => 0, 'won' => 0, 'lost' => 0];
		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if (isset($counts[$status]) === true) {
				$counts[$status]++;
			}
		}

		return $counts;
	}//end statusCounts()


	/**
	 * Count rows per non-empty category, largest first.
	 *
	 * @param array<int, array<string, mixed>> $rows Request-ticket rows.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-navi-ai-analytics-widget-req-dash-001
	 *
	 * @return array<string, int>
	 */
	public function categoryCounts(array $rows): array {
		$counts = [];
		foreach ($rows as $row) {
			// The request's free-text `category` keeps its name on the ticket
			// schema (the complaint's category became complaintCategory).
			$cat = (string)($row['category'] ?? '');
			if ($cat !== '') {
				$counts[$cat] = ($counts[$cat] ?? 0) + 1;
			}
		}

		arsort($counts);
		return $counts;
	}//end categoryCounts()
}//end class
