<?php

/**
 * Integration test asserting every schema default is a member of its own enum.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec exclude mechanical schema-integrity guard, not a product behaviour
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * A property that declares both `enum` and `default` must declare a default the
 * enum admits.
 *
 * This is a silent-failure class: nothing errors at write time, so an object
 * created without an explicit value simply carries a value the schema rejects,
 * and every consumer filtering on the declared enum skips it. It was found on
 * `task.priority` (default `normaal`, enum `low|normal|high`) and the same
 * Dutch-default-against-Englishified-enum slip existed twice more in the
 * loyalty-programme fragment — a typo class worth catching mechanically rather
 * than one property at a time.
 */
class SchemaDefaultsWithinEnumTest extends TestCase {

	/**
	 * Every enum-carrying property in every register fragment declares a legal default.
	 *
	 * @return void
	 */
	public function testEveryDefaultIsAMemberOfItsEnum(): void {
		$violations = [];

		foreach ($this->registerFiles() as $file) {
			$decoded = json_decode(file_get_contents($file), true);
			if (is_array($decoded) === false) {
				continue;
			}

			$schemas = ($decoded['components']['schemas'] ?? []);
			if (is_array($schemas) === false) {
				continue;
			}

			foreach ($schemas as $schemaName => $schema) {
				$properties = ($schema['properties'] ?? []);
				if (is_array($properties) === false) {
					continue;
				}

				foreach ($properties as $propertyName => $property) {
					if (is_array($property) === false) {
						continue;
					}

					$enum = ($property['enum'] ?? null);
					if (is_array($enum) === false || array_key_exists('default', $property) === false) {
						continue;
					}

					$default = $property['default'];
					if (is_scalar($default) === false || in_array($default, $enum, true) === true) {
						continue;
					}

					$violations[] = sprintf(
						'%s: %s.%s default %s not in [%s]',
						basename($file),
						$schemaName,
						$propertyName,
						var_export($default, true),
						implode('|', array_map('strval', $enum))
					);
				}//end foreach
			}//end foreach
		}//end foreach

		self::assertSame([], $violations, "Schema defaults outside their enum:\n" . implode("\n", $violations));

	}//end testEveryDefaultIsAMemberOfItsEnum()

	/**
	 * Collect the register configuration files this app ships.
	 *
	 * @return array<int, string> Absolute paths to register JSON files.
	 */
	private function registerFiles(): array {
		$settings = dirname(__DIR__, 2) . '/lib/Settings';

		$files = array_merge(
			(glob($settings . '/*register*.json') ?: []),
			(glob($settings . '/register.d/*.json') ?: [])
		);

		self::assertNotEmpty($files, 'Expected at least one register configuration file to scan.');

		return $files;

	}//end registerFiles()

}//end class
