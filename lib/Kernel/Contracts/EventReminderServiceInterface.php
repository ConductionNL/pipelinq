<?php

/**
 * Contract of EventReminderService
 *
 * The public surface of EventReminderService that other components call, so they depend
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
 * Contract of EventReminderService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EventReminderServiceInterface {

	/**
	 * Send the reminder to every confirmed registration with an e-mail address.
	 *
	 * @param string $eventId The event.
	 *
	 * @return array{sent: int, skipped: int} How many mails went out, and how many registrations had no usable address.
	 *
	 * @throws RuntimeException `event_not_found` when the caller may not read it, `event_not_saved` when the caller may not change it.
	 *
	 * @spec openspec/specs/campaign-events/spec.md
	 */
	public function send(string $eventId): array;
}//end interface
