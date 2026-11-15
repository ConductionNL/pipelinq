<?php

/**
 * Behavioural tests for the ClientRecordRead trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\RegisterSchema;
use OCA\Pipelinq\Service\Support\ClientRecordRead;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The client location and the caller-scoped find.
 *
 * @covers \OCA\Pipelinq\Service\Support\ClientRecordRead
 */
class ClientRecordReadTest extends TestCase {

	/**
	 * Build a host over config values and a container.
	 *
	 * @param array<string, string> $values    Config values by key.
	 * @param ContainerInterface    $container The container.
	 *
	 * @return object The host.
	 */
	private function host(array $values, ContainerInterface $container): object {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($values[$key] ?? $default)
		);

		return new class($appConfig, $container) {
			use ClientRecordRead;

			/**
			 * @param IAppConfig         $appConfig The app config.
			 * @param ContainerInterface $container The container.
			 */
			public function __construct(private IAppConfig $appConfig, private ContainerInterface $container) {
			}

			/**
			 * @param string $method  The private method.
			 * @param mixed  ...$args The arguments.
			 * @return mixed
			 */
			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testLocationAndFind(): void {
		$store = new class {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/**
			 * @param string $id            Id.
			 * @param string $register      Register.
			 * @param string $schema        Schema.
			 * @param bool   $_rbac         Rbac.
			 * @param bool   $_multitenancy Multitenancy.
			 * @return array<string, string>
			 */
			public function find(string $id, string $register, string $schema, bool $_rbac, bool $_multitenancy): array {
				$this->calls[] = [$id, $register, $schema, $_rbac, $_multitenancy];
				return ['id' => $id];
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($store);

		$this->assertNull($this->host([], $container)->call('clientLocation', 'c1'));
		$host = $this->host(['register' => 'r', 'client_schema' => 's'], $container);
		$this->assertNull($host->call('clientLocation', ''));
		$location = $host->call('clientLocation', 'c1');
		$this->assertEquals(new RegisterSchema(register: 'r', schema: 's'), $location);

		$this->assertSame(['id' => 'c1'], $host->call('findClientAsCaller', 'c1', $location));
		$this->assertSame([['c1', 'r', 's', true, true]], $store->calls);
	}//end testLocationAndFind()
}//end class
