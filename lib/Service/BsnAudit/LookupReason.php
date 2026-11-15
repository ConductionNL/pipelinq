<?php

/**
 * Pipelinq LookupReason.
 *
 * Why a BSN lookup was made.
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
 * The compliance grounds of a BSN lookup.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */
class LookupReason {
	/**
	 * Constructor.
	 *
	 * @param string  $verzoekreden   Verzoekreden.
	 * @param string  $purposeBinding Doelbinding.
	 * @param ?string $linkedRequest  UUID of the linked Pipelinq verzoek, or null.
	 * @param bool    $vogScreening   VOG-screening flag for Justis.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $verzoekreden,
		public readonly string $purposeBinding,
		public readonly ?string $linkedRequest,
		public readonly bool $vogScreening,
	) {
	}//end __construct()
}//end class
