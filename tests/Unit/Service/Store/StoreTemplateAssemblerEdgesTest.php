<?php

/**
 * Edge cases of StoreTemplateAssembler: kinds with several schemas, the
 * per-schema config key, refusal messages and identity stripping.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Store
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.conduction.nl
 *
 * @spec openspec/specs/pipelinq-store-surface/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Store;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCA\Pipelinq\Service\Store\StoreTemplateAssembler;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/pipelinq-store-surface/spec.md
 */
#[CoversClass(StoreTemplateAssembler::class)]
class StoreTemplateAssemblerEdgesTest extends TestCase {

	/**
	 * An assembler where `<slug>_schema` keys hold the given schema ids, in register 18.
	 *
	 * @param ObjectServiceInterface $objects   The object service.
	 * @param array<string, string>  $schemaIds Schema id per app/config key.
	 *
	 * @return StoreTemplateAssembler
	 */
	private function assembler(ObjectServiceInterface $objects, array $schemaIds): StoreTemplateAssembler {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => ($schemaIds[$app.'/'.$key] ?? $default)
		);
		$resolver = $this->createMock(originalClassName: RegisterResolverService::class);
		$resolver->method('resolve')->willReturn('18');

		return new StoreTemplateAssembler(objectService: $objects, appConfig: $config, registerResolver: $resolver);
	}//end assembler()

	/**
	 * A catalogue template offers products and categories, each looked up in its own schema.
	 *
	 * @return void
	 */
	public function testACatalogueOffersEachOfItsSchemas(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->exactly(count: 2))->method('findAll')->willReturnCallback(
			static fn (array $config): array => match ($config['filters']['schema']) {
				'60' => [['id' => 'pr-1', 'title' => 'Fiets']],
				'61' => [['id' => 'pc-1', 'name' => 'Vervoer']],
			}
		);

		$offered = $this->assembler(objects: $objects, schemaIds: ['pipelinq/product_schema' => '60', 'pipelinq/productCategory_schema' => '61'])
			->publishable(kind: 'catalogue');

		$this->assertSame(
			expected: [
				['schema' => 'product', 'id' => 'pr-1', 'title' => 'Fiets'],
				['schema' => 'productCategory', 'id' => 'pc-1', 'title' => 'Vervoer'],
			],
			actual: $offered
		);
	}//end testACatalogueOffersEachOfItsSchemas()

	/**
	 * A schema of the kind that is not configured is skipped; the others are still offered.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaIsSkipped(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => '18', 'schema' => '61'], 'limit' => 200])
			->willReturn([['id' => 'pc-1', 'title' => 'Vervoer']]);

		$offered = $this->assembler(objects: $objects, schemaIds: ['pipelinq/productCategory_schema' => '61'])
			->publishable(kind: 'catalogue');

		$this->assertSame(expected: [['schema' => 'productCategory', 'id' => 'pc-1', 'title' => 'Vervoer']], actual: $offered);
	}//end testAnUnconfiguredSchemaIsSkipped()

	/**
	 * A title wins over a name, and an `@self` id is used when there is no top-level id.
	 *
	 * @return void
	 */
	public function testTheTitleAndIdFallBacks(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([
			['id' => 's-1', 'title' => 'Titel', 'name' => 'Naam'],
			['@self' => ['id' => 's-2'], 'name' => 'Alleen naam'],
			['id' => 7],
		]);

		$this->assertSame(
			expected: [
				['schema' => 'skill', 'id' => 's-1', 'title' => 'Titel'],
				['schema' => 'skill', 'id' => 's-2', 'title' => 'Alleen naam'],
				['schema' => 'skill', 'id' => '7', 'title' => '7'],
			],
			actual: $this->assembler(objects: $objects, schemaIds: ['pipelinq/skill_schema' => '55'])->publishable(kind: 'queue-routing')
		);
	}//end testTheTitleAndIdFallBacks()

	/**
	 * An object of a schema outside the kind is refused by name, before anything is looked up.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheKindAndTheSchema(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'A pipeline template cannot carry a product.');
		$this->assembler(objects: $objects, schemaIds: ['pipelinq/product_schema' => '60'])
			->components(kind: 'pipeline', chosen: [['schema' => 'product', 'id' => 'pr-1']]);
	}//end testTheRefusalNamesTheKindAndTheSchema()

	/**
	 * An unknown kind carries nothing, not even a schema it would otherwise allow.
	 *
	 * @return void
	 */
	public function testAnUnknownKindCarriesNothing(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'A lead template cannot carry a pipeline.');
		$this->assembler(objects: $objects, schemaIds: ['pipelinq/pipeline_schema' => '51'])
			->components(kind: 'lead', chosen: [['schema' => 'pipeline', 'id' => 'p-1']]);
	}//end testAnUnknownKindCarriesNothing()

	/**
	 * A chosen entry without a schema is refused as an empty schema.
	 *
	 * @return void
	 */
	public function testAnEntryWithoutASchemaIsRefused(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'A pipeline template cannot carry a .');
		$this->assembler(objects: $objects, schemaIds: ['pipelinq/pipeline_schema' => '51'])
			->components(kind: 'pipeline', chosen: [['id' => 'p-1']]);
	}//end testAnEntryWithoutASchemaIsRefused()

	/**
	 * A missing object is refused with the schema named, also when its schema is unconfigured.
	 *
	 * @return void
	 */
	public function testAnObjectOfAnUnconfiguredSchemaIsMissing(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'The chosen pipeline is not on this instance.');
		$this->assembler(objects: $objects, schemaIds: [])
			->components(kind: 'pipeline', chosen: [['schema' => 'pipeline', 'id' => 'p-1']]);
	}//end testAnObjectOfAnUnconfiguredSchemaIsMissing()

	/**
	 * Components keep the chosen order, match on an `@self` id, and lose every identity key.
	 *
	 * @return void
	 */
	public function testComponentsKeepOrderAndLoseEveryIdentityKey(): void {
		$identity = [
			'uuid' => 'u',
			'organisation' => 'zuiddrecht',
			'owner' => 'pieter',
			'created' => '2026-01-01',
			'updated' => '2026-02-01',
			'register' => '18',
			'schema' => '60',
		];
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => match ($config['filters']['schema']) {
				'60' => [['id' => 'pr-1', 'title' => 'Fiets', 'price' => 10] + $identity],
				'61' => [['@self' => ['id' => 'pc-1'], 'name' => 'Vervoer'] + $identity],
			}
		);

		$components = $this->assembler(objects: $objects, schemaIds: ['pipelinq/product_schema' => '60', 'pipelinq/productCategory_schema' => '61'])
			->components(
				kind: 'catalogue',
				chosen: [['schema' => 'productCategory', 'id' => 'pc-1'], ['schema' => 'product', 'id' => 'pr-1']]
			);

		$this->assertSame(
			expected: [
				['schema' => 'productCategory', 'object' => ['name' => 'Vervoer']],
				['schema' => 'product', 'object' => ['title' => 'Fiets', 'price' => 10]],
			],
			actual: $components
		);
	}//end testComponentsKeepOrderAndLoseEveryIdentityKey()

	/**
	 * Nothing chosen gives no components and no lookups.
	 *
	 * @return void
	 */
	public function testNothingChosenGivesNoComponents(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');

		$this->assertSame(expected: [], actual: $this->assembler(objects: $objects, schemaIds: [])->components(kind: 'pipeline', chosen: []));
	}//end testNothingChosenGivesNoComponents()
}//end class
