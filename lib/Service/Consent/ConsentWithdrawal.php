<?php

/**
 * Pipelinq ConsentWithdrawal.
 *
 * One withdrawal of consent that ComplianceService records.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Consent
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
 * @spec openspec/specs/marketing-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Consent;

/**
 * Which ConsentRecord is withdrawn, why, from which blast, and when.
 *
 * @spec openspec/specs/marketing-compliance/spec.md
 */
final class ConsentWithdrawal {
	/**
	 * Constructor.
	 *
	 * @param ConsentRecordKey $key           The record withdrawn: contact, channel and list.
	 * @param string           $reason        Withdrawal reason.
	 * @param string|null      $sourceBlastId The blast it came from, if any.
	 * @param string           $withdrawnAt   UTC timestamp string.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly ConsentRecordKey $key,
		public readonly string $reason,
		public readonly ?string $sourceBlastId,
		public readonly string $withdrawnAt,
	) {
	}//end __construct()
}//end class
