<?php

/**
 * Unit tests for ExportDestinationService's lifecycle: listing, create / update /
 * delete with their re-reads and probes, the allowlist on stored fields, the
 * validation refusals, the validity gate, and the probe of a destination whose
 * type no sink serves or whose source register is not on this instance.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Export
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Adapter\ExportSinkInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Service\Export\ExportDestinationService;
use OCA\Pipelinq\Tests\Unit\Support\FakeSlugResolver;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the destination lifecycle over an in-memory object store.
 *
 * @covers \OCA\Pipelinq\Service\Export\ExportDestinationService
 * @uses   \OCA\Pipelinq\Service\Export\AbstractExportService
 * @uses   \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses   \OCA\Pipelinq\Adapter\ExportSinkRegistry
 * @uses   \OCA\Pipelinq\Service\ConnectorSourceRegister
 */
class ExportDestinationServiceLifecycleTest extends TestCase {

	/**
	 * Stored objects by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * Every saveObject call: uuid and the object written.
	 *
	 * @var array<int, array{uuid: string|null, object: array<string, mixed>, register: mixed, schema: mixed}>
	 */
	private array $saves = [];

	/**
	 * Every deleteObject call.
	 *
	 * @var array<int, array{uuid: string, register: mixed, schema: mixed}>
	 */
	private array $deletes = [];

	/**
	 * The credentials each probe was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $probes = [];

	/**
	 * Whether the sink reports the destination reachable.
	 *
	 * @var bool
	 */
	public bool $reachable = true;

	/**
	 * The id a save without a uuid assigns.
	 *
	 * @var string
	 */
	private string $newId = 'dest-new';

	/**
	 * A mock IAppConfig resolving the register and destination schema keys.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default): string {
				return match ($key) {
					'register' => 'pipelinq-register',
					'exportDestination_schema' => 'exportDestination',
					default => $default,
				};
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * An entity whose raw object is the given fields.
	 *
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $fields): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(null);
		$entity->method('getObject')->willReturn($fields);
		return $entity;
	}//end entity()

	/**
	 * An object service over $this->store that records saves and deletes.
	 *
	 * @return ObjectServiceInterface
	 */
	private function objectService(): ObjectServiceInterface {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntityInterface {
				if ($schema === 'source') {
					return $this->entity(['apikey' => 'k-1']);
				}

				if (isset($this->store[$id]) === false) {
					return null;
				}

				return $this->entity($this->store[$id]);
			}
		);
		$objects->method('findAll')->willReturnCallback(
			fn (array $config = []): array => array_map(fn (array $o): ObjectEntityInterface => $this->entity($o), array_values($this->store))
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntityInterface {
				$this->saves[] = ['uuid' => $uuid, 'object' => $object, 'register' => $register, 'schema' => $schema];
				$id = $uuid ?? $this->newId;
				$this->store[$id] = array_merge($object, ['id' => $id]);
				return $this->entity($this->store[$id]);
			}
		);
		$objects->method('deleteObject')->willReturnCallback(
			function (string $uuid, $register = null, $schema = null): bool {
				$this->deletes[] = ['uuid' => $uuid, 'register' => $register, 'schema' => $schema];
				unset($this->store[$uuid]);
				return true;
			}
		);

