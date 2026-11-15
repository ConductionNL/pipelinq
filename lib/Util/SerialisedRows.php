<?php

/**
 * Pipelinq SerialisedRows.
 *
 * Rows with a jsonSerialize() method or an array shape, as plain arrays.
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
 * Serialises rows that have a jsonSerialize() method and keeps the ones that
 * are, or serialise to, arrays; anything else is dropped.
 *
 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
 */
trait SerialisedRows {

	/**
	 * Rows as plain arrays; a row with no array shape is dropped.
	 *
	 * @param iterable<mixed> $rows The rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function serialisedRows(iterable $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			if (is_array($row) === true) {
				$out[] = $row;
			}
		}

		return $out;
	}//end serialisedRows()
}//end trait
