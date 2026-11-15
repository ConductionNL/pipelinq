<?php

/**
 * Pipelinq NonEmptyString.
 *
 * A scalar as a non-empty string, or null.
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
 * Null, non-scalar and empty values are null; any other scalar is its string
 * form.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait NonEmptyString {

	/**
	 * Coerce a value to a string, or return null when empty/null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return string|null The string value or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$stringValue = '';
		if (is_scalar($value) === true) {
			$stringValue = (string)$value;
		}

		if ($stringValue === '') {
			return null;
		}

		return $stringValue;
	}//end stringOrNull()
}//end trait
