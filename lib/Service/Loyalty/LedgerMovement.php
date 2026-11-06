<?php

/**
 * Pipelinq LedgerMovement.
 *
 * One points movement on one account: its type, signed count and lifetime contribution.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Loyalty
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Loyalty;

/**
 * One points movement on one account: its type, signed count and lifetime contribution.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
class LedgerMovement {
	/**
	 * Constructor.
	 *
	 * @param string $accountId     The account UUID.
	 * @param string $type          One of credit/debit/expiry/adjustment/refund.
	 * @param int    $signedCount   Signed delta.
	 * @param int    $lifetimeDelta Positive contribution to lifetimePoints (credits only).
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $accountId,
		public readonly string $type,
		public readonly int $signedCount,
		public readonly int $lifetimeDelta,
	) {
	}//end __construct()
}//end class
