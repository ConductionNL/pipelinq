<?php

/**
 * Contract of SurveyInvitationSender
 *
 * The public surface of SurveyInvitationSender that other components call, so they depend
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
 * Contract of SurveyInvitationSender.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SurveyInvitationSenderInterface {

	/**
	 * Send one invitation.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param string $address The address to deliver to. Defaults to the one
	 *   the invitation recorded when the dispatch decided to send.
	 *
	 * @return bool True when the hand-off succeeded.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function send(array $invitation, string $address = ''): bool;
}//end interface
