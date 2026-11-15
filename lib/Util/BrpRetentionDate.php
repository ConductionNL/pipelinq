<?php

/**
 * Pipelinq BrpRetentionDate.
 *
 * Reads the retention date of a cached BRP row.
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
use Throwable;

/**
 * The `retentionTo` of a cached BRP row as a UTC date, shared by the BRP cache
 * read and the retention sweep.
 *
 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
 */
trait BrpRetentionDate {

	/**
	 * The row's retention date, or null when absent or unparsable.
	 *
	 * @param array<string, mixed> $row The cached row.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec exclude shared plumbing of the classes that use it, no spec surface of its own.
	 */
	private function retentionDate(array $row): ?DateTimeImmutable {
		$retentionTo = (string)($row['retentionTo'] ?? '');
		if ($retentionTo === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($retentionTo, new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return null;
		}
	}//end retentionDate()
}//end trait
