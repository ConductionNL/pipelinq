<?php

/**
 * Pipelinq RowIdOf.
 *
 * The identity of an OpenRegister row, as a string.
 *
 * @category Util
 * @package  OCA\Pipelinq\Util
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * The row's {@see RowIdentity}, or an empty string when it has none.
 */
trait RowIdOf {
	use RowIdentity;

	/**
	 * The row's id.
	 *
	 * @param array<string, mixed> $object The row.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function idOf(array $object): string {
		return $this->rowIdentity(row: $object) ?? '';
	}//end idOf()
}//end trait
