<?php

/**
 * Pipelinq IdentityCollection.
 *
 * One schema of the pipelinq register that the identity pass reads or writes.
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
 * The ObjectService, register and schema of one collection the identity
 * pass works on; an empty schema is an unconfigured collection.
 *
 * @spec openspec/specs/unify-client-contact/spec.md
 */
final class IdentityCollection {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OR ObjectService.
	 * @param string $register      The register id/slug.
	 * @param string $schema        The schema id/slug, or empty when not configured.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()
}//end class
