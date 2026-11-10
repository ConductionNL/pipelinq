<?php

/**
 * Golden test for importing and removing the example records.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Demo
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

namespace OCA\Pipelinq\Tests\Unit\Service\Demo;

use JsonSerializable;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\Object\ObjectDeletion;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\Demo\DemoRecordSweeper;
use OCA\Pipelinq\Service\Demo\DemoRecordsDescriptor;
use OCA\Pipelinq\Service\Demo\DemoRegisterImporter;
use OCA\Pipelinq\Service\Demo\DemoUserFields;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Runs import() or remove() against a scripted descriptor and scripted
 * OpenRegister services, and compares the result and every call with
 * DemoRegisterImporterGolden.json. Set DEMO_IMPORTER_GOLDEN_RECORD=1 to
 * rewrite the golden file.
 *
 * @covers \OCA\Pipelinq\Service\Demo\DemoRegisterImporter
 * @covers \OCA\Pipelinq\Service\Demo\DemoRecordsDescriptor
 * @covers \OCA\Pipelinq\Service\Demo\DemoRecordSweeper
 */
class DemoRegisterImporterGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/DemoRegisterImporterGolden.json';

	/**
	 * Calls recorded during one case, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * The temporary app directory of the current case.
	 *
	 * @var string
	 */
	private string $appDir = '';

	/**
	 * The example descriptor most cases use.
	 *
	 * @return array<string, mixed>
	 */
	private static function descriptor(): array {
		return [
			'components' => [
				'objects' => [
					['@self' => ['schema' => 'product', 'slug' => 'p-same'], 'name' => 'Widget'],
					['@self' => ['schema' => 'product', 'slug' => 'p-renamed'], 'name' => 'Gadget'],
					['@self' => ['schema' => 'product', 'slug' => 'p-unnamed'], 'price' => 3],
					['@self' => ['schema' => 'product', 'slug' => 'p-title'], 'title' => 'Titled', 'name' => ['not', 'scalar']],
					['@self' => ['schema' => 'product', 'slug' => 'p-archived'], 'name' => 'Kept'],
					['@self' => ['schema' => 'product', 'slug' => 'p-serialised'], 'code' => 'C1'],
					['@self' => ['schema' => 'missing', 'slug' => 'm-1'], 'name' => 'Nowhere'],
				],
			],
		];
	}//end descriptor()

	/**
	 * Every case's setup.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array {
		$base = ['action' => 'import', 'descriptor' => self::descriptor(), 'register' => '7', 'stored' => 'mixed', 'importResult' => 'full'];
		return [
			'import, descriptor missing'     => array_merge($base, ['descriptor' => null]),
			'import, descriptor not json'    => array_merge($base, ['descriptor' => 'not json']),
			'import, descriptor without list' => array_merge($base, ['descriptor' => ['components' => ['objects' => 'x']]]),
			'import'                         => $base,
			'import, empty result'           => array_merge($base, ['importResult' => 'empty']),
			'remove'                         => array_merge($base, ['action' => 'remove']),
			'remove, descriptor missing'     => array_merge($base, ['action' => 'remove', 'descriptor' => null]),
			'remove, no register'            => array_merge($base, ['action' => 'remove', 'register' => '']),
			'remove, delete fails'           => array_merge($base, ['action' => 'remove', 'stored' => 'delete fails']),
		];
	}//end definitions()

	/**
	 * The case names.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$names = [];
		foreach (array_keys(self::definitions()) as $name) {
			$names[$name] = [$name];
		}

		return $names;
	}//end cases()

	/**
	 * Record one call.
	 *
	 * @param string               $call The call name.
	 * @param array<string, mixed> $args The arguments by name.
	 *
	 * @return void
	 */
	public function recordCall(string $call, array $args): void {
		$this->events[] = ['call' => $call, 'args' => $args];
	}//end recordCall()

	/**
	 * Write the case's descriptor into a fresh app directory.
	 *
	 * @param mixed $descriptor The descriptor: an array, raw text, or null for none.
	 *
	 * @return void
	 */
	private function writeDescriptor(mixed $descriptor): void {
		$this->appDir = sys_get_temp_dir() . '/pq-demo-golden-' . bin2hex(random_bytes(6));
		mkdir($this->appDir . '/lib/Settings', 0777, true);
		if ($descriptor === null) {
			return;
		}

		$text = $descriptor;
		if (is_array($descriptor) === true) {
			$text = (string) json_encode($descriptor);
		}

		file_put_contents($this->appDir . '/lib/Settings/pipelinq_example_register.json', $text);
	}//end writeDescriptor()

	/**
	 * Remove the case's app directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$file = $this->appDir . '/lib/Settings/pipelinq_example_register.json';
		if (is_file($file) === true) {
			unlink($file);
		}

		foreach ([$this->appDir . '/lib/Settings', $this->appDir . '/lib', $this->appDir] as $dir) {
			if ($this->appDir !== '' && is_dir($dir) === true) {
				rmdir($dir);
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The rows the scripted object service stores under a schema id.
	 *
	 * @param string $script   The storage script.
	 * @param string $schemaId The schema id.
	 *
	 * @return array<int, mixed>
	 */
	public static function stored(string $script, string $schemaId): array {
		if ($schemaId !== '42') {
			return [];
		}

		$serialised = new class implements JsonSerializable {
			/**
			 * The serialised row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'id-serialised', '@self' => ['slug' => 'p-serialised'], 'code' => 'C1'];
			}//end jsonSerialize()
		};

		$rows = [
			['id' => 'id-same', '@self' => ['slug' => 'p-same'], 'name' => 'Widget'],
			['id' => 'id-renamed', '@self' => ['slug' => 'p-renamed'], 'name' => 'Gadget 2'],
			['id' => 'id-unnamed', '@self' => ['slug' => 'p-unnamed'], 'name' => 'Anything'],
			['id' => 'id-title', '@self' => ['slug' => 'p-title'], 'title' => 'Titled'],
			['id' => 'id-archived', '@self' => ['slug' => 'p-archived'], 'name' => 'Kept'],
			['id' => 'id-other', '@self' => ['slug' => 'p-other'], 'name' => 'Widget'],
			['id' => 'id-noslug', 'name' => 'Widget'],
			$serialised,
			'not a record',
			null,
		];
		if ($script === 'delete fails') {
			$rows[] = ['id' => 'id-fails', '@self' => ['slug' => 'p-same'], 'name' => 'Widget'];
		}

		return $rows;
	}//end stored()

	/**
	 * Mocks and fakes for every collaborator.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed>
	 */
	private function collaborators(array $case): array {
		$appDir     = $this->appDir;
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appDir);
		$appManager->method('getAppVersion')->willReturn('1.2.3');

		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default='') use ($case): string {
				if ($key === 'register') {
					return $case['register'];
				}

				return $default;
			}
		);

		$test          = $this;
		$configuration = new class ($test, $case['importResult']) extends ConfigurationService {
			/**
			 * @param DemoRegisterImporterGoldenTest $test   The recording test.
			 * @param string                         $result The import result script.
			 */
			public function __construct(private DemoRegisterImporterGoldenTest $test, private string $result) {
			}//end __construct()

			/**
			 * Record an import.
			 *
			 * @param string               $appId   Configuration identity.
			 * @param array<string, mixed> $data    The descriptor.
			 * @param string               $version App version.
			 * @param bool                 $force   Force flag.
			 *
			 * @return array<string, mixed>
			 */
			public function importFromApp(string $appId, array $data, string $version, bool $force=false): array {
				$this->test->recordCall(call: 'importFromApp', args: ['appId' => $appId, 'data' => $data, 'version' => $version, 'force' => $force]);
				if ($this->result === 'empty') {
					return [];
				}

				return ['objects' => array_fill(0, 3, 'x'), 'skipped' => ['objects' => 1, 'seedObjects' => 2]];
			}//end importFromApp()

			/**
			 * Record a soft delete of recorded imports.
			 *
			 * @param string $appId Configuration identity.
			 *
			 * @return array<string, mixed>
			 */
			public function softDeleteAppImports(string $appId): array {
				$this->test->recordCall(call: 'softDeleteAppImports', args: ['appId' => $appId]);
				return ['softDeleted' => 2];
			}//end softDeleteAppImports()
		};

		$schema = new class extends Schema {
			/**
			 * Skip the entity's field registration.
			 */
			public function __construct() {
			}//end __construct()

			/**
			 * The schema id.
			 *
			 * @return int
			 */
			public function getId(): int {
				return 42;
			}//end getId()
		};

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(
			function (string|int $slug) use ($schema): Schema {
				$this->recordCall(call: 'schemaMapper.find', args: ['slug' => $slug]);
				if ($slug !== 'product') {
					throw new RuntimeException('not found');
				}

				return $schema;
			}
		);

		$objectService = $this->createMock(originalClassName: ObjectService::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config) use ($case): array {
				$this->recordCall(call: 'findAll', args: ['config' => $config]);
				return DemoRegisterImporterGoldenTest::stored(script: $case['stored'], schemaId: (string) $config['filters']['schema']);
			}
		);
		$objectService->method('deleteObject')->willReturnCallback(
			function (ObjectDeletion $deletion): bool {
				$uuid = $deletion->uuid;
				$register = $deletion->target->register;
				$schemaId = $deletion->target->schema;
				$this->recordCall(call: 'deleteObject', args: ['uuid' => $uuid, 'register' => $register, 'schema' => $schemaId]);
				if ($uuid === 'id-archived') {
					throw new RuntimeException('SCHEMA_ARCHIVAL_IMMUTABLE: append-only');
				}

				if ($uuid === 'id-fails') {
					throw new RuntimeException('database gone');
				}

				return true;
			}
		);

		$userFields = $this->createMock(originalClassName: DemoUserFields::class);
		$userFields->method('withRealUsers')->willReturnCallback(
			function (array $objects): array {
				$this->recordCall(call: 'withRealUsers', args: ['count' => count($objects)]);
				return array_map(static fn (array $object): array => $object + ['owner' => 'admin'], $objects);
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		foreach (['info', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string|\Stringable $message, array $context=[]) use ($level, $appDir): void {
					if (isset($context['path']) === true) {
						$context['path'] = str_replace($appDir, '<app>', (string) $context['path']);
					}

					$this->recordCall(call: 'log.' . $level, args: ['message' => (string) $message, 'context' => $context]);
				}
			);
		}

		return [
			'appManager'           => $appManager,
			'appConfig'            => $appConfig,
			'configurationService' => $configuration,
			'schemaMapper'         => $schemaMapper,
			'objectService'        => $objectService,
			'logger'               => $logger,
			'userFields'           => $userFields,
		];
	}//end collaborators()

	/**
	 * Build the importer.
	 *
	 * @param array<string, mixed> $c The collaborators.
	 *
	 * @return DemoRegisterImporter
	 */
	private function makeImporter(array $c): DemoRegisterImporter {
		return new DemoRegisterImporter(
			descriptor: new DemoRecordsDescriptor(appManager: $c['appManager'], userFields: $c['userFields'], logger: $c['logger']),
			configurationService: $c['configurationService'],
			sweeper: new DemoRecordSweeper(appConfig: $c['appConfig'], schemaMapper: $c['schemaMapper'], objectService: $c['objectService']),
			logger: $c['logger'],
		);
	}//end makeImporter()

	/**
	 * The result and the calls match the golden.
	 *
	 * @param string $name The case name.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testImportAndRemoveMatchTheGolden(string $name): void {
		$case         = self::definitions()[$name];
		$this->events = [];
		$this->writeDescriptor(descriptor: $case['descriptor']);
		$importer = $this->makeImporter(c: $this->collaborators(case: $case));
		try {
			$result = ['returned' => $importer->{$case['action']}()];
		} catch (Throwable $e) {
			$result = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode((string) json_encode(['result' => $result, 'events' => $this->events]), true);
		if (getenv('DEMO_IMPORTER_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(
				self::GOLDEN,
				json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $name, array: $golden);
		$this->assertSame(expected: $golden[$name], actual: $actual);
	}//end testImportAndRemoveMatchTheGolden()
}//end class
