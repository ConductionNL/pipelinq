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
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));
		$this->config = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
	}//end setUp()

	/**
	 * The register carries a label schema with a required name.
	 *
	 * @return void
	 */
	public function testTheRegisterCarriesTheLabelSchema(): void {
		$this->assertContains('label', $this->config['components']['registers']['pipelinq']['schemas']);

		$label = $this->config['components']['schemas']['label'];
		$this->assertSame('label', $label['slug']);
		$this->assertSame(['name'], $label['required']);
		$this->assertSame('string', $label['properties']['name']['type']);
		$this->assertSame('string', $label['properties']['description']['type']);
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

		$this->assertSame(['sales', 'user:pipelinq-system'], $authorization['read']);
		$this->assertSame(['sales', 'user:pipelinq-system'], $authorization['export']);
		foreach (['create', 'update', 'delete'] as $verb) {
			$this->assertSame([], $authorization[$verb], 'label.'.$verb);
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
			$this->assertIsArray($property, "{$schema}.{$field} must be declared or storage drops it");
			$this->assertSame('array', $property['type'], "{$schema}.{$field}");
			$this->assertSame('string', $property['items']['type'], "{$schema}.{$field}");
			$this->assertSame(
				[
					'register'      => 'pipelinq',
					'schema'        => 'label',
					'valueField'    => 'name',
					'labelField'    => 'name',
					'hideWhenEmpty' => true,
				],
				$property['items']['x-options-from'],
				"{$schema}.{$field} offers the label list and hides when it is empty"
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
		$this->assertTrue($validator->validate(json_decode('{"labels":["ondernemers","duurzaam"]}'), $jsonSchema)->isValid());
		$this->assertTrue($validator->validate(json_decode('{}'), $jsonSchema)->isValid());
		$this->assertFalse($validator->validate(json_decode('{"labels":"ondernemers"}'), $jsonSchema)->isValid());
	}//end testALabelledLeadValidates()
}//end class
