<?php

/**
 * The Zuiddrecht example set brings the five labels board PqBeheerMarketing draws.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Reads the example-set fragment; executes no app code.
 *
 * @coversNothing
 */
class ZuiddrechtBoardLabelsTest extends TestCase {

	/**
	 * The fragment's objects.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 */
	private function objects(): array {
		$path = dirname(__DIR__, 3).'/lib/Settings/example-sets/zuiddrecht.d/84-shared-labels.json';
		$data = json_decode(json: (string) file_get_contents(filename: $path), associative: true);
		self::assertIsArray(actual: $data);

		return $data['objects'];
	}//end objects()

	/**
	 * Exactly the five board labels, as itemLabel objects in the pipelinq register.
	 *
	 * @return void
	 */
	public function testTheFiveBoardLabelsAreItemLabels(): void {
		$names = [];
		foreach ($this->objects() as $object) {
			self::assertSame(expected: 'pipelinq', actual: $object['register']);
			self::assertSame(expected: 'itemLabel', actual: $object['schema']);
			$names[] = $object['data']['name'];
		}

		self::assertSame(
			expected: ['afval', 'duurzaam', 'parkeren', 'ondernemers', 'evenementen'],
			actual: $names
		);
	}//end testTheFiveBoardLabelsAreItemLabels()

	/**
	 * Every object has its own key and a description, and no name repeats in another case.
	 *
	 * @return void
	 */
	public function testKeysAreUniqueAndNamesDifferIgnoringCase(): void {
		$objects = $this->objects();
		$keys = array_column(array: $objects, column_key: 'key');
		self::assertSame(expected: count($objects), actual: count(array_unique(array: $keys)));

		$lower = array_map(callback: static fn (array $object): string => mb_strtolower(string: $object['data']['name']), array: $objects);
		self::assertSame(expected: count($objects), actual: count(array_unique(array: $lower)));

		foreach ($objects as $object) {
			self::assertNotSame(expected: '', actual: trim(string: (string) ($object['data']['description'] ?? '')));
		}
	}//end testKeysAreUniqueAndNamesDifferIgnoringCase()
}//end class
