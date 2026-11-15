<?php

/**
 * Contract of TicketLeafProvider
 *
 * The public surface of TicketLeafProvider that other components call, so they depend
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

use OCA\Pipelinq\Kernel\Contracts\TicketServiceInterface;

/**
 * Contract of TicketLeafProvider.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TicketLeafProviderInterface {

	/**
	 * The ticket subtypes that count as tickets; an interaction is a contact moment.
	 *
	 * @var array<int, string>
	 */
	public const TICKET_TYPES = [TicketServiceInterface::TYPE_REQUEST, TicketServiceInterface::TYPE_COMPLAINT];

	/**
	 * The clients that map to one master entity: what a host app's link to
	 * that party's ticket list filters on. None when the reference is empty,
	 * unknown, or the ticket surface is not configured.
	 *
	 * @param string $ref The master entity reference.
	 *
	 * @return array<int, string> The client ids.
	 *
	 * @spec openspec/changes/tickets-list-by-party/specs/ticket-leaf/spec.md#requirement-a-host-app-can-link-to-one-partys-ticket-list
	 */
	public function clientIdsOf(string $ref): array;
}//end interface
