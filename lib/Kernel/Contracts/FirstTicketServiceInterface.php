<?php

/**
 * Contract of FirstTicketService
 *
 * The public surface of FirstTicketService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of FirstTicketService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FirstTicketServiceInterface {

	/**
	 * The first open ticket assigned to the user, or null when there is none.
	 *
	 * @param string $userId The handler.
	 * @param DateTimeImmutable|null $now Now; the current time when null.
	 *
	 * @return array<string, mixed>|null {id, title, reference, ticketType, priority, channel, client, clientName, waitingDays, overdueDays}
	 *
	 * @spec openspec/specs/dashboard/spec.md
	 */
	public function firstFor(string $userId, ?DateTimeImmutable $now = null): ?array;
}//end interface
