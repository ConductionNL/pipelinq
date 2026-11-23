<?php

/**
 * Contract of RenewalEngineService
 *
 * The public surface of RenewalEngineService that other components call, so they depend
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
 * Contract of RenewalEngineService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RenewalEngineServiceInterface {

	/**
	 * Process a single contract for the nightly job. Pure orchestration over
	 * the injected services; safe to call repeatedly (idempotent).
	 *
	 * @param array<string,mixed> $contract The contract object.
	 * @param string $today ISO date (Y-m-d) of evaluation.
	 *
	 * @return string A short action code: 'expiring', 'churned-silent', 'noticed', or 'noop'.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-window-detection
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
	 */
	public function processContract(array $contract, string $today): string;

	/**
	 * Reconcile a contract against the outcome of its renewal lead.
	 *
	 * Won  -> renewed + drafted successor.
	 * Lost -> churned.
	 *
	 * @param array<string,mixed> $contract The expiring contract (renewalLeadOutcome set).
	 *
	 * @return string 'renewed', 'churned', or 'noop'.
	 *
	 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-renewal-lead-automation
	 */
	public function reconcile(array $contract): string;
}//end interface
