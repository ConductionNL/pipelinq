<?php

/**
 * Pipelinq LockedRecord.
 *
 * Whether a record read from OpenRegister is locked (frozen), so a background
 * run can skip it instead of writing to it and being refused.
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
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Util;

/**
 * A locked record refuses every data write; background paths ask this first.
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
 */
final class LockedRecord {

	/**
	 * Whether a record is locked: an object whose `isFrozen()` says so, or a
	 * serialised row whose `@self.frozen` holds the lock.
	 *
	 * @param mixed $record The record: an object entity or its array form.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md#requirement-a-user-can-lock-a-client-a-finished-lead-or-a-finished-ticket-req-prl-001
	 */
	public static function isLocked(mixed $record): bool {
		if (is_object($record) === true && method_exists($record, 'isFrozen') === true) {
			return $record->isFrozen() === true;
		}

		if (is_array($record) === false) {
			return false;
		}

		$self = ($record['@self'] ?? null);
		if (is_array($self) === false) {
			return false;
		}

		$frozen = ($self['frozen'] ?? null);

		return is_array($frozen) === true && $frozen !== [];
	}//end isLocked()
}//end class
