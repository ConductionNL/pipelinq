<?php

/**
 * Pipelinq ByteTruncate.
 *
 * A string cut to a maximum byte length.
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
 * Answers the string unchanged when it fits, otherwise its first max bytes.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait ByteTruncate {

	/**
	 * Truncate a string to a maximum length.
	 *
	 * @param string $value Raw value.
	 * @param int $max Maximum length.
	 *
	 * @return string
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function truncate(string $value, int $max): string {
		if (strlen($value) <= $max) {
			return $value;
		}

		return substr($value, 0, $max);
	}//end truncate()
}//end trait
