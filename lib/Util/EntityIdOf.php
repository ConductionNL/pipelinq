<?php

/**
 * Pipelinq EntityIdOf.
 *
 * The identity of an OpenRegister row or entity, as a string.
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
 * The {@see RowIdentity} of what the host class's toArray() makes of the value,
 * or an empty string when it has none. The host class provides toArray().
 */
trait EntityIdOf {
	use RowIdentity;

	/**
	 * The row's or entity's id.
	 *
	 * @param array<string, mixed>|object $object The row or entity.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec exclude result-shape plumbing shared by register reads, no behavioural spec surface.
	 */
	private function idOf(array|object $object): string {
		$row = $this->toArray(object: $object);
		if ($row === null) {
			return '';
		}

		return $this->rowIdentity(row: $row) ?? '';
	}//end idOf()
}//end trait
