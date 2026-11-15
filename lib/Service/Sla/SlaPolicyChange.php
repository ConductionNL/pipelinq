<?php

/**
 * Pipelinq SlaPolicyChange.
 *
 * One create or update of an SLA policy: the submitted payload, where it is
 * written, and the id of the policy it replaces.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Sla
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The payload, register, policy schema and (for an update) id of a policy write.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
final class SlaPolicyChange {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $payload  The submitted policy payload.
	 * @param string               $register The SLA register.
	 * @param string               $schema   The SLA policy schema.
	 * @param string|null          $id       The policy id for an update, null for a create.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly string $register,
		public readonly string $schema,
		public readonly ?string $id = null,
	) {
	}//end __construct()

	/**
	 * The change justification the payload carries.
	 *
	 * @return string The justification, or an empty string.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function justification(): string {
		return (string)($this->payload['justification'] ?? '');
	}//end justification()

	/**
	 * The audited operation: `update` for a write under an id, else `create`.
	 *
	 * @return string The action.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function action(): string {
		if ($this->id === null) {
			return 'create';
		}

		return 'update';
	}//end action()

	/**
	 * The audit log context of the change: action, actor, justification, before/after and timestamp.
	 *
	 * @param string $actorId The acting user id, or `system`.
	 * @param mixed  $before  The previous policy data, or null.
	 *
	 * @return array<string, mixed> The context.
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function auditContext(string $actorId, mixed $before): array {
		return [
			'action' => $this->action(),
			'actor' => $actorId,
			'justification' => $this->justification(),
			'before' => $before,
			'after' => $this->payload,
			'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
	}//end auditContext()
}//end class
