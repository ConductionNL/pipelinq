<?php

/**
 * Example data for PqBeheerKassa: the Zuiddrecht set sets pipelinq's own
 * shipped tender types, till roles and payment providers instead of adding
 * its own, so each shows once.
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
 * The set's till configuration lives on pipelinq's shipped rows.
 */
class ZuiddrechtPosAdoptsShippedRowsTest extends TestCase {

	/**
	 * The schemas whose shipped rows the set adopts.
	 */
	private const ADOPTED = ['posTenderType', 'posRole', 'paymentProvider'];

	/**
	 * The shipped seed rows with a fixed id, as schema => [id => row].
	 *
	 * @return array<string, array<string, array<string, mixed>>>
	 */
	private function shipped(): array {
		$folder = __DIR__.'/../../../lib/Settings';
		$files  = glob($folder.'/register.d/*.json');
		if ($files === false) {
			$files = [];
		}

		$rows = [];
		foreach ([$folder.'/pipelinq_register.json', ...$files] as $file) {
			$config = json_decode((string)file_get_contents($file), true);
			foreach (($config['components']['objects'] ?? []) as $object) {
				$id = (string)($object['@self']['id'] ?? '');
				if ($id !== '') {
					$rows[$object['@self']['schema']][$id] = $object;
				}
			}
		}

		return $rows;
	}//end shipped()

	/**
	 * The till part of the set.
	 *
	 * @return array<string, mixed>
	 */
	private function part(): array {
		$file = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.d/52-admin-general-and-pos.json';

		return json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
	}//end part()

	/**
	 * Every till change targets one shipped row by its fixed id, and every shipped role and provider is set.
	 *
	 * @return void
	 */
	public function testTheChangesSetTheShippedRows(): void {
		$shipped = $this->shipped();
		$targets = [];
		foreach ($this->part()['changes'] as $change) {
			$this->assertContains(needle: $change['schema'], haystack: self::ADOPTED, message: $change['key']);
			$this->assertSame(expected: ['id'], actual: array_keys($change['where']), message: $change['key']);
			$this->assertArrayHasKey(key: $change['where']['id'], array: $shipped[$change['schema']], message: $change['key']);
			$targets[$change['schema']][] = $change['where']['id'];
		}

		foreach (['posRole', 'paymentProvider'] as $schema) {
			$this->assertEqualsCanonicalizing(expected: array_keys($shipped[$schema]), actual: $targets[$schema], message: $schema);
		}

		$this->assertCount(expectedCount: 3, haystack: $targets['posTenderType']);
	}//end testTheChangesSetTheShippedRows()

	/**
	 * The set adds a till row only where pipelinq ships none of that name, and its staff hold shipped roles.
	 *
	 * @return void
	 */
	public function testTheSetAddsNoSecondCopy(): void {
		$shipped = $this->shipped();
		$part    = $this->part();
		$names   = [];
		foreach ($part['changes'] as $change) {
			$names[$change['schema']][] = (string)($change['set']['name'] ?? '');
		}

		foreach ($part['objects'] as $object) {
			if (in_array(needle: $object['schema'], haystack: self::ADOPTED, strict: true) === true) {
				$this->assertNotContains(needle: $object['data']['name'], haystack: $names[$object['schema']], message: $object['key']);
			}

			if ($object['schema'] === 'posStaff') {
				$this->assertArrayHasKey(key: $object['data']['posRole'], array: $shipped['posRole'], message: $object['key']);
			}
		}

		$tenders = array_column(array_column(array_filter($part['objects'], static fn(array $object): bool => $object['schema'] === 'posTenderType'), 'data'), 'name');
		$this->assertSame(expected: ['Op rekening'], actual: $tenders);
	}//end testTheSetAddsNoSecondCopy()
}//end class
