<?php

/**
 * Contract of LoyaltyEngineService
 *
 * The public surface of LoyaltyEngineService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of LoyaltyEngineService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LoyaltyEngineServiceInterface {

	/**
	 * Process a POS transaction (or any 'purchase' trigger) and award points.
	 *
	 * Idempotency: callers MUST pass a unique posTransactionId; the service
	 * uses it in the ledger entry brondocument. Duplicate calls produce
	 * additional ledger entries — at-most-once is the caller's responsibility.
	 *
	 * @param string $customerId The Nextcloud contact UID.
	 * @param array<string, mixed> $transaction Transaction context (amount,
	 *                                          category, channel, posTransactionId, segment,
	 *                                          timestamp, posTerminalId).
	 *
	 * @return array<int, array<string, mixed>> One result per programme: status
	 *                                          (credited / skipped / blocked / error),
	 *                                          accountId, points, tierChanged.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function processPosTransaction(string $customerId, array $transaction): array;
}//end interface
