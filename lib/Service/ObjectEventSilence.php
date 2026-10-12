<?php

/**
 * Pipelinq ObjectEventSilence.
 *
 * Says whether OpenRegister withholds the object events for a write.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Whether a write made now reaches no notification, activity or listener.
 *
 * OpenRegister withholds `ObjectCreatedEvent` / `ObjectUpdatedEvent` for every
 * write made inside `SystemOperationContext::run()` (which
 * `ObjectService::runAsSystem()` enters): `MagicMapper::suppressLifecycleEvents()`
 * gates the dispatch itself. Notification rules, the activity publisher and
 * every app listener hang off that event, so withholding it is the only silent
 * path. `saveObject(silent: true)` is not: it skips the audit trail and the
 * inverse relations, and the event still fires.
 *
 * Both checks name OpenRegister classes by string, because pipelinq does not
 * hard-depend on a given OpenRegister build. A build without the gate answers
 * "not silent", and the caller then writes nothing.
 *
 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
 */
class ObjectEventSilence {
	/**
	 * OpenRegister's system-scope marker.
	 *
	 * @var string
	 */
	private const CONTEXT_CLASS = 'OCA\OpenRegister\Service\SystemOperationContext';

	/**
	 * The mapper that dispatches the object events.
	 *
	 * @var string
	 */
	private string $mapperClass = 'OCA\OpenRegister\Db\MagicMapper';

	/**
	 * Whether this OpenRegister can withhold the object events at all.
	 *
	 * @return bool True when the system scope and the dispatch gate both exist.
	 *
	 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
	 */
	public function isSupported(): bool {
		// Private methods count for method_exists() too. The class name is read from
		// a property, not a constant: phpstan resolves the test stub of
		// MagicMapper, which has no gate, and would call the check always false.
		return class_exists(self::CONTEXT_CLASS) === true
			&& method_exists($this->mapperClass, 'suppressLifecycleEvents') === true;
	}//end isSupported()

	/**
	 * Whether a write made right now has its object events withheld.
	 *
	 * @return bool True inside an active system scope on a build that gates the dispatch.
	 *
	 * @phpstan-impure The answer depends on OpenRegister's ambient scope, which changes between calls.
	 *
	 * @spec openspec/changes/round5-task-name-backfill/specs/repair-steps/spec.md#requirement-existing-tasks-are-named-after-their-subject-without-telling-anyone
	 */
	public function isActive(): bool {
		if ($this->isSupported() === false) {
			return false;
		}

		$isActive = [self::CONTEXT_CLASS, 'isActive'];

		return is_callable($isActive) === true && call_user_func($isActive) === true;
	}//end isActive()
}//end class
