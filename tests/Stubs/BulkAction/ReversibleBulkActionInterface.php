<?php

/**
 * OpenRegister ReversibleBulkActionInterface
 *
 * A bulk action whose writes can be undone.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BulkAction
 * @package  OCA\OpenRegister\BulkAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BulkAction;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The contract an action implements to be undoable.
 */
interface ReversibleBulkActionInterface extends BulkActionInterface {

	/**
	 * The key under which a plan carries the values as they were.
	 *
	 * @var string
	 */
	public const PLAN_PRIOR = 'prior';

	/**
	 * The key under which a plan carries the values the action writes.
	 *
	 * @var string
	 */
	public const PLAN_APPLIED = 'applied';

	/**
	 * How long after the job runs a reversal is still accepted, in seconds.
	 *
	 * A window is mandatory. Without one the recorded prior values are an
	 * unbounded second copy of the register, and the older the copy the more
	 * likely restoring it destroys somebody's later work (D-2, D-3).
	 *
	 * @return int The window, in seconds.
	 */
	public function getReversalWindow(): int;

	/**
	 * What this action would change on one object, and what it would change it from.
	 *
	 * The two maps carry the SAME keys: only the properties the action
	 * touches, never a snapshot of the object (D-2). `prior` is what the
	 * reversal writes back; `applied` is what the reversal compares against
	 * the object's current values to notice somebody's later edit (D-3).
	 *
	 * A property the object does not carry yet is recorded as null in
	 * `prior`, so restoring it clears the property rather than leaving the
	 * action's value in place.
	 *
	 * @param ObjectEntity         $object     The object to act on.
	 * @param array<string, mixed> $parameters The job's parameters.
	 *
	 * @return array{prior: array<string, mixed>, applied: array<string, mixed>} The plan.
	 */
	public function reversalPlanFor(ObjectEntity $object, array $parameters): array;
}//end interface
