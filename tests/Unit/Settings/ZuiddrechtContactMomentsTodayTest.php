<?php

/**
 * Example data for PqContactmomenten "Vandaag": the board lists this
 * morning's contact moments newest first. The callback to the Kuiper family,
 * the chat about bulky waste and Henk Visser's letter happen today at the
 * times the board draws, beside De Graaf's call and the passport at the desk.
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
 * Today's contact moments.
 */
class ZuiddrechtContactMomentsTodayTest extends TestCase {

	/**
	 * The contact moments PqContactmomenten draws under "Vandaag" happen today, at the board's times.
	 *
	 * @return void
	 */
	public function testTheBoardsMorningContactMomentsHappenToday(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$expected = [
			'interaction-degraaf-afvalpas'          => '{{datetime:0 09:12}}',
			'interaction-dewit-paspoort'            => '{{datetime:0 08:51}}',
			'interaction-kuiper-parkeervergunning'  => '{{datetime:0 08:40}}',
			'interaction-hoekstra-grofvuil'         => '{{datetime:0 08:05}}',
			'interaction-henkvisser-boom-beukenlaan' => '{{datetime:0 07:40}}',
		];
		foreach ($expected as $key => $occurredAt) {
			$this->assertSame(expected: $occurredAt, actual: $objects[$key]['occurredAt'], message: $key);
		}
	}//end testTheBoardsMorningContactMomentsHappenToday()

	/**
	 * The callback task for the Kuiper family says the call was this morning, not yesterday.
	 *
	 * @return void
	 */
	public function testTheKuiperCallbackTaskSaysTheyCalledThisMorning(): void {
		$set     = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$objects = array_column($set['objects'], 'data', 'key');

		$this->assertStringStartsWith(
			prefix: 'Belde vanochtend',
			string: $objects['taak-kuiper-parkeervergunning']['contactMomentSummary']
		);
	}//end testTheKuiperCallbackTaskSaysTheyCalledThisMorning()
}//end class
