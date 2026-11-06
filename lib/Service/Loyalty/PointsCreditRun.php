<?php

/**
 * Pipelinq PointsCreditRun.
 *
 * One POS transaction being credited to one programme's account.
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
 * One POS transaction being credited to one programme's account.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
 */
class PointsCreditRun {
	/**
	 * Constructor.
	 *
	 * @param string               $programmeId The programme UUID.
	 * @param string               $accountId   The account UUID.
	 * @param array<string, mixed> $transaction The raw transaction payload.
	 * @param array<string, mixed> $context     The rule-evaluation context built from it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $programmeId,
		public readonly string $accountId,
		public readonly array $transaction,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * The source document a ledger credit links back to.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
	 */
	public function sourceDocument(): array {
		return [
			'transactionId' => $this->transaction['posTransactionId'] ?? null,
			'amount' => $this->context['amount'],
			'channel' => $this->context['channel'],
		];
	}//end sourceDocument()

	/**
	 * Who processed the credit: the POS terminal, or the system.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
	 */
	public function processedBy(): string {
		return (string)($this->transaction['posTerminalId'] ?? 'system');
	}//end processedBy()

	/**
	 * The ledger operation id of this credit: the POS transaction, so a
	 * resent transaction is credited once; '' without a transaction id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/loyalty-ledger-operation-id/specs/loyalty-program/spec.md#requirement-a-retried-movement-is-written-once-req-loy-011
	 */
	public function operationId(): string {
		$transactionId = (string)($this->transaction['posTransactionId'] ?? '');
		if ($transactionId === '') {
			return '';
		}

		return 'pos:'.$transactionId;
	}//end operationId()
}//end class
