<?php

/**
 * Unit tests for the attached files in the full export archive.
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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use ZipArchive;

/**
 * The files attached to objects travel in the archive under their object identifier.
 *
 * @covers \OCA\Pipelinq\Service\Export\FullExportService
 * @uses   \OCA\Pipelinq\Service\Export\ExportDataService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class FullExportAttachedFilesTest extends TestCase {

	/**
	 * OpenRegister's published object file reader, as the service looks it up.
	 *
	 * @var string
	 */
	private const READER = 'OCA\\OpenRegister\\Contract\\ObjectFileReaderInterface';

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
	 * A file node with a name and content.
	 *
	 * @param string $name    The file name.
	 * @param string $content The content.
	 *
	 * @return File The node.
	 */
	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn($content);
		return $file;
	}//end file()

	/**
	 * A reader that answers files per object uuid and records what it was asked.
	 *
	 * @param array<string, list<File>|\Throwable> $byObject The files per object uuid, or the error to throw.
	 *
	 * @return object The reader.
	 */
	private function reader(array $byObject): object {
		return new class ($byObject) {
			/**
			 * The uuids asked for, in order.
			 *
			 * @var list<string>
			 */
			public array $asked = [];

			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $byObject The files per object uuid.
			 */
			public function __construct(private array $byObject) {
			}

			/**
			 * The files of an object.
			 *
			 * @param string $objectUuid The object's uuid.
			 *
			 * @return list<File> The files.
			 */
			public function getObjectFiles(string $objectUuid): array {
				$this->asked[] = $objectUuid;
				$answer        = ($this->byObject[$objectUuid] ?? []);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}
		};
	}//end reader()

	/**
	 * The service over two schemas, with the container answering the reader (or not).
	 *
	 * @param array<string, list<array<string, mixed>>> $objectsBySchema The objects per schema id.
	 * @param object|null                               $reader          The reader, null when OpenRegister does not publish it.
	 *
	 * @return FullExportService The service.
	 */
	private function service(array $objectsBySchema, ?object $reader): FullExportService {
		$config    = ['register' => 'reg-1', 'client_schema' => 'sch-client', 'lead_schema' => 'sch-lead'];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$loader = $this->createMock(ConfigFileLoaderService::class);
		$loader->method('loadConfigurationFile')->willReturn(['components' => ['schemas' => ['client' => [], 'lead' => []]]]);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config): array => ($objectsBySchema[$config['filters']['schema']] ?? [])
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturnCallback(static fn (string $id): bool => $id === self::READER && $reader !== null);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($reader): object {
				if ($id !== self::READER || $reader === null) {
					throw new RuntimeException('not in the container: ' . $id);
				}

				return $reader;
			}
		);

		return new FullExportService(
			configFiles: $loader,
			formatter: new ExportDataService(container: $this->createMock(ContainerInterface::class), appConfig: $appConfig, objectService: $objects),
			objectService: $objects,
			appConfig: $appConfig,
			rootFolder: $this->createMock(IRootFolder::class),
			timeFactory: $time,
			container: $container,
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
	 * The names in an archive, sorted.
	 *
	 * @param string $path The archive.
	 *
	 * @return list<string> The names.
	 */
	private function namesIn(string $path): array {
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path));
		$names = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$names[] = (string) $zip->getNameIndex($i);
		}

		$zip->close();
		sort($names);
		return $names;
	}//end namesIn()

	/**
	 * Every object's files are in the archive under its identifier, and the manifest counts them.
	 *
	 * @return void
	 */
	public function testAttachedFilesArePresentUnderTheirObjectIdentifier(): void {
		$reader = $this->reader(
			[
				'client-1' => [$this->file(name: 'contract.pdf', content: '%PDF-1'), $this->file(name: 'logo.png', content: 'PNG')],
				'lead-7'   => [$this->file(name: 'offer.docx', content: 'DOCX')],
			]
		);
		$service = $this->service(
			objectsBySchema: [
				'sch-client' => [['@self' => ['id' => 'client-1'], 'name' => 'Acme'], ['@self' => ['id' => 'client-2'], 'name' => 'Beta']],
				'sch-lead'   => [['id' => 'lead-7', 'title' => 'Big deal']],
			],
			reader: $reader
		);
		$path = $this->archivePath();

		$manifest = $service->writeArchive(path: $path, exportedAt: '2026-11-14T10:00:00+00:00');

		$this->assertSame(['client-1', 'client-2', 'lead-7'], $reader->asked);
		$this->assertTrue($manifest['attachedFilesIncluded']);
		$this->assertSame(
			[['client', 2, 2], ['lead', 1, 1]],
			array_map(static fn (array $e): array => [$e['schema'], $e['objects'], $e['attachedFiles']], $manifest['schemas'])
		);
		$this->assertSame(
			[
				'data/client.csv', 'data/client.jsonl', 'data/lead.csv', 'data/lead.jsonl',
				'files/client-1/contract.pdf', 'files/client-1/logo.png', 'files/lead-7/offer.docx',
				'manifest.json', 'schemas/client.schema.json', 'schemas/lead.schema.json',
			],
			$this->namesIn(path: $path)
		);

		$zip = new ZipArchive();
		$zip->open($path);
		$this->assertSame('%PDF-1', $zip->getFromName('files/client-1/contract.pdf'));
		$this->assertSame('DOCX', $zip->getFromName('files/lead-7/offer.docx'));
		$this->assertSame($manifest, json_decode((string) $zip->getFromName('manifest.json'), true));
		$zip->close();
	}//end testAttachedFilesArePresentUnderTheirObjectIdentifier()

	/**
	 * Without OpenRegister's reader the rest is exported and the manifest says the files are not included.
	 *
	 * @return void
	 */
	public function testWithoutTheReaderTheManifestSaysFilesAreNotIncluded(): void {
		$service = $this->service(objectsBySchema: ['sch-client' => [['id' => 'client-1']]], reader: null);
		$path    = $this->archivePath();

		$manifest = $service->writeArchive(path: $path, exportedAt: 'now');

		$this->assertFalse($manifest['attachedFilesIncluded']);
		$this->assertSame([0, 0], array_column($manifest['schemas'], 'attachedFiles'));
		$this->assertSame([], preg_grep('/^files\//', $this->namesIn(path: $path)));
	}//end testWithoutTheReaderTheManifestSaysFilesAreNotIncluded()

	/**
	 * A failing file read fails the export rather than shipping an archive missing files.
	 *
	 * @return void
	 */
	public function testAFailingFileReadFailsTheExport(): void {
		$service = $this->service(
			objectsBySchema: ['sch-client' => [['id' => 'client-1']]],
			reader: $this->reader(['client-1' => new RuntimeException('storage offline')])
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('The attached files of client object client-1 could not be read: storage offline');

		$service->writeArchive(path: $this->archivePath(), exportedAt: 'now');
	}//end testAFailingFileReadFailsTheExport()

	/**
	 * An identifier or file name that would escape its directory is not written.
	 *
	 * @return void
	 */
	public function testIdentifiersAndNamesCannotEscapeTheirDirectory(): void {
		$reader = $this->reader(
			[
				'client-1' => [$this->file(name: '../../etc/passwd', content: 'x'), $this->file(name: '..', content: 'y')],
				'../up'    => [$this->file(name: 'a.txt', content: 'z')],
			]
		);
		$service = $this->service(
			objectsBySchema: ['sch-client' => [['id' => 'client-1'], ['id' => '../up'], ['name' => 'no id']]],
			reader: $reader
		);
		$path = $this->archivePath();

		$manifest = $service->writeArchive(path: $path, exportedAt: 'now');

		$this->assertSame(['client-1'], $reader->asked);
		$this->assertSame(['files/client-1/passwd'], array_values(preg_grep('/^files\//', $this->namesIn(path: $path))));
		$this->assertSame(1, $manifest['schemas'][0]['attachedFiles']);
	}//end testIdentifiersAndNamesCannotEscapeTheirDirectory()
}//end class
