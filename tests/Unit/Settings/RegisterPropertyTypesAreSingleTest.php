<?php
/**
 * Every property type in pipelinq's register is a single type name.
 *
 * OpenRegister's schema import (PropertyValidatorHandler) refuses a union type
 * such as ["string", "null"] and skips the whole schema, so its other new
 * properties never reach the instance. An optional property already accepts
 * null at write time, because OpenRegister widens non-required properties
 * (with null in the enum when it has one).
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
 * No union types in the register's schemas.
 */
class RegisterPropertyTypesAreSingleTest extends TestCase {

	/**
	 * Every `type` under a schema's properties, in the main register and every
	 * fragment, is a string.
	 *
	 * @return void
	 */
	public function testNoPropertyDeclaresAUnionType(): void {
		$dir   = __DIR__.'/../../../lib/Settings/';
		$files = array_merge([$dir.'pipelinq_register.json'], (glob($dir.'register.d/*.json') ?: []));
		$found = [];
		foreach ($files as $file) {
			$data    = json_decode((string)file_get_contents($file), true);
			$schemas = ($data['components']['schemas'] ?? []);
			foreach ($schemas as $slug => $schema) {
				$this->collectUnionTypes(node: ($schema['properties'] ?? []), path: basename($file).':'.$slug, found: $found);
			}
		}

		$this->assertSame(expected: [], actual: $found);
	}//end testNoPropertyDeclaresAUnionType()

	/**
	 * Collect the paths of every property, at any depth, whose `type` is an array.
	 *
	 * @param mixed              $node  A properties map.
	 * @param string             $path  Where the map sits.
	 * @param array<int, string> $found The paths found so far.
	 *
	 * @return void
	 */
	private function collectUnionTypes(mixed $node, string $path, array &$found): void {
		if (is_array($node) === false) {
			return;
		}

		foreach ($node as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			if (is_array($property['type'] ?? null) === true) {
				$found[] = $path.'/'.$name;
			}

			$this->collectUnionTypes(node: ($property['properties'] ?? []), path: $path.'/'.$name, found: $found);
			$items = ($property['items'] ?? []);
			if (is_array($items) === true) {
				if (is_array($items['type'] ?? null) === true) {
					$found[] = $path.'/'.$name.'/items';
				}

				$this->collectUnionTypes(node: ($items['properties'] ?? []), path: $path.'/'.$name.'/items', found: $found);
			}
		}
	}//end collectUnionTypes()
}//end class
