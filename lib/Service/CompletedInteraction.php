<?php

/**
 * Pipelinq CompletedInteraction.
 *
 * The type, status and channel of an interaction that completed.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * A completed interaction as a survey dispatch rule trigger sees it.
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
 */
final class CompletedInteraction {
	/**
	 * Constructor.
	 *
	 * @param string $entityType The completed record's type.
	 * @param string $status     The status it reached.
	 * @param string $channel    The channel it ran over, or ''.
	 */
	public function __construct(
		public readonly string $entityType,
		public readonly string $status,
		public readonly string $channel,
	) {
	}//end __construct()

	/**
	 * Whether a rule trigger matches this entity type, status and channel.
	 *
	 * An empty channel filter matches every channel.
	 *
	 * @param array<string, mixed> $trigger The rule trigger.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-configurable-survey-dispatch-rules
	 */
	public function matches(array $trigger): bool {
		if (trim((string)($trigger['entityType'] ?? '')) !== $this->entityType) {
			return false;
		}

		if (trim((string)($trigger['statusEquals'] ?? '')) !== $this->status) {
			return false;
		}

		$channelFilter = trim((string)($trigger['channelEquals'] ?? ''));
		if ($channelFilter !== '' && $channelFilter !== $this->channel) {
			return false;
		}

		return true;
	}//end matches()
}//end class
