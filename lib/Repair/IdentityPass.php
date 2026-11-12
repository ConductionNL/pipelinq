<?php

/**
 * Pipelinq IdentityPass.
 *
 * One collection resolved as one kind of identity.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

/**
 * The collection one resolve loop reads and writes, and the kind of
 * identity its objects are.
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 */
final class IdentityPass {
	/**
	 * Constructor.
	 *
	 * @param IdentityCollection $collection The collection.
	 * @param IdentityKind       $kind       The kind of identity in it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly IdentityCollection $collection,
		public readonly IdentityKind $kind,
	) {
	}//end __construct()
}//end class
