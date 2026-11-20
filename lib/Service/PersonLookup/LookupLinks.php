<?php

/**
 * Pipelinq LookupLinks.
 *
 * The records a person lookup is linked to.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\PersonLookup
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\PersonLookup;

/**
 * The contact and client a person lookup verifies.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
 */
class LookupLinks {
	/**
	 * Constructor.
	 *
	 * @param string $contactId     Contact UUID to stamp, or ''.
	 * @param string $clientId      Client UUID whose identity is verified, or ''.
	 * @param string $identityCheck What the employee checked, or ''.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $contactId,
		public readonly string $clientId,
		public readonly string $identityCheck,
	) {
	}//end __construct()
}//end class
