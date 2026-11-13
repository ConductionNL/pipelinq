<?php

/**
 * The product schema carries the service catalogue fields, and only a published,
 * current service is public.
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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/product-service-catalogue-public/specs/product-service-catalog/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Settings;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * REQ-PSC-001 against the register as the import composes it.
 *
 * The register file with every register.d fragment merged on, read through the
 * loader OpenRegister's import uses.
 *
 * @covers \OCA\Pipelinq\Service\ConfigFileLoaderService
 * @uses   \OCA\Pipelinq\Service\ConfigFragmentValue
 */
class ProductServiceCatalogueSchemaTest extends TestCase {

	/**
	 * The catalogue fields this change adds, with their JSON Schema type.
	 *
	 * @var array<string, string>
	 */
	private const ADDED = [
		'summary'       => 'string',
		'contentBlocks' => 'array',
		'uplReference'  => 'string',
		'publishFrom'   => 'string',
		'publishUntil'  => 'string',
		'applyUrl'      => 'string',
		'channels'      => 'array',
	];

	/**
	 * The product schema as the import composes it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function product(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 3));

		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema   = ($register['components']['schemas']['product'] ?? null);
		$this->assertIsArray($schema, 'The product schema was not read.');

		return $schema;
	}//end product()

	/**
	 * The catalogue fields are declared with their types, and none is required.
	 *
	 * @return void
	 */
	public function testTheCatalogueFieldsAreDeclaredAndOptional(): void {
		$product = $this->product();

		foreach (self::ADDED as $field => $type) {
			$this->assertArrayHasKey($field, $product['properties'], $field);
			$this->assertSame($type, $product['properties'][$field]['type'], $field);
			$this->assertNotContains($field, (array)($product['required'] ?? []), $field);
		}

		$this->assertSame('uri', $product['properties']['uplReference']['format']);
		$this->assertSame('uri', $product['properties']['applyUrl']['format']);
		$this->assertSame('date', $product['properties']['publishFrom']['format']);
		$this->assertSame('date', $product['properties']['publishUntil']['format']);
		$this->assertSame(['online', 'desk', 'phone', 'post'], $product['properties']['channels']['items']['enum']);
		$this->assertSame(['heading', 'body'], array_keys($product['properties']['contentBlocks']['items']['properties']));
	}//end testTheCatalogueFieldsAreDeclaredAndOptional()

	/**
	 * The fields the catalogue already had keep their shape, and a service is a product type.
	 *
	 * @return void
	 */
	public function testTheExistingCatalogueFieldsAndTheServiceTypeStay(): void {
		$product = $this->product();

		$this->assertSame('1.2.0', $product['version']);
		$this->assertContains('service', $product['properties']['type']['enum']);
		$this->assertSame(['label', 'price', 'validFrom', 'note'], array_keys($product['properties']['tariffs']['items']['properties']));
		$this->assertSame(['resident', 'business'], $product['properties']['audience']['items']['enum']);
	}//end testTheExistingCatalogueFieldsAndTheServiceTypeStay()

	/**
	 * The staff keep their reads, and the only public reads are the two
	 * conditional service rules.
	 *
	 * @return void
	 */
	public function testOnlyAnActiveCurrentServiceIsReadableWithoutSigningIn(): void {
		$read = $this->product()['authorization']['read'];

		$this->assertSame(['sales', 'user:pipelinq-system', 'authenticated'], array_values(array_filter($read, 'is_string')));
		$this->assertNotContains('public', $read, 'An unconditional public read would publish every product.');

		$window = ['type' => 'service', 'status' => 'active', 'publishFrom' => ['$lte' => '$now']];
		$this->assertSame(
			[
				['group' => 'public', 'match' => $window + ['publishUntil' => ['$exists' => false]]],
				['group' => 'public', 'match' => $window + ['publishUntil' => ['$gt' => '$now']]],
			],
			array_values(array_filter($read, 'is_array'))
		);

		foreach (['create', 'update', 'delete'] as $verb) {
			foreach ($this->product()['authorization'][$verb] as $rule) {
				$this->assertIsString($rule, $verb);
				$this->assertNotSame('public', $rule, $verb);
			}
		}
	}//end testOnlyAnActiveCurrentServiceIsReadableWithoutSigningIn()
}//end class
