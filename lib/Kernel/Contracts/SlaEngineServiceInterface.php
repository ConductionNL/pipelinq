<?php

/**
 * Contract of SlaEngineService
 *
 * The public surface of SlaEngineService that other components call, so they depend
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

use DateTimeInterface;

/**
 * Contract of SlaEngineService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SlaEngineServiceInterface {

	/**
	 * Re-evaluate target statuses given a current instant and the policy.
	 *
	 * Updates `consumedPercentage` (using business minutes if the
	 * target's calendar is non-24x7) and flips the status field across
	 * on-track → at-risk (>=80%) → breached (>=100%). Targets already
	 * `met` are left untouched.
	 *
	 * @param array<int, array<string, mixed>> $currentTargets Targets snapshot.
	 * @param array<string, mixed> $policy Resolved policy.
	 * @param DateTimeInterface $now Evaluation instant.
	 *
	 * @return array<int, array<string, mixed>> Updated targets array.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function evaluateTargets(array $currentTargets, array $policy, DateTimeInterface $now): array;

	/**
	 * Execute escalation steps whose thresholds were just crossed.
	 *
	 * Idempotent per `(objectId, escalationLevel)`. Creates an
	 * `slaBreachEvent` audit record and sends a notification on the
	 * configured channel (email / nextcloud-notification / webhook).
	 *
	 * @param array<string, mixed> $policy Policy.
	 * @param string $objectType Tracked object type.
	 * @param string $objectId Tracked object UUID.
	 * @param array<int, array<string, mixed>> $newTargets Targets after re-evaluation.
	 * @param array<int, array<string, mixed>> $priorTargets Targets before evaluation.
	 * @param int $alreadyFired The current escalation level.
	 *
	 * @return array{level:int, eventIds:array<int,string>} The new escalation level + event UUIDs.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $priorTargets is part of the public signature (positional callers exist outside this class)
	 */
	public function executeEscalations(
		array $policy,
		string $objectType,
		string $objectId,
		array $newTargets,
		array $priorTargets,
		int $alreadyFired,
	): array;

	/**
	 * Initialise the slaStatus envelope when a policy resolves on object
	 * creation.
	 *
	 * @param array<string, mixed> $policy Policy.
	 * @param DateTimeInterface $startTime Start instant.
	 *
	 * @return array<string, mixed> The initial slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function initialiseStatus(array $policy, DateTimeInterface $startTime): array;

	/**
	 * Load all active, published policies from the sla register.
	 *
	 * Falls back to an empty list if the sla register is not configured
	 * (e.g. fresh install before the repair step has run).
	 *
	 * @return array<int, array<string, mixed>> Active policies.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function loadActivePolicies(): array;

	/**
	 * Mark an SLA target as met (e.g. on resolution).
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param string $kind Target kind to mark.
	 * @param DateTimeInterface $when Met instant.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function markTargetMet(array $slaStatus, string $kind, DateTimeInterface $when): array;

	/**
	 * Mark the timer as paused.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param DateTimeInterface $now Pause instant.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function pauseTimer(array $slaStatus, DateTimeInterface $now): array;

	/**
	 * Return the identity string for a policy (UUID preferred, else slug).
	 *
	 * @param array<string, mixed> $policy Policy.
	 *
	 * @return string Identity.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function policyIdentity(array $policy): string;

	/**
	 * Resolve the most-specific active policy for an object.
	 *
	 * Matching: appliesTo IN [$objectType, '*']; customerTier IN
	 * [$metadata.tier, '*']; customerScope (contract first, then
	 * organisation) when present. Tie-breaks: lower priority, then
	 * newer validFrom.
	 *
	 * Returns null when nothing matches. Errors are logged and yield
	 * null (fail-safe per REQ-007).
	 *
	 * @param string $objectType Tracked object type.
	 * @param string $objectId Object UUID (currently unused; reserved).
	 * @param array<string, mixed> $metadata Resolution metadata (`tier`, `organisationId`, `contractId`).
	 *
	 * @return ?array<string, mixed> The matched policy array, or null.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function resolvePolicyForObject(string $objectType, string $objectId, array $metadata): ?array;

	/**
	 * Resume the timer and shift target dueAt values by the paused
	 * duration.
	 *
	 * @param array<string, mixed> $slaStatus Current slaStatus.
	 * @param array<string, mixed> $policy Policy (for calendar config).
	 * @param DateTimeInterface $now Resume instant.
	 *
	 * @return array<string, mixed> Updated slaStatus.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function resumeTimer(array $slaStatus, array $policy, DateTimeInterface $now): array;
}//end interface
