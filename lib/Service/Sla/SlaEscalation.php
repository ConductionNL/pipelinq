<?php

/**
 * Pipelinq SlaEscalation.
 *
 * One escalation step that fires for a tracked object.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

/**
 * An escalation step of a policy, fired at a level for one tracked object.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
 */
class SlaEscalation {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $policy Policy.
	 * @param SlaTrackedObject     $object The tracked object.
	 * @param array<string, mixed> $step   Escalation step.
	 * @param int                  $level  1-indexed escalation level.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $policy,
		public readonly SlaTrackedObject $object,
		public readonly array $step,
		public readonly int $level,
	) {
	}//end __construct()

	/**
	 * The channel the step notifies on.
	 *
	 * @return string Channel slug.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
	 */
	public function channel(): string {
		return (string)($this->step['channel'] ?? 'nextcloud-notification');
	}//end channel()

	/**
	 * The actor role the step notifies.
	 *
	 * @return string Actor role.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
	 */
	public function notify(): string {
		return (string)($this->step['notify'] ?? 'assignee');
	}//end notify()

	/**
	 * The message template the step names, trimmed; empty when it names none.
	 *
	 * @return string Template id.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md#requirement-escalation-chain-execution
	 */
	public function templateId(): string {
		return trim((string)($this->step['templateId'] ?? ''));
	}//end templateId()
}//end class
