<?php

/**
 * Contract of LoyaltyAccountService
 *
 * The public surface of LoyaltyAccountService that other components call, so they depend
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
 * Contract of LoyaltyAccountService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LoyaltyAccountServiceInterface {

	/**
	 * Get an account by UUID.
	 *
	 * @param string $accountId The account UUID.
	 *
	 * @return array<string, mixed>|null The account, or null when not found.
	 * @spec exclude loyalty has no spec at all; the change that specified it was archived
	 *   and nothing inherited it
	 */
	public function getAccount(string $accountId): ?array;

	/**
	 * List all accounts for a programme (paginated; soft-cap 1000).
	 *
	 * @param string $programmeId The programme UUID.
	 * @param int $limit Max results.
	 * @param int $offset Pagination offset.
	 *
	 * @return array<int, array<string, mixed>> The accounts.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
	 */
	public function listAccountsForProgramme(string $programmeId, int $limit = 1000, int $offset = 0): array;
}//end interface
