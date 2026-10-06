<?php

/**
 * Unit tests for DemoRegisterImporter.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Pipelinq\Service\Demo\DemoRegisterImporter
 */
class DemoRegisterImporterTest extends TestCase {
	/**
	 * Calls the fake OpenRegister services received, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Stored records the fake object service returns, per schema id.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $stored = [];

	/**
	 * Build the importer over the real descriptor and fake OpenRegister services.
	 *
	 * @return DemoRegisterImporter
	 */
	private function importer(): DemoRegisterImporter {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$appManager->method('getAppVersion')->willReturn('0.5.12');

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key): string => ($key === 'register') ? '7' : ''
		);

		$test = $this;
		$services = [
			'OCA\OpenRegister\Service\ConfigurationService' => new class($test) {
				public function __construct(private DemoRegisterImporterTest $test) {
				}

				public function importFromApp(string $appId, array $data, string $version, bool $force = false): array {
					$this->test->record(['importFromApp', $appId, count($data['components']['objects']), $version, $force]);
					return ['objects' => array_fill(0, 3, 'x'), 'skipped' => ['objects' => 1]];
				}

				public function softDeleteAppImports(string $appId): array {
					$this->test->record(['softDeleteAppImports', $appId]);
					return ['softDeleted' => 2];
				}
			},
			'OCA\OpenRegister\Db\SchemaMapper' => new class {
				public function find(string $slug, bool $_rbac = true, bool $_multitenancy = true): object {
					if ($slug !== 'product') {
						throw new \RuntimeException('not found');
					}

					return new class {
						public function getId(): int {
							return 42;
						}
					};
				}
			},
			'OCA\OpenRegister\Service\ObjectService' => new class($test) {
				public function __construct(private DemoRegisterImporterTest $test) {
				}

				public function findAll(array $config): array {
					return $this->test->storedFor((string)$config['filters']['schema']);
				}

				public function deleteObject(string $uuid, string $register, string $schema): bool {
					$this->test->record(['deleteObject', $uuid, $register, $schema]);
					return true;
				}
			},
		];

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);

		return new DemoRegisterImporter(
			appManager: $appManager,
			appConfig: $appConfig,
			container: $container,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end importer()

	/**
	 * Record a call a fake service received.
	 *
	 * @param array<int, mixed> $call The call.
	 *
	 * @return void
	 */
	public function record(array $call): void {
		$this->calls[] = $call;
	}//end record()

	/**
	 * The stored records of one schema id.
	 *
	 * @param string $schemaId The schema id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function storedFor(string $schemaId): array {
		return ($this->stored[$schemaId] ?? []);
	}//end storedFor()

	/**
	 * The import runs under its own identity, forced, with the whole descriptor.
	 *
	 * @return void
	 */
	public function testImportUsesItsOwnConfigurationIdentity(): void {
		$result = $this->importer()->import();

		$descriptor = json_decode((string)file_get_contents(dirname(__DIR__, 4) . '/lib/Settings/pipelinq_example_register.json'), true);
		$this->assertSame(
			[['importFromApp', 'pipelinq.demo', count($descriptor['components']['objects']), '0.5.12', true]],
			$this->calls
		);
		$this->assertSame(['imported' => 3, 'skipped' => 1], $result);
	}//end testImportUsesItsOwnConfigurationIdentity()

	/**
	 * Removal soft-deletes the recorded imports, then the records an old install
	 * received from the register, but only those whose slug and name still match.
	 *
	 * @return void
	 */
	public function testRemoveDeletesOnlyUnchangedExampleRecords(): void {
		$this->stored['42'] = [
			// An example record exactly as the old register seeded it.
			['id' => 'uuid-cappuccino', 'name' => 'Cappuccino', '@self' => ['slug' => 'product-cappuccino']],
			// The same slug, renamed by its owner: theirs now.
			['id' => 'uuid-renamed', 'name' => 'Our house coffee', '@self' => ['slug' => 'product-tshirt-conduction']],
			// A record of the administrator's own.
			['id' => 'uuid-own', 'name' => 'Consultancy day', '@self' => ['slug' => 'consultancy-day']],
		];

		$result = $this->importer()->remove();

		$this->assertSame(['softDeleteAppImports', 'pipelinq.demo'], $this->calls[0]);
		$this->assertSame([['deleteObject', 'uuid-cappuccino', '7', '42']], array_slice($this->calls, 1));
		$this->assertSame(['removed' => 3, 'retained' => 0], $result);
	}//end testRemoveDeletesOnlyUnchangedExampleRecords()
}//end class
