<?php

/**
 * Unit tests for ExportDestinationService.
 *
 * Verifies destination CRUD, validation, and — the case that had no coverage
 * at all before this test file existed — credential resolution for the
 * connectivity probe. `resolveCredentials()` used to resolve
 * `OCA\OpenConnector\Service\SourceService`, a class that does not exist;
 * the call threw, was swallowed, and every probe silently ran with an empty
 * credentials map. These tests configure a real `ObjectServiceInterface`
 * mock returning a raw Source payload and assert the exact register/schema/
 * `_render` lookup shape and that the extracted credentials reach the sink
 * adapter — not a test that mocks credential resolution away.
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
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ExportDestinationService, including real credential resolution.
 */
class ExportDestinationServiceTest extends TestCase {
	/**
	 * A mock IAppConfig resolving the register + schema keys used by
	 * AbstractExportService::config().
	 *
	 * @return IAppConfig The mock app config.
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default) {
				if ($key === 'register') {
					return 'pipelinq-register';
				}

				if ($key === 'exportDestination_schema') {
					return 'exportDestination';
				}

				return $default;
			}
		);

		return $appConfig;
	}//end appConfig()

	/**
	 * Build a Source entity mock whose `getObject()` returns the given raw
	 * (unrendered) field set — mirrors how `AbstractExportService::toArray()`
	 * normalises an `ObjectEntityInterface` when it is not directly an array.
	 *
	 * @param array<string, mixed> $fields The raw entity fields.
	 *
	 * @return ObjectEntityInterface The mock entity.
	 */
	private function entity(array $fields): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(null);
		$entity->method('getObject')->willReturn($fields);

