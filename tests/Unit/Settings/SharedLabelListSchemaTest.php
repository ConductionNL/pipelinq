<?php

/**
 * The shared label list: the label schema, labels on lead and client, and the pickers.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-labels-are-one-shared-list-for-articles-leads-and-customers
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Reads the register as the import composes it, with every fragment merged.
 *
 * @coversNothing
 */
class SharedLabelListSchemaTest extends TestCase {

	/**
	 * The merged register configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config = [];

	/**
	 * Merge the shipped register the way the import does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$this->config = (new ConfigFileLoaderService(appManager: $appManager))->loadConfigurationFile();
	}//end setUp()

	/**
	 * The register carries a label schema with a required name.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesTheLabelSchema(): void {
		$this->assertContains(needle: 'label', haystack: $this->config['components']['registers']['pipelinq']['schemas']);

		$label = $this->config['components']['schemas']['label'];
		$this->assertSame(expected: 'label', actual: $label['slug']);
		$this->assertSame(expected: ['name'], actual: $label['required']);
		$this->assertSame(expected: 'string', actual: $label['properties']['name']['type']);
		$this->assertSame(expected: 'string', actual: $label['properties']['description']['type']);
	}//end testTheRegisterCarriesTheLabelSchema()

	/**
	 * The staff read the list; only an administrator writes it.
	 *
	 * OpenRegister lets the admin group through before any rule, so an empty
	 * write list leaves the writes to administrators.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/shared-label-list/specs/shared-labels/spec.md#requirement-an-administrator-manages-the-labels-on-beheer-marketing
	 */
	public function testOnlyAnAdministratorWritesTheList(): void {
		$authorization = $this->config['components']['schemas']['label']['authorization'];

		$this->assertSame(expected: ['sales', 'user:pipelinq-system'], actual: $authorization['read']);
		$this->assertSame(expected: ['sales', 'user:pipelinq-system'], actual: $authorization['export']);
		foreach (['create', 'update', 'delete'] as $verb) {
			$this->assertSame(expected: [], actual: $authorization[$verb], message: 'label.'.$verb);
		}
	}//end testOnlyAnAdministratorWritesTheList()

	/**
	 * Leads, clients and articles pick their labels from the list.
	 *
	 * @return void
	 */
	public function testLeadsClientsAndArticlesPickFromTheList(): void {
		$schemas = $this->config['components']['schemas'];
		$fields = ['lead' => 'labels', 'client' => 'labels', 'article' => 'tags'];

		foreach ($fields as $schema => $field) {
			$property = $schemas[$schema]['properties'][$field] ?? null;
			$this->assertIsArray(actual: $property, message: "{$schema}.{$field} must be declared or storage drops it");
			$this->assertSame(expected: 'array', actual: $property['type'], message: "{$schema}.{$field}");
			$this->assertSame(expected: 'string', actual: $property['items']['type'], message: "{$schema}.{$field}");
			$this->assertSame(
				expected: [
					'register'      => 'pipelinq',
					'schema'        => 'label',
					'valueField'    => 'name',
					'labelField'    => 'name',
					'hideWhenEmpty' => true,
				],
				actual: $property['items']['x-options-from'],
				message: "{$schema}.{$field} offers the label list and hides when it is empty"
			);
		}
	}//end testLeadsClientsAndArticlesPickFromTheList()

	/**
	 * A lead with two labels validates, and a lead without labels still does.
	 *
	 * @return void
	 */
	public function testALabelledLeadValidates(): void {
		$property = $this->config['components']['schemas']['lead']['properties']['labels'];
		$keywords = array_intersect_key($property, array_flip(['type', 'items']));
		$keywords['items'] = array_intersect_key($keywords['items'], array_flip(['type', 'maxLength']));
		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'properties' => ['labels' => $keywords]]));

		$validator = new Validator();
		$this->assertTrue(condition: $validator->validate(json_decode('{"labels":["ondernemers","duurzaam"]}'), $jsonSchema)->isValid());
		$this->assertTrue(condition: $validator->validate(json_decode('{}'), $jsonSchema)->isValid());
		$this->assertFalse(condition: $validator->validate(json_decode('{"labels":"ondernemers"}'), $jsonSchema)->isValid());
	}//end testALabelledLeadValidates()
}//end class
