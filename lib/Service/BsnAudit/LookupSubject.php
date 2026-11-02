<?php

/**
 * Pipelinq LookupSubject.
 *
 * The data subject of a BSN audit record.
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
 * Whose BSN an audit record is about: the raw BSN, hashed in-process and never stored, or a hash the caller already holds.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-005-01
 */
class LookupSubject {
	/**
	 * Constructor.
	 *
	 * @param string  $rawBsn  Raw 9-digit BSN, possibly empty.
	 * @param ?string $bsnHash Already-computed subject hash; takes precedence over hashing $rawBsn.
	 *
	 * @return void
	 */
	public function __construct(
		#[\SensitiveParameter] public readonly string $rawBsn,
		public readonly ?string $bsnHash = null,
	) {
	}//end __construct()
}//end class
