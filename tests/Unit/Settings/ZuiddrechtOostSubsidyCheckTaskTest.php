<?php
/**
 * Example data for PqOrganisatie: Buurtvereniging Oost's Activiteit card lists
 * Fatma Yildiz's task to check the subsidy documents, dated 6 okt, below the
 * linked mail of 16:05 that day.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The organisation's subsidy check task.
 */
class ZuiddrechtOostSubsidyCheckTaskTest extends TestCase {

	/**
	 * One finished follow-up task on Buurtvereniging Oost for Fatma, due the day
	 * before the install, before the linked mail's 16:05.
	 *
	 * @return void
	 */
	public function testFatmasCheckTaskIsOnBuurtverenigingOost(): void {
		$file = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/82-oost-subsidy-check-task.json';
		$part = json_decode((string)file_get_contents($file), true);
		$this->assertIsArray(actual: $part, message: 'the fragment is there and is JSON');
		$objects = array_column(($part['objects'] ?? []), null, 'key');
		$task    = ($objects['taak-buurtoost-subsidiedocumenten-controleren'] ?? null);
		$this->assertNotNull(actual: $task);
		$this->assertSame(expected: 'crmTask', actual: $task['schema']);
		$data = $task['data'];
		$this->assertSame(expected: 'Controleer de subsidiedocumenten als ze binnenkomen.', actual: $data['subject']);
		$this->assertSame(expected: '{{object:client-buurtoost.id}}', actual: $data['clientId']);
		$this->assertSame(expected: '{{cast:fatmayildiz.uid}}', actual: $data['assigneeUserId']);
		$this->assertSame(expected: 'completed', actual: $data['status']);
		$this->assertSame(expected: '{{datetime:-1 12:00}}', actual: $data['deadline']);
	}//end testFatmasCheckTaskIsOnBuurtverenigingOost()

	/**
	 * The mail linked the same day stays the newest row: the task's deadline,
	 * which the feed dates it by, is earlier than 16:05.
	 *
	 * @return void
	 */
	public function testTheTaskSortsBelowTheLinkedMail(): void {
		$dir  = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/';
		$mail = json_decode((string)file_get_contents($dir.'79-oost-linked-mail.json'), true)['emailLinks'][0]['data'];
		$task = json_decode((string)file_get_contents($dir.'82-oost-subsidy-check-task.json'), true)['objects'][0]['data'];
		$this->assertSame(expected: '{{datetime:-1 16:05}}', actual: $mail['mailDate']);
		$this->assertLessThan(expected: '16:05', actual: substr(string: $task['deadline'], offset: -7, length: 5));
	}//end testTheTaskSortsBelowTheLinkedMail()
}//end class
