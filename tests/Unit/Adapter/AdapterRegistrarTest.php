<?php

/**
 * Unit tests for AdapterRegistrar.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Adapter
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Adapter;

use OCA\Pipelinq\Adapter\AdapterRegistrar;
use OCA\Pipelinq\Adapter\AzureDataLakeExportAdapter;
use OCA\Pipelinq\Adapter\BigQueryExportAdapter;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Adapter\GcsExportAdapter;
use OCA\Pipelinq\Adapter\PostgresExportAdapter;
use OCA\Pipelinq\Adapter\S3ExportAdapter;
use OCA\Pipelinq\Adapter\SftpExportAdapter;
use OCA\Pipelinq\Adapter\SnowflakeExportAdapter;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The registrar binds ExportSinkRegistry to a factory that fetches every export
 * adapter from the container and registers each under its own type.
 *
 * @covers \OCA\Pipelinq\Adapter\AdapterRegistrar
 * @uses   \OCA\Pipelinq\Adapter\ExportSinkRegistry
 */
class AdapterRegistrarTest extends TestCase {

	/**
	 * The adapter classes the registry is built from, with the type each double reports.
	 *
	 * @var array<class-string, string>
	 */
	private const ADAPTERS = [
		S3ExportAdapter::class => 's3',
		BigQueryExportAdapter::class => 'bigquery',
		SnowflakeExportAdapter::class => 'snowflake',
		PostgresExportAdapter::class => 'postgres',
		AzureDataLakeExportAdapter::class => 'azure-data-lake',
		GcsExportAdapter::class => 'gcs',
		SftpExportAdapter::class => 'sftp',
	];

	/**
	 * Register through a context double and return the one factory it was given.
	 *
	 * @return callable The factory registered for ExportSinkRegistry.
	 */
	private function registeredFactory(): callable {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())
			->method('registerService')
			->willReturnCallback(
				static function (string $name, callable $factory) use (&$registered): void {
					$registered[$name] = $factory;
				}
			);

		(new AdapterRegistrar())->register(context: $context);

		$this->assertSame(expected: [ExportSinkRegistry::class], actual: array_keys($registered));
		return $registered[ExportSinkRegistry::class];
	}//end registeredFactory()

	/**
	 * The factory fetches every adapter from the container, in order, and the
	 * registry it builds answers each one under its type.
	 *
	 * @return void
	 */
	public function testTheFactoryRegistersEveryAdapterUnderItsType(): void {
		$doubles = [];
		foreach (self::ADAPTERS as $class => $type) {
			$double = $this->createMock($class);
			$double->method('getType')->willReturn($type);
			$doubles[$class] = $double;
		}

		$asked = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($doubles, &$asked): object {
				$asked[] = $id;
				return $doubles[$id];
			}
		);

		$registry = ($this->registeredFactory())($container);

		$this->assertInstanceOf(expected: ExportSinkRegistry::class, actual: $registry);
		$this->assertSame(expected: array_keys(self::ADAPTERS), actual: $asked);
		foreach (self::ADAPTERS as $class => $type) {
			$this->assertTrue(condition: $registry->supports(type: $type), message: $type);
			$this->assertSame(expected: $doubles[$class], actual: $registry->get(type: $type), message: $type);
		}

		$this->assertFalse(condition: $registry->supports(type: 'ftp'));
	}//end testTheFactoryRegistersEveryAdapterUnderItsType()
}//end class
