<?php

/**
 * Pipelinq TimelinePages.
 *
 * Pages the activity timeline and the worklog.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Slices a sorted activity timeline or worklog into the page a request asks for.
 *
 * The two listings clamp the page differently, and both behaviours are kept:
 * the timeline slices from the page asked for, the worklog from the last page.
 *
 * @spec openspec/specs/activity-timeline/spec.md
 */
final class TimelinePages {
	/**
	 * Slice a sorted activity list into the requested page envelope; the
	 * slice starts at the requested page, and the page reported is clamped
	 * to the last page.
	 *
	 * @param array<int,array<string,mixed>> $merged      The sorted activities.
	 * @param PageRequest                    $pageRequest The requested page and size.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md
	 */
	public function timelinePage(array $merged, PageRequest $pageRequest): array {
		$total = count($merged);
		$pages = $this->pageCount(total: $total, limit: $pageRequest->limit);
		$start = max(0, (($pageRequest->page - 1) * $pageRequest->limit));

		return [
			'items' => array_values(array_slice($merged, $start, $pageRequest->limit)),
			'total' => $total,
			'page' => min($pageRequest->page, $pages),
			'pages' => $pages,
		];
	}//end timelinePage()

	/**
	 * Slice the sorted worklog into the requested page; the page is clamped
	 * to the last page before the slice starts.
	 *
	 * @param array<int,array<string,mixed>> $items       The sorted worklog items.
	 * @param PageRequest                    $pageRequest The requested page and size.
	 *
	 * @return array{items: array<int,array<string,mixed>>, total: int, page: int, pages: int}
	 *
	 * @spec openspec/specs/activity-timeline/spec.md
	 */
	public function worklogPage(array $items, PageRequest $pageRequest): array {
		$lastPage = $this->pageCount(total: count($items), limit: $pageRequest->limit);
		$clamped = new PageRequest(page: min($pageRequest->page, $lastPage), limit: $pageRequest->limit);
		return $this->timelinePage(merged: $items, pageRequest: $clamped);
	}//end worklogPage()

	/**
	 * The number of pages of a listing; an empty listing has one page.
	 *
	 * @param int $total The number of items.
	 * @param int $limit The page size.
	 *
	 * @return int The number of pages.
	 */
	private function pageCount(int $total, int $limit): int {
		if ($total === 0) {
			return 1;
		}

		return (int)ceil($total / $limit);
	}//end pageCount()
}//end class
