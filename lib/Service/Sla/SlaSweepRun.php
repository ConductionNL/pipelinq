<?php

/**
 * Pipelinq SlaSweepRun.
 *
 * What one SLA sweep run reads from: register, policies, clock and store.
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

use DateTimeInterface;

/**
 * The register, the active policies by identity, the instant and the
 * OpenRegister ObjectService of one sweep run.
 *
 * @spec openspec/specs/sla-engine-and-escalation/spec.md
 */
class SlaSweepRun {
	/**
	 * Constructor.
	 *
	 * @param string                              $register      Register UUID.
	 * @param array<string, array<string, mixed>> $policies      Active policies by identity.
	 * @param DateTimeInterface                   $now           The instant of the run.
	 * @param object                              $objectService OpenRegister ObjectService.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $register,
		public readonly array $policies,
		public readonly DateTimeInterface $now,
		public readonly object $objectService,
	) {
	}//end __construct()

	/**
	 * The policy an slaStatus envelope points at, or null when it is not active.
	 *
	 * @param array<string, mixed> $slaStatus The slaStatus envelope.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/sla-engine-and-escalation/spec.md
	 */
	public function policyFor(array $slaStatus): ?array {
		return $this->policies[(string) $slaStatus['policyId']] ?? null;
	}//end policyFor()
}//end class
