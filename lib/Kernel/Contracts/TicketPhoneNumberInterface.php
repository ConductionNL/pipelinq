<?php

/**
 * Contract of TicketPhoneNumber
 *
 * The public surface of TicketPhoneNumber that other components call, so they depend
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
 * Contract of TicketPhoneNumber.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TicketPhoneNumberInterface {

	/**
	 * The field the normalised number is stored in.
	 */
	public const FIELD = 'phoneE164';

	/**
	 * The number the ticket is about in E.164; null when it names none or the
	 * number does not normalise.
	 *
	 * @param array<string,mixed> $ticket The ticket fields.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.2
	 */
	public function e164(array $ticket): ?string;
}//end interface
