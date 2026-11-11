<?php

/**
 * Pipelinq ListPage.
 *
 * One page of a Blast REST listing.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * A 1-based page and a page size, clamped the way the Blast listings
 * clamp them, with the paging pushed to OpenRegister and the envelope the
 * listings answer.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
 */
final class ListPage {
	/**
	 * Constructor.
	 *
	 * @param int $page  1-based page number.
	 * @param int $limit Page size.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int $page,
		public readonly int $limit,
	) {
	}//end __construct()

	/**
	 * The page asked for, clamped to at least page 1 and a size of 1..100.
	 *
	 * @param int $page  1-based page number.
	 * @param int $limit Page size.
	 *
	 * @return self The clamped page.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public static function clamped(int $page, int $limit): self {
		return new self(page: max(1, $page), limit: min(100, max(1, $limit)));
	}//end clamped()

	/**
	 * The paging pushed to OpenRegister.
	 *
	 * @return array{limit: int, offset: int}
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function window(): array {
		return [
			'limit' => $this->limit,
			'offset' => (($this->page - 1) * $this->limit),
		];
	}//end window()

	/**
	 * The query for this page of the given filters, newest first.
	 *
	 * @param array<string, mixed> $filters The scoped filters.
	 *
	 * @return array<string, mixed> The findAll config.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function newestFirst(array $filters): array {
		return ['filters' => $filters] + $this->window() + ['sort' => ['createdAt' => 'desc']];
	}//end newestFirst()

	/**
	 * The listing envelope for this page.
	 *
	 * @param array<int, array<string, mixed>> $data  The rows of the page.
	 * @param int                              $total The number of rows over all pages.
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#blastcontroller-task-2.6-of-giant
	 */
	public function envelope(array $data, int $total): array {
		return [
			'data' => $data,
			'pagination' => [
				'page' => $this->page,
				'limit' => $this->limit,
				'total' => $total,
				'pages' => $this->pageCount(total: $total),
			],
		];
	}//end envelope()

	/**
	 * Compute the page-count from a total and this page size.
	 *
	 * @param int $total Total rows.
	 *
	 * @return int Pages, or 0 when there is nothing to page.
	 */
	private function pageCount(int $total): int {
		if ($total <= 0 || $this->limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $this->limit);
	}//end pageCount()
}//end class
