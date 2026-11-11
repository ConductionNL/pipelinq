<?php

/**
 * Pipelinq LoyaltyPurchase.
 *
 * One POS purchase being credited across the loyalty programmes: the customer
 * and the transaction context.
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
 * A purchase to credit; built by LoyaltyEngineService.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
final class LoyaltyPurchase {
	/**
	 * Constructor.
	 *
	 * @param string               $customerId  The Nextcloud contact UID.
	 * @param array<string, mixed> $transaction The transaction context.
	 */
	public function __construct(
		public readonly string $customerId,
		public readonly array $transaction,
	) {
	}//end __construct()
}//end class
