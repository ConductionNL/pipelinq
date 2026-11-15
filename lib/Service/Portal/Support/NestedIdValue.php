<?php

/**
 * Pipelinq NestedIdValue.
 *
 * Reads a possibly-nested id value of a portal row.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Portal\Support
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
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Portal\Support;

/**
 * Turns a string id or an {id|uuid} reference into a string id.
 *
 * @spec exclude shared plumbing of the portal services, no spec surface of its own.
 */
trait NestedIdValue {

	/**
	 * Read a possibly-nested id value (string or {id|uuid}) into a string.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The id, or null.
	 *
	 * @spec exclude shared plumbing of the portal services, no spec surface of its own.
	 */
	private function readId(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_array($value) === true) {
			$id = ($value['id'] ?? $value['uuid'] ?? null);
			if ($id === null) {
				return null;
			}

			return (string)$id;
		}

		return (string)$value;
	}//end readId()
}//end trait