		return $entity;
	}//end entity()

	/**
	 * Build an `ObjectServiceInterface` mock that routes `find()` by
	 * register/schema — one branch for pipelinq's own destination object,
	 * one for the OpenConnector source — and records every call on the
	 * returned recorder so the lookup shape can be asserted.
	 *
	 * @param array<string, mixed> $destination The stored destination fields.
	 * @param ObjectEntityInterface|null|\Throwable $sourceResult The source lookup outcome.
	 *
	 * @return array{0: ObjectServiceInterface, 1: object} The mock + a `calls` recorder.
	 */
	private function objectService(array $destination, ObjectEntityInterface|null|\Throwable $sourceResult): array {
		$recorder = new class {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];
		};

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (
				$id,
				$_extend = [],
				$files = false,
				$register = null,
				$schema = null,
				$_rbac = true,
				$_multitenancy = true,
				$_render = true,
				$_audit = true,
			) use ($recorder, $destination, $sourceResult) {
				$recorder->calls[] = ['id' => $id, 'register' => $register, 'schema' => $schema, '_render' => $_render];

				if ($register === 'openconnector' && $schema === 'source') {
					if ($sourceResult instanceof \Throwable) {
						throw $sourceResult;
					}

					return $sourceResult;
				}

				return $this->entity($destination);
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			fn () => $this->entity($destination)
		);

		return [$objectService, $recorder];
	}//end objectService()

	/**
	 * A destination referencing an OpenConnector source, wired to a sink
	 * that records the credentials it was probed with.
	 *
	 * @param ObjectServiceInterface $objectService The (mock) OpenRegister object service.
	 * @param bool $sinkResult What the mock sink's testConnection() returns.
	 *
	 * @return array{0: ExportDestinationService, 1: object} The service + the recording sink.
	 */
	private function service(ObjectServiceInterface $objectService, bool $sinkResult = true): array {
		$sink = new class ($sinkResult) implements ExportSinkInterface {
			/** @var array<int, array<string, mixed>> */
			public array $probes = [];

			/**
			 * @param bool $result The connectivity result to return.
			 */
			public function __construct(private bool $result) {
			}

			public function getType(): string {
				return 's3';
			}

			public function testConnection(array $credentials, array $destination): bool {
				$this->probes[] = $credentials;
				return $this->result;
			}

			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				return 'unused';
			}
		};

		$registry = new ExportSinkRegistry([$sink]);

		$service = new ExportDestinationService(
			container: $this->createMock(ContainerInterface::class),
			appConfig: $this->appConfig(),
			objectService: $objectService,
			sinks: $registry,
			logger: $this->createMock(LoggerInterface::class),
		);

		return [$service, $sink];
	}//end service()

	/**
	 * The stock destination payload used across the tests below.
	 *
	 * @param string $sourceId The connectorSourceId to reference.
	 *
	 * @return array<string, mixed> The destination fields.
	 */
	private function destination(string $sourceId): array {
		return [
			'@self' => ['id' => 'dest-1'],
			'uuid' => 'dest-1',
			'name' => 'Warehouse',
			'type' => 's3',
			'connectorSourceId' => $sourceId,
			'pathTemplate' => 'exports/{schema}',
		];
	}//end destination()

	/**
	 * A legacy inline `apikey` on the connector source reaches the sink
	 * adapter's credentials argument, and the Source is looked up with the
	 * exact register/schema/`_render` shape.
	 *
	 * @return void
	 */
	public function testTestConnectionResolvesLegacyCredentialsFromRawSourceRead(): void {
		$destination = $this->destination('oc-source-1');
		[$objectService, $recorder] = $this->objectService(
			destination: $destination,
			sourceResult: $this->entity(['apikey' => 'super-secret-key']),
		);
		[$service, $sink] = $this->service($objectService);

		$valid = $service->testConnection(id: 'dest-1');

		$this->assertTrue($valid);
		$this->assertCount(1, $sink->probes);
		$this->assertSame('super-secret-key', $sink->probes[0]['apikey']);

		$sourceLookups = array_values(array_filter($recorder->calls, fn (array $c) => $c['id'] === 'oc-source-1'));
		$this->assertNotEmpty($sourceLookups, 'the connector source must be looked up via ObjectServiceInterface::find()');
		foreach ($sourceLookups as $call) {
			$this->assertSame('openconnector', $call['register']);
			$this->assertSame('source', $call['schema']);
			$this->assertFalse($call['_render'], 'the source must be read RAW so write-only secret fields survive');
		}
	}//end testTestConnectionResolvesLegacyCredentialsFromRawSourceRead()

	/**
	 * A source lookup that throws (OpenRegister unavailable) fails closed: an
	 * empty credentials map reaches the sink, no exception escapes.
	 *
	 * @return void
	 */
	public function testTestConnectionFailsClosedWhenSourceLookupThrows(): void {
		$destination = $this->destination('oc-source-missing');
		[$objectService] = $this->objectService(
			destination: $destination,
			sourceResult: new \RuntimeException('OpenConnector unavailable'),
		);
		[$service, $sink] = $this->service($objectService, sinkResult: false);

		$valid = $service->testConnection(id: 'dest-1');

		$this->assertFalse($valid);
		$this->assertCount(1, $sink->probes);
		$this->assertSame([], $sink->probes[0]);
	}//end testTestConnectionFailsClosedWhenSourceLookupThrows()

	/**
	 * A source resolving to null (not found) is treated the same as a lookup
	 * failure: empty credentials, no exception.
	 *
	 * @return void
	 */
	public function testTestConnectionFailsClosedWhenSourceNotFound(): void {
		$destination = $this->destination('oc-source-gone');
		[$objectService] = $this->objectService(destination: $destination, sourceResult: null);
		[$service, $sink] = $this->service($objectService, sinkResult: false);

		$valid = $service->testConnection(id: 'dest-1');

		$this->assertFalse($valid);
		$this->assertSame([], $sink->probes[0]);
	}//end testTestConnectionFailsClosedWhenSourceNotFound()

	/**
	 * A source backed only by a broker `credentialRef` (no legacy inline
	 * fields) yields no raw secret, but does not throw and still surfaces
	 * the non-secret authentication config to the sink.
	 *
	 * @return void
	 */
	public function testTestConnectionSurfacesBrokerReferenceWithoutASecretValue(): void {
		$destination = $this->destination('oc-source-broker');
		[$objectService] = $this->objectService(
			destination: $destination,
			sourceResult: $this->entity([
				'configuration' => [
					'authentication' => ['credentialRef' => 'cred-broker-ref-1'],
				],
			]),
		);
		[$service, $sink] = $this->service($objectService);

		$service->testConnection(id: 'dest-1');

		$credentials = $sink->probes[0];
		$this->assertArrayNotHasKey('apikey', $credentials);
		$this->assertArrayNotHasKey('secret', $credentials);
		$this->assertSame(['credentialRef' => 'cred-broker-ref-1'], $credentials['authentication']);
	}//end testTestConnectionSurfacesBrokerReferenceWithoutASecretValue()

	/**
	 * Creating a destination without a connector source is rejected before
	 * any credential resolution is attempted.
	 *
	 * @return void
	 */
	public function testCreateDestinationRejectsMissingSource(): void {
		[$objectService] = $this->objectService(destination: $this->destination(''), sourceResult: null);
		[$service] = $this->service($objectService);

		$this->expectException(OCSBadRequestException::class);

		$service->createDestination([
			'name' => 'Warehouse',
			'type' => 's3',
			'pathTemplate' => 'exports/{schema}',
		]);
	}//end testCreateDestinationRejectsMissingSource()

	/**
	 * Getting a destination that does not exist throws OCSNotFoundException.
	 *
	 * @return void
	 */
	public function testGetDestinationThrowsWhenAbsent(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturn(null);
		[$service] = $this->service($objectService);

		$this->expectException(OCSNotFoundException::class);
		$service->getDestination(id: 'missing');
	}//end testGetDestinationThrowsWhenAbsent()
}//end class
