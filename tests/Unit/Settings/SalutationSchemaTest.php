<?php

/**
 * The salutation on the contact and client schemas.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/client-letters/spec.md#requirement-a-person-carries-the-salutation-they-asked-for
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Reads the register as the import composes it and checks the salutation a
 * letter template prints: optional, plain text, with no default that would
 * fill it for someone who never stated one.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class SalutationSchemaTest extends TestCase {
	/**
	 * A schema as the import composes it.
	 *
	 * @param string $key The schema key.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $key): array {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService(appManager: $appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas'][$key] ?? null);
		$this->assertIsArray(actual: $schema, message: "The {$key} schema was not read.");

		return $schema;
	}//end schema()

	/**
	 * Both schemas declare an optional text salutation that nothing fills by default.
	 *
	 * @return void
	 */
	public function testContactAndClientDeclareAnOptionalSalutation(): void {
		foreach (['contact', 'client'] as $key) {
			$schema = $this->schema(key: $key);

			$this->assertSame(expected: $key, actual: $schema['slug'], message: 'A fragment without a slug is refused by the import.');
			$this->assertArrayHasKey(key: 'salutation', array: $schema['properties'], message: "{$key} has no salutation.");
			$property = $schema['properties']['salutation'];
			$this->assertSame(expected: 'string', actual: $property['type']);
			$this->assertSame(expected: 'Salutation', actual: $property['title']);
			$this->assertArrayNotHasKey(key: 'default', array: $property, message: 'A default would greet someone in a way they never asked for.');
			$this->assertArrayNotHasKey(key: 'enum', array: $property, message: 'A salutation is what the person stated, not a pick from a list.');
			$this->assertNotContains(needle: 'salutation', haystack: ($schema['required'] ?? []));
			$this->assertArrayHasKey(key: 'name', array: $schema['properties'], message: 'The fragment replaced the schema instead of adding to it.');
		}
	}//end testContactAndClientDeclareAnOptionalSalutation()
}//end class
