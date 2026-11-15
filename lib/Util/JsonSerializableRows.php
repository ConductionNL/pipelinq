<?php

/**
 * Pipelinq JsonSerializableRows.
 *
 * OpenRegister rows (arrays or JsonSerializable entities) as plain arrays.
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
 * Keeps array rows as they are and JsonSerializable rows by what they
 * serialise to; a row with no array shape is dropped.
 *
 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
 */
trait JsonSerializableRows {

	/**
	 * Rows as plain arrays; a row with no array shape is dropped.
	 *
	 * @param iterable<mixed> $rows The rows OpenRegister returned.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function plainRows(iterable $rows): array {
		$out = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (($row instanceof \JsonSerializable) === true) {
				$data = $row->jsonSerialize();
				if (is_array($data) === true) {
					$out[] = $data;
				}
			}
		}

		return $out;
	}//end plainRows()
}//end trait
