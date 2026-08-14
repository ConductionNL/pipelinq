<?php

/**
 * Unit tests for ContactImportService's fail-closed handling of an
 * unconfigured register / client_schema / contact_schema.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ContactDataBuilder;
use OCA\Pipelinq\Service\ContactImportService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * These imports WROTE to OpenRegister with an unchecked register and schema.
 *
 * An empty id is not the same as passing none: ObjectService skips
 * setRegister()/setSchema() for an empty value, so the imported contact would
 * be persisted into whatever register/schema context an earlier call in the
 * same request left on the shared instance. Every test therefore asserts that
 * saveObject() is never reached, not merely that an exception surfaced.
 */
class ContactImportServiceGuardTest extends TestCase {
	/**
	 * Build the service over a config map.
	 *
	 * @param array<string, string> $config The app-config contents.
	 * @param ObjectService $object The OpenRegister ObjectService mock.
	 *
	 * @return ContactImportService
	 */
	private function buildService(array $config, ObjectService $object): ContactImportService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($object);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				return ($config[$key] ?? $default);
			}
		);

		$builder = $this->createMock(ContactDataBuilder::class);
		$builder->method('buildClientImportData')->willReturn(['name' => 'Acme']);
		$builder->method('buildContactImportData')->willReturn(['name' => 'Alice']);

		return new ContactImportService($appConfig, $container, $builder);
	}//end buildService()

	/**
	 * importAsClient refuses when `client_schema` is unset.
	 *
	 * @return void
	 */
	public function testImportAsClientRefusesWithoutClientSchema(): void {
		$object = $this->createMock(ObjectService::class);
		$object->expects($this->never())->method('saveObject');

		$service = $this->buildService(['register' => 'reg-1'], $object);

		$this->expectException(RuntimeException::class);
		$service->importAsClient(['FN' => 'Acme'], 'uid-1');
	}//end testImportAsClientRefusesWithoutClientSchema()

	/**
	 * importAsContact refuses when `contact_schema` is unset.
	 *
	 * @return void
	 */
	public function testImportAsContactRefusesWithoutContactSchema(): void {
		$object = $this->createMock(ObjectService::class);
		$object->expects($this->never())->method('saveObject');

		$service = $this->buildService(['register' => 'reg-1'], $object);

		$this->expectException(RuntimeException::class);
		$service->importAsContact(['FN' => 'Alice'], 'uid-2', null);
	}//end testImportAsContactRefusesWithoutContactSchema()

	/**
	 * A configured schema but an unset register still refuses — the register
	 * is read inside saveAndSerialize(), after the schema check passes.
	 *
	 * @return void
	 */
	public function testImportRefusesWithoutRegisterEvenWhenSchemaIsSet(): void {
		$object = $this->createMock(ObjectService::class);
		$object->expects($this->never())->method('saveObject');

		$service = $this->buildService(['client_schema' => 'sch-client'], $object);

		$this->expectException(RuntimeException::class);
		$service->importAsClient(['FN' => 'Acme'], 'uid-3');
	}//end testImportRefusesWithoutRegisterEvenWhenSchemaIsSet()

	/**
	 * A fully configured instance does write — otherwise the negative
	 * assertions above would pass for the wrong reason.
	 *
	 * @return void
	 */
	public function testConfiguredImportReachesOpenRegister(): void {
		$object = $this->createMock(ObjectService::class);
		$object->expects($this->once())->method('saveObject')->willReturn(['id' => 'obj-1']);

		$service = $this->buildService(
			[
				'register' => 'reg-1',
				'client_schema' => 'sch-client',
			],
			$object
		);

		$this->assertSame(['id' => 'obj-1'], $service->importAsClient(['FN' => 'Acme'], 'uid-4'));
	}//end testConfiguredImportReachesOpenRegister()
}//end class
