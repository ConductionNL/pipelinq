<?php

/**
 * Pipelinq LookupQuery.
 *
 * What a person lookup asks for, read from the request.
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

use OCA\Pipelinq\Service\BsnAudit\LookupReason;

/**
 * The BSN, the compliance grounds and the links of one person lookup.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
 */
class LookupQuery {
	/**
	 * Constructor.
	 *
	 * @param string       $rawBsn The BSN as entered.
	 * @param LookupReason $reason Verzoekreden, doelbinding, linked request and VOG flag.
	 * @param string       $basis  Grondslag; the doelbinding when none was given.
	 * @param LookupLinks  $links  The contact and client to stamp.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $rawBsn,
		public readonly LookupReason $reason,
		public readonly string $basis,
		public readonly LookupLinks $links,
	) {
	}//end __construct()

	/**
	 * Whether the verzoekreden and the doelbinding are both given.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002-01
	 */
	public function hasPurpose(): bool {
		return $this->reason->verzoekreden !== '' && $this->reason->purposeBinding !== '';
	}//end hasPurpose()
}//end class
