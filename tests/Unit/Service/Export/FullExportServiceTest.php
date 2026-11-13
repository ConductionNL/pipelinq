<?php

/**
 * Unit tests for FullExportService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use DateTime;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\Export\ExportDataService;
use OCA\Pipelinq\Service\Export\FullExportService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use ZipArchive;

/**
 * The archive every schema leaves in, and where it is stored.
 *
 * @covers \OCA\Pipelinq\Service\Export\FullExportService
 * @uses   \OCA\Pipelinq\Service\Export\ExportDataService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Service\ConfigFileLoaderService
 */
class FullExportServiceTest extends TestCase {

	/**
	 * Archive paths to remove after each test.
	 *
	 * @var list<string>
	 */
	private array $paths = [];

	/**
	 * Remove the archives a test wrote.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->paths as $path) {
			@unlink($path);
		}
	}//end tearDown()

	/**
	 * A register loader over a fixed definition.
	 *
	 * @param array<string, mixed> $schemas The schema definitions.
	 *
	 * @return ConfigFileLoaderService The loader.
	 */
	private function loader(array $schemas): ConfigFileLoaderService {
		$loader = $this->createMock(ConfigFileLoaderService::class);
		$loader->method('loadConfigurationFile')->willReturn(['components' => ['schemas' => $schemas]]);
		return $loader;
	}//end loader()

