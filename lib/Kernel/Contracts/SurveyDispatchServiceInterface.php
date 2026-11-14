<?php

/**
 * Contract of SurveyDispatchService
 *
 * The public surface of SurveyDispatchService that other components call, so they depend
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
 * Contract of SurveyDispatchService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SurveyDispatchServiceInterface {

	/**
	 * The rules that match a completed interaction.
	 *
	 * A disabled rule matches nothing, and is inert rather than absent, so an
	 * administrator can turn one off without losing what it said.
	 *
	 * @param string $entityType The completed record's type.
	 * @param string $status The status it reached.
	 * @param string $channel The channel it ran over, where the rule filters on one.
	 *
	 * @return array<int, array<string, mixed>> The matching rules.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	public function matchingRules(string $entityType, string $status, string $channel = ''): array;

	/**
	 * Create the invitations a completed interaction earns.
	 *
	 * Never throws: an interaction's save must not be blocked or rolled back
	 * by anything that happens here.
	 *
	 * @param string $entityType The completed record's type.
	 * @param array<string, mixed> $entity The record.
	 * @param array<string, mixed> $contact The recipient.
	 *
	 * @return array<int, array<string, mixed>> The invitations written.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-automated-invitation-dispatch-on-interaction-completion
	 */
	public function onInteractionCompleted(string $entityType, array $entity, array $contact): array;

	/**
	 * Read invitations as plain arrays.
	 *
	 * @param array<string, mixed> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function read(array $filters): array;

	/**
	 * The response rate of a set of invitations, with the same figures per channel.
	 *
	 * Suppressed and failed invitations are excluded from the DENOMINATOR and
	 * reported as counts of their own: a survey nobody was sent has no
	 * response rate, and hiding that inside the percentage is how a throttle
	 * looks like disinterest.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 *
	 * @return array<string, mixed> delivered, responded, rate, suppressed, failed, byChannel.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function responseRate(array $invitations): array;

	/**
	 * The invitations that fell due in the last `$days` days.
	 *
	 * @param array<int, array<string, mixed>> $invitations The invitations.
	 * @param int                              $days        The period; 0 or less keeps all.
	 * @param DateTimeImmutable|null           $now         The clock, for tests.
	 *
	 * @return array<int, array<string, mixed>> The invitations inside the period.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
	 */
	public function withinDays(array $invitations, int $days, ?DateTimeImmutable $now = null): array;

	/**
	 * Persist one invitation.
	 *
	 * @param array<string, mixed> $invitation The invitation.
	 * @param string|null $uuid Its uuid, for an update.
	 *
	 * @return bool True when the write landed.
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function write(array $invitation, ?string $uuid = null): bool;
}//end interface
