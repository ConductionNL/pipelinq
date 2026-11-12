<?php

/**
 * Pipelinq SchemaWriteTarget.
 *
 * Where a repair step reads and writes one schema's objects, and as whom.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair\Support;

use OCP\IUser;

/**
 * Where a repair step reads and writes one schema's objects, and as whom.
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-existing-records-backfill-channel-arrays-on-upgrade
 */
class SchemaWriteTarget {
	/**
	 * Constructor.
	 *
	 * @param object     $objectService OpenRegister ObjectService.
	 * @param string     $register      Register id.
	 * @param string     $schema        Schema id.
	 * @param IUser|null $actingAdmin   User to save as (repair steps have no session).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
		public readonly ?IUser $actingAdmin,
	) {
	}//end __construct()
}//end class
