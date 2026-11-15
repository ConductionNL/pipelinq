<?php

/**
 * Contract of KeywordTargetService
 *
 * The public surface of KeywordTargetService that other components call, so they depend
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

use UnexpectedValueException;

/**
 * Contract of KeywordTargetService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface KeywordTargetServiceInterface {

	/**
	 * Every keyword target.
	 *
	 * @return array<int, array<string, mixed>> The targets.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function all(): array;

	/**
	 * Confirm a proposal into a keyword target.
	 *
	 * @param array<string, mixed> $payload The confirmation: term, status, and optionally intent, targetPageRef, proposalKind, property and notes.
	 * @param string $uid The user confirming it.
	 *
	 * @return array<string, mixed>|null The stored target, or null when it could not be written.
	 *
	 * @throws UnexpectedValueException When the term is empty or a vocabulary value is unknown.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function confirm(array $payload, string $uid): ?array;

	/**
	 * The terms already confirmed, normalised for comparison, so a page can
	 * mark a proposal as already taken.
	 *
	 * @return array<int, string> The lowercase terms.
	 *
	 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function confirmedTerms(): array;
}//end interface
