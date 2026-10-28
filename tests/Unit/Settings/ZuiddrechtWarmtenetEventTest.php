<?php

/**
 * Example data for PqEvenement: Informatieavond warmtenet Oost, its named
 * registrations on the Lindelaan and its history.
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
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The event is dated from the day it is viewed, as the board's 7 October is.
 */
class ZuiddrechtWarmtenetEventTest extends TestCase {

	/**
	 * The fragment with its parts' lists appended, as the loader reads it.
	 *
	 * @return array<string, mixed> The fragment.
	 */
	private function fragment(): array {
		$folder   = __DIR__.'/../../../lib/Settings/example-sets';
		$fragment = json_decode((string)file_get_contents($folder.'/zuiddrecht.json'), true);
		$parts    = glob($folder.'/zuiddrecht.d/*.json');
		if ($parts === false) {
			$parts = [];
		}

		sort($parts);
		foreach ($parts as $file) {
			foreach (json_decode((string)file_get_contents($file), true) as $key => $value) {
				if (is_array($value) === true && array_is_list($value) === true) {
					$fragment[$key] = array_merge(($fragment[$key] ?? []), $value);
				}
			}
		}

		return $fragment;
	}//end fragment()

	/**
	 * Thirty-six days out (the board's 12 November from 7 October), 19:30 to 21:30 at
	 * Wijkcentrum De Linde for 80 people, with five named registrations on the Lindelaan.
	 *
	 * @return void
	 */
	public function testTheEventAndItsNamedRegistrations(): void {
		$objects = array_column($this->fragment()['objects'], null, 'key');
		$event   = $objects['evenement-warmtenet-oost']['data'];

		$this->assertSame(expected: '{{datetime:36 19:30}}', actual: $event['startAt']);
		$this->assertSame(expected: '{{datetime:36 21:30}}', actual: $event['endAt']);
		$this->assertSame(expected: 'Wijkcentrum De Linde, Lindelaan 2', actual: $event['venue']);
		$this->assertSame(expected: 80, actual: $event['capacity']);

		$named = [];
		foreach ($objects as $object) {
			if ($object['schema'] === 'eventRegistration' && $object['data']['event'] === '{{object:evenement-warmtenet-oost.id}}') {
				$named[$object['data']['name']] = $object['data']['status'];
			}
		}

		$this->assertSame(
			expected: [
				'Sanne de Vries'   => 'confirmed',
				'Fam. Kuiper'      => 'confirmed',
				'Ahmed El Idrissi' => 'confirmed',
				'VvE Lindehof'     => 'confirmed',
				'Joke van Dam'     => 'waitlist',
			],
			actual: $named
		);
	}//end testTheEventAndItsNamedRegistrations()

	/**
	 * Pieter made the event, and Lotte raised the places from 60 to 80.
	 *
	 * @return void
	 */
	public function testTheEventHasItsHistory(): void {
		$history = [];
		foreach ($this->fragment()['auditEntries'] as $entry) {
			if ($entry['data']['object'] === '{{object:evenement-warmtenet-oost.id}}') {
				$history[] = [$entry['data']['action'], $entry['data']['user'], ($entry['data']['changed'] ?? null)];
			}
		}

		$this->assertSame(
			expected: [
				['create', '{{cast:pieter.uid}}', null],
				['update', '{{cast:lotte.uid}}', ['capacity' => ['old' => 60, 'new' => 80]]],
			],
			actual: $history
		);
	}//end testTheEventHasItsHistory()
}//end class
