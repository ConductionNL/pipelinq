<?php
/**
 * UplListImporter: download the UPL list and keep the uplProduct lookup in step with it.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Upl
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Upl;

use JsonSerializable;
use OCA\Pipelinq\Service\Upl\UplListImporter;
use OCA\Pipelinq\Service\Upl\UplListParser;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Creates what is new, updates what changed, leaves the rest, and refuses when it cannot read what is stored.
 *
 * @covers \OCA\Pipelinq\Service\Upl\UplListImporter
 * @uses   \OCA\Pipelinq\Service\Upl\UplListParser
 */
class UplListImporterTest extends TestCase {

	private const CSV = "UniformeProductnaam,URI,Burger,Bedrijf\n"
		. "paspoort,http://standaarden.overheid.nl/owms/terms/paspoort,X,\n"
		. "bijstand,http://standaarden.overheid.nl/owms/terms/bijstand,X,\n"
		. "parkeervergunning,http://standaarden.overheid.nl/owms/terms/parkeervergunning,X,X\n"
		. "kapvergunning,http://standaarden.overheid.nl/owms/terms/kapvergunning,X,X\n";

	/**
	 * The saves the fake object service received.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $saved = [];

	/**
	 * The importer over a fake object service holding the given entries.
	 *
	 * @param array<int, mixed>  $stored    What findAll() returns.
	 * @param array<string, string> $config    App config values.
	 * @param bool               $readFails Whether findAll() throws.
	 * @param string|null        $failUri   A URI whose save throws.
	 *
	 * @return UplListImporter
	 */
	private function importer(array $stored = [], array $config = [], bool $readFails = false, ?string $failUri = null): UplListImporter {
		$objectService = new class ($this, $stored, $readFails, $failUri) {
			public function __construct(
				private UplListImporterTest $test,
				private array $stored,
				private bool $readFails,
				private ?string $failUri,
			) {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				if ($this->readFails === true) {
					throw new RuntimeException('database gone');
				}

				return $this->stored;
			}

			public function saveObject(array $object, mixed $register = null, mixed $schema = null, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				if ($object['uri'] === $this->failUri) {
					throw new RuntimeException('validation failed');
				}

				$this->test->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac];
				return $object;
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		return new UplListImporter(
			new UplListParser(),
			$this->createMock(IClientService::class),
			$this->appConfig(config: $config),
			$container,
			$this->createMock(LoggerInterface::class)
		);
	}//end importer()

	/**
	 * App config answering from a map, with each caller's default otherwise.
	 *
	 * @param array<string, string> $config The values.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(array $config): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * An entity as OpenRegister returns it.
	 *
	 * @param array<string, mixed> $data Its serialised form.
	 *
	 * @return JsonSerializable
	 */
	private static function entity(array $data): JsonSerializable {
		return new class ($data) implements JsonSerializable {
			public function __construct(private array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * A first import creates every entry, without RBAC, in the configured register and schema.
	 *
	 * @return void
	 */
	public function testAFirstImportCreatesEveryEntry(): void {
		$result = $this->importer(config: ['register' => '7', 'uplProduct_schema' => '41'])->import(csv: self::CSV);

		$this->assertSame(['read' => 4, 'created' => 4, 'updated' => 0, 'unchanged' => 0, 'failed' => 0], $result);
		$this->assertSame(
			['object' => ['name' => 'paspoort', 'uri' => 'http://standaarden.overheid.nl/owms/terms/paspoort', 'audience' => ['resident']], 'register' => '7', 'schema' => '41', 'uuid' => null, 'rbac' => false],
			$this->saved[0]
		);
	}//end testAFirstImportCreatesEveryEntry()

	/**
	 * A re-import writes only what changed, onto the stored object, and keeps entries no longer listed.
	 *
	 * @return void
	 */
	public function testAReImportWritesOnlyWhatChanged(): void {
		$stored = [
			self::entity(['id' => 'u-1', 'name' => 'paspoort', 'uri' => 'http://standaarden.overheid.nl/owms/terms/paspoort', 'audience' => ['resident']]),
			['@self' => ['id' => 'u-2'], 'name' => 'bijstandsuitkering', 'uri' => 'http://standaarden.overheid.nl/owms/terms/bijstand', 'audience' => ['resident']],
			['uuid' => 'u-3', 'name' => 'parkeervergunning', 'uri' => 'http://standaarden.overheid.nl/owms/terms/parkeervergunning', 'audience' => ['resident']],
			['id' => 'u-9', 'name' => 'vervallen', 'uri' => 'http://standaarden.overheid.nl/owms/terms/vervallen'],
			['name' => 'zonder uri'],
			'not an entry',
		];

		$result = $this->importer(stored: $stored)->import(csv: self::CSV);

		$this->assertSame(['read' => 4, 'created' => 1, 'updated' => 2, 'unchanged' => 1, 'failed' => 0], $result);
		$this->assertSame(['u-2', 'u-3', null], array_column($this->saved, 'uuid'));
		$this->assertSame('bijstand', $this->saved[0]['object']['name']);
		$this->assertSame(['resident', 'business'], $this->saved[1]['object']['audience']);
		$this->assertSame(['pipelinq', 'uplProduct'], [$this->saved[2]['register'], $this->saved[2]['schema']]);
	}//end testAReImportWritesOnlyWhatChanged()

	/**
	 * A save that fails is counted and the rest still runs.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsCountedAndTheRunContinues(): void {
		$result = $this->importer(failUri: 'http://standaarden.overheid.nl/owms/terms/bijstand')->import(csv: self::CSV);

		$this->assertSame(['read' => 4, 'created' => 3, 'updated' => 0, 'unchanged' => 0, 'failed' => 1], $result);
	}//end testAFailedSaveIsCountedAndTheRunContinues()

	/**
	 * When the stored entries cannot be read, nothing is written, so nothing is duplicated.
	 *
	 * @return void
	 */
	public function testAnUnreadableLookupWritesNothing(): void {
		try {
			$this->importer(readFails: true)->import(csv: self::CSV);
			$this->fail('Expected the import to be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame('The stored UPL entries could not be read: database gone', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end testAnUnreadableLookupWritesNothing()

	/**
	 * Without OpenRegister the import is refused with the reason.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheImportIsRefused(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));
		$importer = new UplListImporter(new UplListParser(), $this->createMock(IClientService::class), $this->appConfig([]), $container, $this->createMock(LoggerInterface::class));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available: not installed');

		$importer->import(csv: self::CSV);
	}//end testWithoutOpenRegisterTheImportIsRefused()

	/**
	 * The source is the national list unless the app setting names another.
	 *
	 * @return void
	 */
	public function testTheSourceUrlDefaultsToTheNationalList(): void {
		$this->assertSame(UplListImporter::DEFAULT_URL, $this->importer()->sourceUrl());
		$this->assertSame('https://mirror.example.org/upl.csv', $this->importer(config: ['upl_list_url' => ' https://mirror.example.org/upl.csv '])->sourceUrl());
	}//end testTheSourceUrlDefaultsToTheNationalList()

	/**
	 * A download returns the body; a failed or empty one is refused with the URL.
	 *
	 * @return void
	 */
	public function testADownloadReturnsTheBodyOrIsRefused(): void {
		$importer = $this->importerDownloading(body: self::CSV);
		$this->assertSame(self::CSV, $importer->download(url: 'https://upl.example.org/list.csv'));

		try {
			$this->importerDownloading(body: " \n")->download(url: 'https://upl.example.org/list.csv');
			$this->fail('An empty download must be refused.');
		} catch (RuntimeException $e) {
			$this->assertSame('The UPL list at https://upl.example.org/list.csv is empty.', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('could not be downloaded from https://upl.example.org/list.csv: timed out');
		$this->importerDownloading(body: null)->download(url: 'https://upl.example.org/list.csv');
	}//end testADownloadReturnsTheBodyOrIsRefused()

	/**
	 * An importer whose HTTP client answers with the body, or throws when it is null.
	 *
	 * @param string|null $body The response body.
	 *
	 * @return UplListImporter
	 */
	private function importerDownloading(?string $body): UplListImporter {
		$client = $this->createMock(IClient::class);
		if ($body === null) {
			$client->method('get')->willThrowException(new RuntimeException('timed out'));
		} else {
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn($body);
			$client->method('get')->with('https://upl.example.org/list.csv', ['timeout' => 60])->willReturn($response);
		}

		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new UplListImporter(
			new UplListParser(),
			$clients,
			$this->appConfig([]),
			$this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class)
		);
	}//end importerDownloading()
}//end class
