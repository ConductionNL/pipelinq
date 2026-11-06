<?php

/**
 * The preferred name on the contact and client schemas.
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
 * @spec openspec/changes/clients-preferred-name/specs/client-preferred-name/spec.md#requirement-a-person-carries-a-preferred-name-req-cpn-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\ContactDataBuilder;
use OCP\App\IAppManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Reads the register as the import composes it (the monolith with every
 * `register.d` fragment merged onto it) and validates what the Contacts sync
 * really writes into `preferredName` against the property as it is declared.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 * @covers \OCA\Pipelinq\Service\ContactDataBuilder
 */
class PreferredNameSchemaTest extends TestCase {
	/**
	 * A schema as the import composes it.
	 *
	 * @param string $key The schema key.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function schema(string $key): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas'][$key] ?? null);
		$this->assertIsArray($schema, "The {$key} schema was not read.");

		return $schema;
	}//end schema()

	/**
	 * Whether a value is valid for the declared property.
	 *
	 * @param array<string, mixed> $property The property as declared.
	 * @param mixed $value The value.
	 *
	 * @return bool True when valid.
	 */
	private function accepts(array $property, mixed $value): bool {
		$keywords = array_intersect_key($property, array_flip(['type', 'maxLength', 'minLength', 'format', 'enum', 'pattern']));
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'properties' => ['preferredName' => $keywords]]));

		return (new Validator())->validate(json_decode((string)json_encode(['preferredName' => $value])), $jsonSchema)->isValid();
	}//end accepts()

	/**
	 * Both schemas declare the field, keep their slug, and do not require it.
	 *
	 * @return void
	 */
	public function testContactAndClientDeclareAnOptionalPreferredName(): void {
		foreach (['contact', 'client'] as $key) {
			$schema = $this->schema(key: $key);

			$this->assertSame($key, $schema['slug'], 'A fragment without a slug is refused by the import.');
			$this->assertArrayHasKey('preferredName', $schema['properties'], "{$key} has no preferredName.");
			$this->assertSame('string', $schema['properties']['preferredName']['type']);
			$this->assertSame('Preferred name', $schema['properties']['preferredName']['title']);
			$this->assertNotContains('preferredName', ($schema['required'] ?? []));
			$this->assertArrayHasKey('name', $schema['properties'], 'The fragment replaced the schema instead of adding to it.');
		}
	}//end testContactAndClientDeclareAnOptionalPreferredName()

	/**
	 * What the Contacts sync writes is what the schema takes: a name, and the
	 * empty text that clears it. A format or a minimum length on the property
	 * would refuse the clearing write, and the sync would fail on every
	 * contact whose nickname was removed.
	 *
	 * @return void
	 */
	public function testTheSchemaTakesWhatTheSyncWrites(): void {
		$builder = new ContactDataBuilder();
		$set = $builder->buildContactImportData(['FN' => 'Johannes Jansen', 'NICKNAME' => 'Jan'], 'uid-1', null);
		$cleared = $builder->buildContactImportData(['FN' => 'Johannes Jansen', 'NICKNAME' => ''], 'uid-1', null);
		$person = $builder->buildClientImportData(['FN' => 'Johanna de Wit', 'NICKNAME' => 'Hanneke'], 'uid-2');

		$contact = $this->schema(key: 'contact')['properties']['preferredName'];
		$client = $this->schema(key: 'client')['properties']['preferredName'];

		$this->assertTrue($this->accepts(property: $contact, value: $set['preferredName']));
		$this->assertTrue($this->accepts(property: $contact, value: $cleared['preferredName']));
		$this->assertTrue($this->accepts(property: $client, value: $person['preferredName']));
		// Control: the judge can refuse, so the passes above mean something.
		$this->assertFalse($this->accepts(property: $contact, value: 7));
		$this->assertFalse($this->accepts(property: $contact, value: str_repeat('a', 256)));
	}//end testTheSchemaTakesWhatTheSyncWrites()
}//end class