	/**
	 * The service.
	 *
	 * @param ConfigFileLoaderService $loader  The register loader.
	 * @param ObjectServiceInterface  $objects The object service.
	 * @param array<string, string>   $config  The app config values.
	 * @param IRootFolder|null        $root    Nextcloud Files.
	 *
	 * @return FullExportService The service.
	 */
	private function service(ConfigFileLoaderService $loader, ObjectServiceInterface $objects, array $config = [], ?IRootFolder $root = null): FullExportService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T10:20:30+00:00'));
		$formatter = new ExportDataService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $appConfig,
			objectService: $objects,
		);

		return new FullExportService(
			configFiles: $loader,
			formatter: $formatter,
			objectService: $objects,
			appConfig: $appConfig,
			rootFolder: ($root ?? $this->createMock(IRootFolder::class)),
			timeFactory: $time,
		);
	}//end service()

	/**
	 * A temporary archive path.
	 *
	 * @return string The path.
	 */
	private function archivePath(): string {
		$path          = (string) tempnam(sys_get_temp_dir(), 'pq-test-');
		$this->paths[] = $path;
		return $path;
	}//end archivePath()

	/**
	 * The exportable schemas are exactly the ones the register declares, fragments included (REQ-DPE-001).
	 *
	 * @return void
	 */
	public function testEveryDeclaredSchemaIsExportable(): void {
		$appPath  = dirname(__DIR__, 4);
		$expected = [];
		$files    = array_merge([$appPath . '/lib/Settings/pipelinq_register.json'], glob($appPath . '/lib/Settings/register.d/*.json'));
		foreach ($files as $file) {
			$data     = json_decode((string) file_get_contents($file), true);
			$expected = array_merge($expected, array_keys((array) ($data['components']['schemas'] ?? [])));
		}

		$expected = array_values(array_unique(array_map('strval', $expected)));
		sort($expected);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->willReturn($appPath);
		$slugs = $this->service(loader: new ConfigFileLoaderService($apps), objects: $this->createMock(ObjectServiceInterface::class))->schemaSlugs();

		$this->assertNotEmpty($slugs);
		$this->assertContains('lead', $slugs);
		$this->assertSame($expected, $slugs);
	}//end testEveryDeclaredSchemaIsExportable()

	/**
	 * Every schema is written as JSON Lines and CSV with its definition, all objects read past per-user rules.
	 *
	 * @return void
	 */
	public function testTheArchiveHoldsEverySchemaInBothFormatsWithAManifest(): void {
		$leads   = [];
		for ($i = 1; $i <= 501; $i++) {
			$leads[] = ['id' => 'lead-' . $i, 'title' => 'Lead ' . $i];
		}

		$objects = $this->createMock(ObjectServiceInterface::class);
		$calls   = [];
		$objects->method('findAll')->willReturnCallback(
			static function (array $config, bool $_rbac, bool $_multitenancy) use ($leads, &$calls): array {
				$calls[] = [$config['filters']['schema'], $config['offset'], $_rbac, $_multitenancy];
				if ($config['filters']['schema'] !== 'sch-lead') {
					return [['id' => 'client-1', 'name' => 'Acme', 'tags' => ['a']]];
				}

				return array_slice($leads, $config['offset'], $config['limit']);
			}
		);
		$service = $this->service(
			loader: $this->loader(['lead' => ['title' => 'Lead', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]], 'client' => [], 'product' => []]),
			objects: $objects,
			config: ['register' => 'reg-1', 'lead_schema' => 'sch-lead', 'client_schema' => 'sch-client']
		);
		$path = $this->archivePath();

		$manifest = $service->writeArchive(path: $path, exportedAt: '2026-11-13T10:20:30+00:00');

		$this->assertSame(
			[['sch-client', 0, false, false], ['sch-lead', 0, false, false], ['sch-lead', 500, false, false]],
			$calls
		);
		$this->assertSame('pipelinq', $manifest['app']);
		$this->assertSame('2026-11-13T10:20:30+00:00', $manifest['exportedAt']);
		$this->assertSame(
			[['client', true, 1], ['lead', true, 501], ['product', false, 0]],
			array_map(static fn (array $e): array => [$e['schema'], $e['configured'], $e['objects']], $manifest['schemas'])
		);

		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path));
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = (string) $zip->getNameIndex($i);
		}

		sort($names);
		$this->assertSame(
			[
				'data/client.csv', 'data/client.jsonl', 'data/lead.csv', 'data/lead.jsonl', 'data/product.csv', 'data/product.jsonl',
				'manifest.json', 'schemas/client.schema.json', 'schemas/lead.schema.json', 'schemas/product.schema.json',
			],
			$names
		);
		$this->assertSame('{"id":"client-1","name":"Acme","tags":["a"]}' . "\n", $zip->getFromName('data/client.jsonl'));
		$this->assertCount(502, explode("\r\n", rtrim((string) $zip->getFromName('data/lead.csv'))));
		$this->assertSame('', $zip->getFromName('data/product.jsonl'));
		$this->assertSame(
			[
				'$schema' => 'https://json-schema.org/draft/2020-12/schema',
				'$id' => 'lead',
				'title' => 'Lead',
				'type' => 'object',
				'required' => ['title'],
				'properties' => ['title' => ['type' => 'string']],
			],
			json_decode((string) $zip->getFromName('schemas/lead.schema.json'), true)
		);
		$this->assertSame($manifest, json_decode((string) $zip->getFromName('manifest.json'), true));
		$zip->close();
	}//end testTheArchiveHoldsEverySchemaInBothFormatsWithAManifest()

	/**
	 * A failing read fails the export rather than shipping a schema cut short.
	 *
	 * @return void
	 */
	public function testAFailingReadFailsTheExport(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willThrowException(new RuntimeException('database gone'));
		$service = $this->service(loader: $this->loader(['lead' => []]), objects: $objects, config: ['register' => 'reg-1', 'lead_schema' => 'sch-lead']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The objects of schema lead could not be read: database gone');

		$service->writeArchive(path: $this->archivePath(), exportedAt: 'now');
	}//end testAFailingReadFailsTheExport()

	/**
	 * The archive is stored in the administrator's Files, named by the export time.
	 *
	 * @return void
	 */
	public function testTheArchiveIsStoredInTheAdministratorsFiles(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/admin/files/pipelinq-export-2026-11-13-102030.zip');
		$file->method('getId')->willReturn(42);
		$stored = '';
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('newFile')->willReturnCallback(
			static function (string $name, $content) use ($file, &$stored): File {
				$stored = $name . ':' . substr((string) $content, 0, 2);
				return $file;
			}
		);
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->once())->method('getUserFolder')->with('admin')->willReturn($folder);
		$service = $this->service(loader: $this->loader([]), objects: $this->createMock(ObjectServiceInterface::class), root: $root);

		$result = $service->exportFor(userId: 'admin');

		$this->assertSame('pipelinq-export-2026-11-13-102030.zip:PK', $stored);
		$this->assertSame('pipelinq-export-2026-11-13-102030.zip', $result['name']);
		$this->assertSame('/admin/files/pipelinq-export-2026-11-13-102030.zip', $result['path']);
		$this->assertSame(42, $result['fileId']);
		$this->assertSame([], $result['manifest']['schemas']);
	}//end testTheArchiveIsStoredInTheAdministratorsFiles()

	/**
	 * A register without a schema list has nothing to export.
	 *
	 * @return void
	 */
	public function testNoSchemaListIsNothingToExport(): void {
		$loader = $this->createMock(ConfigFileLoaderService::class);
		$loader->method('loadConfigurationFile')->willReturn(['components' => ['schemas' => 'broken']]);

		$this->assertSame([], $this->service(loader: $loader, objects: $this->createMock(ObjectServiceInterface::class))->schemaSlugs());
	}//end testNoSchemaListIsNothingToExport()
}//end class
