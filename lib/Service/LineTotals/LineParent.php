<?php

/**
 * Pipelinq LineParent.
 *
 * The quote or lead a line belongs to.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\LineTotals
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\LineTotals;

/**
 * The parent of a line: which kind of line it is and the id of its quote or lead.
 *
 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
 */
class LineParent {
	/**
	 * Constructor.
	 *
	 * @param string $kind     The line schema slug: salesQuoteLine or leadProduct.
	 * @param string $parentId The quote or lead id.
	 *
	 * @spec openspec/changes/line-totals-roll-up/tasks.md#task-1
	 */
	public function __construct(
		public readonly string $kind,
		public readonly string $parentId,
	) {
	}//end __construct()
}//end class
