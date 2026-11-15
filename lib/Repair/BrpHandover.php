<?php

/**
 * Pipelinq BrpHandover.
 *
 * One answered handover of the BRP connection by {@see MoveBrpConnectionToIntegriq}.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCA\Pipelinq\Event\BrpConnectionHandoverEvent;

/**
 * The answered handover event, the stored values and why a key could not be handed over.
 *
 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
 */
final class BrpHandover {

	/**
	 * Constructor.
	 *
	 * @param BrpConnectionHandoverEvent $event    The answered handover.
	 * @param array<string, string>      $values   The stored values, by key.
	 * @param array<string, string>      $problems Why a key could not be handed over, by key.
	 *
	 * @spec openspec/changes/brp-monitor-moves-to-integriq/specs/brp-lookup/spec.md#requirement-the-brp-connection-settings-move-to-integriq-once
	 */
	public function __construct(
		public readonly BrpConnectionHandoverEvent $event,
		public readonly array $values,
		public readonly array $problems,
	) {
	}//end __construct()
}//end class
