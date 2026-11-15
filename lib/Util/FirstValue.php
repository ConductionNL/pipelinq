<?php

/**
 * Pipelinq FirstValue.
 *
 * The first element of a list value, or the value itself, as a string.
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
 * A list value answers its first element; any other value answers itself, both
 * cast to string.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait FirstValue {

	/**
	 * Extract first value from a vCard property that may be an array or string.
	 *
	 * @param mixed $value The value to extract from.
	 *
	 * @return string The extracted string value.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function extractFirstValue(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value[0] ?? '');
		}

		return (string)$value;
	}//end extractFirstValue()
}//end trait
