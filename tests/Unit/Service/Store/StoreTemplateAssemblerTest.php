<?php

/**
 * Unit tests for StoreTemplateAssembler: what may be published, and the
 * components a published store item carries.
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

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\RegisterResolverService;
use OCA\Pipelinq\Service\Store\StoreTemplateAssembler;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/specs/pipelinq-store-surface/spec.md
 */
class StoreTemplateAssemblerTest extends TestCase {

	/**
	 * An assembler over the given object service, with every schema configured.
	 *
	 * @param ObjectServiceInterface $objects The object service.
	 *
	 * @return StoreTemplateAssembler
	 */
	private function assembler(ObjectServiceInterface $objects): StoreTemplateAssembler {
		$config = $this->createMock(originalClassName: IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => str_ends_with($key, '_schema') === true ? '51' : $default
		);
		$resolver = $this->createMock(originalClassName: RegisterResolverService::class);
		$resolver->method('resolve')->willReturn('18');

		return new StoreTemplateAssembler(objectService: $objects, appConfig: $config, registerResolver: $resolver);

	}//end assembler()

	/**
	 * The local pipelines are offered for a pipeline template, by title.
	 *
	 * @return void
	 */
	public function testThePipelinesAreOfferedForAPipelineTemplate(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => '18', 'schema' => '51'], 'limit' => 200])
			->willReturn([['id' => 'p-1', 'title' => 'Verkoop'], ['id' => 'p-2', 'name' => 'Woo-vragen']]);

		$offered = $this->assembler(objects: $objects)->publishable(kind: 'pipeline');

		$this->assertSame(
			[
				['schema' => 'pipeline', 'id' => 'p-1', 'title' => 'Verkoop'],
				['schema' => 'pipeline', 'id' => 'p-2', 'title' => 'Woo-vragen'],
			],
			$offered
		);

	}//end testThePipelinesAreOfferedForAPipelineTemplate()

	/**
	 * A kind the store does not know offers nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownKindOffersNothing(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');

		$this->assertSame([], $this->assembler(objects: $objects)->publishable(kind: 'lead'));

	}//end testAnUnknownKindOffersNothing()

	/**
	 * The chosen objects become components without their identity.
	 *
	 * @return void
	 */
	public function testTheChosenObjectsBecomeComponentsWithoutTheirIdentity(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturn(null);
		$objects->method('findAll')->willReturn([
			[
				'id' => 'p-1',
				'uuid' => 'p-1',
				'@self' => ['id' => 'p-1', 'owner' => 'pieter'],
				'organisation' => 'zuiddrecht',
				'owner' => 'pieter',
				'title' => 'Verkoop',
				'stages' => [['name' => 'Nieuw']],
			],
		]);

		$components = $this->assembler(objects: $objects)->components(
			kind: 'pipeline',
			chosen: [['schema' => 'pipeline', 'id' => 'p-1']]
		);

		$this->assertSame([['schema' => 'pipeline', 'object' => ['title' => 'Verkoop', 'stages' => [['name' => 'Nieuw']]]]], $components);

	}//end testTheChosenObjectsBecomeComponentsWithoutTheirIdentity()

	/**
	 * A chosen object of a schema the kind does not publish is refused.
	 *
	 * @return void
	 */
	public function testAnObjectOutsideTheKindIsRefused(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);

		$this->expectException(\InvalidArgumentException::class);
		$this->assembler(objects: $objects)->components(kind: 'pipeline', chosen: [['schema' => 'lead', 'id' => 'l-1']]);

	}//end testAnObjectOutsideTheKindIsRefused()

	/**
	 * A chosen object that is not on this instance is refused.
	 *
	 * @return void
	 */
	public function testAMissingObjectIsRefused(): void {
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([]);

		$this->expectException(\InvalidArgumentException::class);
		$this->assembler(objects: $objects)->components(kind: 'pipeline', chosen: [['schema' => 'pipeline', 'id' => 'gone']]);

	}//end testAMissingObjectIsRefused()
}//end class
