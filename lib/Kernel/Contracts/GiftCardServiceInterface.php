<?php

/**
 * Contract of GiftCardService
 *
 * The public surface of GiftCardService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of GiftCardService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GiftCardServiceInterface {

	/**
	 * Activate a card (issued → active) after the issuing POS transaction completes.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param ?string $posTransactionId The POS transaction id.
	 *
	 * @return ?array<string, mixed>
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-03
	 */
	public function activateGiftCard(string $giftCardId, ?string $posTransactionId = null): ?array;

	/**
	 * Issue a new gift card (status "issued", balance = initial, PIN hashed).
	 *
	 * @param ?string $programmeId Optional programme UUID.
	 * @param float $initialBalance Initial card balance.
	 * @param int $expiryDays Days until expiry (default 365).
	 * @param string $channel Issuance channel.
	 * @param ?string $issuedIn Recipient label.
	 *
	 * @return array{card: array<string, mixed>, pin: string} Card + plaintext PIN (returned ONCE).
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-01
	 */
	public function issueGiftCard(
		?string $programmeId,
		float $initialBalance,
		int $expiryDays = 365,
		string $channel = 'purchased',
		?string $issuedIn = null,
	): array;

	/**
	 * Redeem (debit) an amount from a gift card. Supports partial redemption.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param string $pin The plaintext PIN (verified against hash).
	 * @param float $amount Amount to redeem (positive).
	 * @param ?string $posTransactionId Optional POS transaction id.
	 *
	 * @return array{amountApplied: float, balanceAfter: float, changeAmount: float, status: string}
	 *
	 * @throws RuntimeException On invalid PIN, expired, blocked, or non-active card.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-01
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-02
	 */
	public function redeemGiftCard(
		string $giftCardId,
		string $pin,
		float $amount,
		?string $posTransactionId = null,
	): array;

	/**
	 * Validate a card by serial + PIN (used at POS).
	 *
	 * @param string $serial The serial.
	 * @param string $pin The plaintext PIN.
	 *
	 * @return array{valid: bool, balance: float, expiryDate: ?string, giftCardId: ?string, reason: ?string}
	 *
	 * @spec exclude mechanical phpmd cleanup — no behaviour change
	 */
	public function validateBySerial(string $serial, string $pin): array;
}//end interface
