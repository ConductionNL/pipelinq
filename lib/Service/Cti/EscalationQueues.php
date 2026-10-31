<?php

/**
 * Pipelinq EscalationQueues.
 *
 * The queues an escalated call can be handed to.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * The escalation queues, from app config `cti_escalation_queues`: a JSON list
 * of `{ id, label, groupId }`. A queue routes to a Nextcloud group.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 */
class EscalationQueues {

	/**
	 * The app config key that holds the queues.
	 */
	public const CONFIG_KEY = 'cti_escalation_queues';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config.
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Every configured queue; an entry without an id, label or group is skipped.
	 *
	 * @return array<int, array{id: string, label: string, groupId: string}> The queues.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	public function all(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$queues = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$queue = [
				'id'      => trim((string)($entry['id'] ?? '')),
				'label'   => trim((string)($entry['label'] ?? '')),
				'groupId' => trim((string)($entry['groupId'] ?? '')),
			];
			if (in_array('', $queue, true) === false) {
				$queues[] = $queue;
			}
		}

		return $queues;
	}//end all()

	/**
	 * The queue with the given id, or null.
	 *
	 * @param string $id The queue id.
	 *
	 * @return array{id: string, label: string, groupId: string}|null The queue.
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
	 */
	public function find(string $id): ?array {
		foreach ($this->all() as $queue) {
			if ($queue['id'] === $id) {
				return $queue;
			}
		}

		return null;
	}//end find()
}//end class
