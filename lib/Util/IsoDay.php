<?php

/**
 * Pipelinq IsoDay.
 *
 * A Y-m-d date as midnight UTC, or null.
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

use DateTimeImmutable;
use DateTimeZone;

/**
 * Parses a strict, valid Y-m-d calendar date to midnight UTC; anything else is
 * null.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait IsoDay {

	/**
	 * Parse a `YYYY-MM-DD` string, or null.
	 *
	 * @param string|null $value The string.
	 *
	 * @return DateTimeImmutable|null UTC midnight or null.
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function parseDay(?string $value): ?DateTimeImmutable {
		if ($value === null || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $matches) !== 1) {
			return null;
		}

		if (checkdate((int)$matches[2], (int)$matches[3], (int)$matches[1]) === false) {
			return null;
		}

		return new DateTimeImmutable((trim($value) . 'T00:00:00'), new DateTimeZone('UTC'));
	}//end parseDay()
}//end trait
