<?php

/**
 * OpenRegister BulkStep
 *
 * Declaration-only stub of BulkStep, for the unit suite and static analysis; the
 * real class wins whenever OpenRegister is installed.
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
use OCP\IUser;

/**
 * One step of a bulk job: the action applied to one member object, rehearsed
 * or committed, as the user who started the job (ADR-011 Rule 2 operation
 * object), the one argument of BulkActionInterface::apply().
 */
final class BulkStep {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity         $object     The member object.
	 * @param array<string, mixed> $parameters The job's validated parameters.
	 * @param bool                 $commit     False to rehearse (preview), true to write.
	 * @param IUser|null           $actor      The user the job runs as, null when none.
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly array $parameters,
		public readonly bool $commit,
		public readonly ?IUser $actor,
	) {
	}//end __construct()
}//end class
