<?php

/**
 * Pipelinq CategoryOverride.
 *
 * The latest manager override of one forecast category, if any.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Forecast
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
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-03
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Forecast;

/**
 * A category (`commit` or `best_case`, also the roll-up key it overrides) and its latest override row.
 *
 * @spec openspec/specs/forecast-roll-up-and-categories/spec.md#REQ-FRC-006-03
 */
final class CategoryOverride {
	/**
	 * Constructor.
	 *
	 * @param string                    $category The category and roll-up key.
	 * @param array<string, mixed>|null $row      The latest override, or null when none.
	 */
	public function __construct(
		public readonly string $category,
		public readonly ?array $row,
	) {
	}//end __construct()
}//end class
