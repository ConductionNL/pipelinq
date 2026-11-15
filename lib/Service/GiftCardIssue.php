<?php

/**
 * Pipelinq GiftCardIssue.
 *
 * What a new gift card is issued with: programme, opening balance, validity and recipient.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-01
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A request to issue one gift card.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-01
 */
final class GiftCardIssue {
	/**
	 * Constructor.
	 *
	 * @param ?string $programmeId    Optional programme UUID.
	 * @param float   $initialBalance Initial card balance.
	 * @param int     $expiryDays     Days until expiry.
	 * @param ?string $issuedIn       Recipient label.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-01
	 */
	public function __construct(
		public readonly ?string $programmeId,
		public readonly float $initialBalance,
		public readonly int $expiryDays,
		public readonly ?string $issuedIn,
	) {
	}//end __construct()
}//end class
