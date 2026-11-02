<?php

/**
 * Pipelinq SlaSweptObject.
 *
 * The data and UUID of one object an SLA sweep reads.
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
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Sla;

/**
 * An OpenRegister row as the sweep reads it: its data payload and its UUID.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
class SlaSweptObject {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Object data.
	 * @param string               $uuid Object UUID, empty when unresolved.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $data,
		public readonly string $uuid,
	) {
	}//end __construct()

	/**
	 * The slaStatus envelope when it names a policy and is not paused.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function activeSlaStatus(): ?array {
		$slaStatus = $this->data['slaStatus'] ?? null;
		if (is_array($slaStatus) === false || ($slaStatus['policyId'] ?? '') === '') {
			return null;
		}

		if (($slaStatus['pausedAt'] ?? null) !== null) {
			return null;
		}

		return $slaStatus;
	}//end activeSlaStatus()

	/**
	 * The same object with a new slaStatus envelope.
	 *
	 * @param array<string, mixed> $slaStatus The new envelope.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function withSlaStatus(array $slaStatus): self {
		$data              = $this->data;
		$data['slaStatus'] = $slaStatus;
		return new self(data: $data, uuid: $this->uuid);
	}//end withSlaStatus()
}//end class
