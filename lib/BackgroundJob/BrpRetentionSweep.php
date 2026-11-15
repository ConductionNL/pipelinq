<?php

/**
 * Pipelinq BrpRetentionSweep.
 *
 * One retention pass of {@see BrpRetentionJob}: the object service, where the
 * person and contact records live, and the time the pass runs at.
 *
 * @category BackgroundJob
 * @package  OCA\Pipelinq\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\BackgroundJob;

use DateTimeImmutable;

/**
 * The object service, register, schemas and time of one retention pass.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008
 */
final class BrpRetentionSweep {

	/**
	 * Constructor.
	 *
	 * @param object            $objects       OpenRegister's object service.
	 * @param string            $register      The register.
	 * @param string            $personSchema  The BrpPersoon schema.
	 * @param string            $contactSchema The contact schema; empty when not configured.
	 * @param DateTimeImmutable $now           The sweep time (UTC).
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-008
	 */
	public function __construct(
		public readonly object $objects,
		public readonly string $register,
		public readonly string $personSchema,
		public readonly string $contactSchema,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
