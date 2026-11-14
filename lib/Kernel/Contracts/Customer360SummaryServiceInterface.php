<?php

/**
 * Contract of Customer360SummaryService
 *
 * The public surface of Customer360SummaryService that other components call, so they depend
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
 * Contract of Customer360SummaryService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface Customer360SummaryServiceInterface {

	/**
	 * Build the consolidated customer 360 summary for one client.
	 *
	 * All reads go through OpenRegister's `ObjectService` (via {@see TicketService}
	 * for tickets, directly for leads), which applies the caller's RBAC —
	 * an object the caller may not read never contributes to any count or total.
	 *
	 * @param string $clientId The client UUID.
	 *
	 * @return array<string, mixed> The summary payload (see class docblock).
	 *
	 * @spec openspec/specs/customer-360/spec.md#requirement-consolidated-customer-360-summary
	 */
	public function getSummary(string $clientId): array;
}//end interface
