<?php

/**
 * Contract of RedemptionService
 *
 * The public surface of RedemptionService that other components call, so they depend
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
 * Contract of RedemptionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RedemptionServiceInterface {

	/**
	 * List options the account can afford and that are currently valid.
	 *
	 * @param string $accountId The account UUID.
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<int, array<string, mixed>> Valid + affordable options.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getValidRedemptionOptions(string $accountId, string $programmeId): array;

	/**
	 * Initiate a redemption — reserve points + create a Redemption with status "reserved".
	 *
	 * @param string $accountId The account UUID.
	 * @param string $optionId The RedemptionOption UUID.
	 *
	 * @return array<string, mixed> The Redemption object.
	 *
	 * @throws RuntimeException On insufficient balance, expired option, per-customer limit reached.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-004-01
	 */
	public function initiateRedemption(string $accountId, string $optionId): array;

	/**
	 * Mark a redemption as used.
	 *
	 * @param string $redemptionId The Redemption UUID.
	 * @param ?string $posTransactionId Optional POS transaction id.
	 *
	 * @return array<string, mixed> The updated redemption.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-004
	 */
	public function markRedemptionUsed(string $redemptionId, ?string $posTransactionId = null): array;

	/**
	 * Validate a redemption code (without consuming it).
	 *
	 * @param string $code The beloningCode.
	 *
	 * @return array{valid: bool, redemption: ?array<string, mixed>, reason: ?string}
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-004
	 */
	public function validateCode(string $code): array;
}//end interface
