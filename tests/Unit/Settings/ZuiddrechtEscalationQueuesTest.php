<?php

/**
 * The Zuiddrecht set seeds the board's escalation queues.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\Cti\EscalationQueues;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Board PqGesprekAfronden offers "Vergunningen, tweede lijn"; every seeded
 * queue routes to a group the set creates.
 *
 * @spec openspec/changes/cti-escalation-queues/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 */
class ZuiddrechtEscalationQueuesTest extends TestCase {
	/**
	 * The board's queue is seeded and each queue's group exists in the set.
	 *
	 * @return void
	 */
	public function testTheBoardsQueuesAreSeededWithTheirGroups(): void {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.d/70-escalation-queues.json'),
			true
		);
		$value = '';
		foreach ($fragment['appConfig'] as $entry) {
			if ($entry['key'] === EscalationQueues::CONFIG_KEY) {
				$value = $entry['value'];
			}
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($value);
		$queues = (new EscalationQueues(appConfig: $config))->all();

		self::assertContains('Vergunningen, tweede lijn', array_column($queues, 'label'));
		$groups = array_column($fragment['groups'], 'group');
		foreach ($queues as $queue) {
			self::assertContains($queue['groupId'], $groups);
		}
	}//end testTheBoardsQueuesAreSeededWithTheirGroups()
}//end class
