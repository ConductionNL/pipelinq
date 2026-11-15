<?php

/**
 * Pipelinq LookupAudit.
 *
 * One BSN lookup to record in the audit trail.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\BsnAudit
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\BsnAudit;

/**
 * Everything an audit record states about one lookup, apart from the acting user.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */
class LookupAudit {
	/**
	 * Constructor.
	 *
	 * @param LookupSubject $subject   Whose BSN it is.
	 * @param LookupReason  $reason    Why it was looked up.
	 * @param LookupResult  $result    What the lookup came to.
	 * @param ?string       $actorRole Role of the actor (behandelaar-burgerzaken), or null.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly LookupSubject $subject,
		public readonly LookupReason $reason,
		public readonly LookupResult $result,
		public readonly ?string $actorRole = null,
	) {
	}//end __construct()
}//end class