		return $objects;
	}//end objectService()

	/**
	 * The service with an s3 sink that records its probes.
	 *
	 * @param array<int, string> $registers The connector registers this instance carries.
	 *
	 * @return ExportDestinationService
	 */
	private function service(array $registers = ['integriq']): ExportDestinationService {
		$test = $this;
		$sink = new class ($test) implements ExportSinkInterface {
			/**
			 * Keep the test.
			 *
			 * @param ExportDestinationServiceLifecycleTest $test The test.
			 */
			public function __construct(private ExportDestinationServiceLifecycleTest $test) {
			}

			/**
			 * The type.
			 *
			 * @return string
			 */
			public function getType(): string {
				return 's3';
			}

			/**
			 * Record the probe.
			 *
			 * @param array<string, mixed> $credentials The credentials.
			 * @param array<string, mixed> $destination The destination.
			 *
			 * @return bool
			 */
			public function testConnection(array $credentials, array $destination): bool {
				$this->test->probes[] = $credentials;
				return $this->test->reachable;
			}

			/**
			 * Not used.
			 *
			 * @param array<string, mixed> $credentials The credentials.
			 * @param array<string, mixed> $destination The destination.
			 * @param string               $remotePath  The path.
			 * @param string               $contents    The contents.
			 *
			 * @return string
			 */
			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				return 'unused';
			}
		};

		return new ExportDestinationService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->appConfig(),
			objectService: $this->objectService(),
			sinks: new ExportSinkRegistry([$sink]),
			connectorRegister: FakeSlugResolver::connectorRegister($registers),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * A complete, valid destination config.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 *
	 * @return array<string, mixed>
	 */
	private function config(array $overrides = []): array {
		return array_merge(
			[
				'name' => 'Warehouse',
				'type' => 's3',
				'connectorSourceId' => 'oc-source-1',
				'pathTemplate' => 'exports/{schema}',
			],
			$overrides
		);
	}//end config()

	/**
	 * Listing returns every stored destination, read with the configured register and schema.
	 *
	 * @return void
	 */
	public function testListDestinationsReturnsTheStoredObjects(): void {
		$this->store = [
			'a' => ['name' => 'A', 'type' => 's3'],
			'b' => ['name' => 'B', 'type' => 'gcs'],
		];

		$this->assertSame(
			[['name' => 'A', 'type' => 's3'], ['name' => 'B', 'type' => 'gcs']],
			$this->service()->listDestinations()
		);
	}//end testListDestinationsReturnsTheStoredObjects()

	/**
	 * Creating stores only allowlisted fields as untested, then probes and returns the re-read.
	 *
	 * @return void
	 */
	public function testCreateStoresAllowlistedFieldsAndProbes(): void {
		$created = $this->service()->createDestination(
			$this->config(['secretKey' => 'leak', 'token' => 'leak', '@self' => ['id' => 'x']])
		);

		$first = $this->saves[0];
		$this->assertNull($first['uuid']);
		$this->assertSame('pipelinq-register', $first['register']);
		$this->assertSame('exportDestination', $first['schema']);
		$this->assertSame(
			['name', 'type', 'connectorSourceId', 'pathTemplate', 'validationStatus'],
			array_keys($first['object'])
		);
		$this->assertSame('untested', $first['object']['validationStatus']);

		$this->assertSame([['apikey' => 'k-1']], $this->probes);
		$this->assertSame('dest-new', $this->saves[1]['uuid']);
		$this->assertSame('valid', $created['validationStatus']);
		$this->assertArrayHasKey('lastValidatedAt', $created);
		$this->assertArrayNotHasKey('token', $created);
	}//end testCreateStoresAllowlistedFieldsAndProbes()

	/**
	 * A save that comes back without an id is returned as saved, without a probe.
	 *
	 * @return void
	 */
	public function testCreateWithoutAnIdReturnsTheSavedObject(): void {
		$this->newId = '';

		$created = $this->service()->createDestination($this->config());

		$this->assertSame([], $this->probes);
		$this->assertCount(1, $this->saves);
		$this->assertSame('untested', $created['validationStatus']);
	}//end testCreateWithoutAnIdReturnsTheSavedObject()

	/**
	 * Each required field is refused with its own message before anything is stored.
	 *
	 * @return void
	 */
	public function testValidationRefusesEachMissingField(): void {
		$cases = [
			'Destination name is required.' => ['name' => '  '],
			"Unsupported destination type 'ftp'." => ['type' => 'ftp'],
			'A path template is required.' => ['pathTemplate' => ''],
		];

		foreach ($cases as $message => $override) {
			try {
				$this->service()->createDestination($this->config($override));
				$this->fail("Accepted an invalid destination: {$message}");
			} catch (OCSBadRequestException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}

		$this->assertSame([], $this->saves);
	}//end testValidationRefusesEachMissingField()

	/**
	 * Updating merges sanitized fields over the stored ones, resets and re-probes.
	 *
	 * @return void
	 */
	public function testUpdateMergesResetsAndReprobes(): void {
		$this->store['dest-1'] = array_merge($this->config(), ['validationStatus' => 'valid']);
		$this->reachable = false;

		$updated = $this->service()->updateDestination('dest-1', ['name' => 'Lake', 'password' => 'leak']);

		$this->assertSame('dest-1', $this->saves[0]['uuid']);
		$this->assertSame('Lake', $this->saves[0]['object']['name']);
		$this->assertSame('untested', $this->saves[0]['object']['validationStatus']);
		$this->assertArrayNotHasKey('password', $this->saves[0]['object']);
		$this->assertCount(1, $this->probes);
		$this->assertSame('invalid', $updated['validationStatus']);
		$this->assertSame('Lake', $updated['name']);
	}//end testUpdateMergesResetsAndReprobes()

	/**
	 * An update that would leave the destination invalid is refused and stores nothing.
	 *
	 * @return void
	 */
	public function testUpdateRefusesAnInvalidResult(): void {
		$this->store['dest-1'] = $this->config();

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage("Unsupported destination type 'ftp'.");

		try {
			$this->service()->updateDestination('dest-1', ['type' => 'ftp']);
		} finally {
			$this->assertSame([], $this->saves);
		}
	}//end testUpdateRefusesAnInvalidResult()

	/**
	 * Deleting an existing destination deletes it with the configured register and schema.
	 *
	 * @return void
	 */
	public function testDeleteRemovesAnExistingDestination(): void {
		$this->store['dest-1'] = $this->config();

		$this->service()->deleteDestination('dest-1');

		$this->assertSame([['uuid' => 'dest-1', 'register' => 'pipelinq-register', 'schema' => 'exportDestination']], $this->deletes);
	}//end testDeleteRemovesAnExistingDestination()

	/**
	 * Deleting an absent destination is a not-found and deletes nothing.
	 *
	 * @return void
	 */
	public function testDeleteOfAnAbsentDestinationDeletesNothing(): void {
		try {
			$this->service()->deleteDestination('missing');
			$this->fail('An absent destination was deleted.');
		} catch (OCSNotFoundException $e) {
			$this->assertSame('Export destination not found.', $e->getMessage());
		}

		$this->assertSame([], $this->deletes);
	}//end testDeleteOfAnAbsentDestinationDeletesNothing()

	/**
	 * Only a destination whose stored status is "valid" passes the gate; absent ones fail it.
	 *
	 * @return void
	 */
	public function testIsValidReadsTheStoredStatus(): void {
		$this->store['ok'] = ['validationStatus' => 'valid'];
		$this->store['bad'] = ['validationStatus' => 'invalid'];
		$this->store['new'] = ['name' => 'x'];
		$service = $this->service();

		$this->assertTrue($service->isValid('ok'));
		$this->assertFalse($service->isValid('bad'));
		$this->assertFalse($service->isValid('new'));
		$this->assertFalse($service->isValid('missing'));
	}//end testIsValidReadsTheStoredStatus()

	/**
	 * A type no sink serves is recorded invalid without a probe.
	 *
	 * @return void
	 */
	public function testAnUnservedTypeIsInvalidWithoutAProbe(): void {
		$this->store['dest-1'] = $this->config(['type' => 'bigquery']);

		$this->assertFalse($this->service()->testConnection('dest-1'));
		$this->assertSame([], $this->probes);
		$this->assertSame('invalid', $this->store['dest-1']['validationStatus']);
	}//end testAnUnservedTypeIsInvalidWithoutAProbe()

	/**
	 * Without a source reference, or without the source register here, the probe gets no credentials.
	 *
	 * @return void
	 */
	public function testNoCredentialsWithoutASourceOrItsRegister(): void {
		$this->store['nosource'] = $this->config(['connectorSourceId' => '']);
		$this->store['dest-1'] = $this->config();

		$this->service()->testConnection('nosource');
		$this->service(registers: [])->testConnection('dest-1');

		$this->assertSame([[], []], $this->probes);
	}//end testNoCredentialsWithoutASourceOrItsRegister()
}//end class
