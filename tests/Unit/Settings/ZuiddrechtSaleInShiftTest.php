<?php

/**
 * Example data for PqKassabon: POS-2026-1043 belongs to the Balie 2 shift that was open when it was sold.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The sale names its shift.
 */
class ZuiddrechtSaleInShiftTest extends TestCase {

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
	 * A change after the objects links POS-2026-1043 to SHIFT-2026-0214, the Balie 2 shift of the same day.
	 *
	 * @return void
	 */
	public function testTheSaleNamesItsShift(): void {
		$fragment = $this->fragment();
		$changes  = array_column($fragment['changes'], null, 'key');
		$change   = $changes['kassabon-1043-in-dienst'];

		$this->assertSame(expected: ['reference' => 'POS-2026-1043'], actual: $change['where']);
		$this->assertSame(expected: ['shift' => '{{object:kassadienst-7okt-balie2.id}}'], actual: $change['set']);

		$objects = array_column($fragment['objects'], null, 'key');
		$this->assertSame(expected: 'Balie 2, stadskantoor', actual: $objects['kassadienst-7okt-balie2']['data']['drawer']);
		$this->assertSame(expected: 'Balie 2', actual: $objects['kassabon-1043']['data']['terminalId']);
	}//end testTheSaleNamesItsShift()
}//end class
