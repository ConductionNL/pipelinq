<?php

/**
 * Unit tests for ExportUploadService.
 *
 * Verifies the destination upload orchestration against a fully mocked external
 * sink (no live warehouse): successful upload + manifest/ack recording,
 * exponential-backoff retry of a transient failure, partial-success accounting
 * across multiple files, all-failed terminal status, unsupported destination
 * type, and the path-template/naming-convention resolution. The mock sink lets
 * us assert retry behaviour without real wall-clock delays.
 *
 * Also covers real credential resolution (`resolveCredentials()`):
 * `OCA\OpenConnector\Service\SourceService` — the class this used to resolve
 * credentials through — does not exist, so every call used to throw, get
 * swallowed, and silently resolve to an empty map. The tests below configure
 * a real `ObjectServiceInterface` mock returning a raw Source payload and
 * assert both the exact register/schema/`_render` lookup shape and that the
 * extracted credentials reach the sink's `upload()` call — not a test that
 * mocks resolution away, which is exactly the shape of test that shipped
 * alongside the original bug.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Adapter\ExportSinkInterface;
use OCA\Pipelinq\Adapter\ExportSinkRegistry;
use OCA\Pipelinq\Service\Export\ExportUploadService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ExportUploadService with a mocked external sink.
 */
class ExportUploadServiceTest extends TestCase {
	/**
	 * Build the upload service wired to a registry holding one mock sink.
	 *
	 * @param ExportSinkInterface $sink The mock sink to register.
	 * @param ObjectServiceInterface|null $objectService The (mock) OpenRegister object service. Defaults to an unconfigured mock — no OpenConnector source, credentials resolve to an empty map.
	 *
	 * @return ExportUploadService The service under test.
	 */
	private function service(ExportSinkInterface $sink, ?ObjectServiceInterface $objectService = null): ExportUploadService {
		$registry = new ExportSinkRegistry([$sink]);

		$container = $this->createMock(ContainerInterface::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new ExportUploadService(
			container: $container,
			appConfig: $appConfig,
			objectService: $objectService ?? $this->createMock(ObjectServiceInterface::class),
			sinks: $registry,
			logger: $logger,
		);
		// Never sleep in tests.
		$service->setSleepBetweenRetries(false);

		return $service;
	}//end service()

	/**
	 * Build a Source entity mock whose `getObject()` returns the given raw
	 * (unrendered) field set.
	 *
	 * @param array<string, mixed> $fields The raw source fields.
	 *
	 * @return ObjectEntityInterface The mock source entity.
	 */
	private function sourceEntity(array $fields): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn(null);
		$entity->method('getObject')->willReturn($fields);

		return $entity;
	}//end sourceEntity()

	/**
	 * A sink that always succeeds, recording each upload.
	 *
	 * @param string $ack The acknowledgement to return.
	 *
	 * @return ExportSinkInterface The mock sink.
	 */
	private function alwaysSucceedSink(string $ack = 'etag-123'): ExportSinkInterface {
		return new class($ack) implements ExportSinkInterface {
			/**
			 * @param string $ack The ack to return.
			 */
			public function __construct(
				private string $ack,
			) {
			}

			public function getType(): string {
				return 's3';
			}

			public function testConnection(array $credentials, array $destination): bool {
				return true;
			}

			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				return $this->ack;
			}
		};
	}//end alwaysSucceedSink()

	/**
	 * A destination of type s3 with a path template.
	 *
	 * @return array<string, mixed> The destination config.
	 */
	private function destination(): array {
		return [
			'type' => 's3',
			'pathTemplate' => 'exports/{schema}',
			'namingConvention' => '{schema}_{run_id}.csv',
		];
	}//end destination()

	/**
	 * One file descriptor as produced by ExportDataService.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed> The file descriptor.
	 */
	private function file(string $schema = 'client'): array {
		$contents = "id,name\r\n1,Alice\r\n";

		return [
			'schema' => $schema,
			'rows' => 1,
			'contents' => $contents,
			'size_bytes' => strlen($contents),
			'sha256' => hash('sha256', $contents),
			'compression_used' => 'none',
			'watermark_to' => null,
		];
	}//end file()

	/**
	 * A successful upload records the manifest entry, byte count and ack.
	 *
	 * @return void
	 */
	public function testSuccessfulUpload(): void {
		$service = $this->service($this->alwaysSucceedSink('etag-abc'));

		$result = $service->uploadFiles(
			destination: $this->destination(),
			files: [$this->file()],
			context: ['run_id' => 'run-1']
		);

		$this->assertSame('all_succeeded', $result['status']);
		$this->assertSame(1, $result['file_count']);
		$this->assertSame('etag-abc', $result['destination_ack']);
		$this->assertSame('success', $result['manifest'][0]['upload_status']);
		$this->assertSame('exports/client/client_run-1.csv', $result['manifest'][0]['path']);
	}//end testSuccessfulUpload()

	/**
	 * A sink that fails the first N attempts then succeeds; asserts retry.
	 *
	 * @return void
	 */
	public function testExponentialBackoffRetrySucceeds(): void {
		$sink = new class implements ExportSinkInterface {

			/**
			 * @var int Attempt counter.
			 */
			public int $attempts = 0;

			public function getType(): string {
				return 's3';
			}

			public function testConnection(array $credentials, array $destination): bool {
				return true;
			}

			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				$this->attempts++;
				if ($this->attempts < 3) {
					throw new RuntimeException('transient network error');
				}

				return 'etag-after-retry';
			}
		};

		$service = $this->service($sink);

		$result = $service->uploadFiles(
			destination: $this->destination(),
			files: [$this->file()],
			context: ['run_id' => 'run-2']
		);

		$this->assertSame('all_succeeded', $result['status']);
		$this->assertSame(3, $sink->attempts, 'Upload should retry until the 3rd attempt succeeds.');
		$this->assertSame('etag-after-retry', $result['destination_ack']);
	}//end testExponentialBackoffRetrySucceeds()

	/**
	 * A permanently failing sink exhausts its retries and reports all_failed.
	 *
	 * @return void
	 */
	public function testAllFailedAfterRetriesExhausted(): void {
		$sink = new class implements ExportSinkInterface {

			/**
			 * @var int Attempt counter.
			 */
			public int $attempts = 0;

			public function getType(): string {
				return 's3';
			}

			public function testConnection(array $credentials, array $destination): bool {
				return false;
			}

			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				$this->attempts++;
				throw new RuntimeException('permanent failure');
			}
		};

		$service = $this->service($sink);

		$result = $service->uploadFiles(
			destination: $this->destination(),
			files: [$this->file()],
			context: ['run_id' => 'run-3']
		);

		$this->assertSame('all_failed', $result['status']);
		$this->assertSame(0, $result['file_count']);
		$this->assertSame('failed', $result['manifest'][0]['upload_status']);
		// BACKOFF_SECONDS has 5 entries -> 5 attempts.
		$this->assertSame(5, $sink->attempts);
		$this->assertNotNull($result['error_message']);
	}//end testAllFailedAfterRetriesExhausted()

	/**
	 * One good file + one bad file yields a partial status.
	 *
	 * @return void
	 */
	public function testPartialUpload(): void {
		$sink = new class implements ExportSinkInterface {

			public function getType(): string {
				return 's3';
			}

			public function testConnection(array $credentials, array $destination): bool {
				return true;
			}

			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				// Fail the "lead" schema, succeed the rest.
				if (str_contains($remotePath, 'lead') === true) {
					throw new RuntimeException('lead upload rejected');
				}

				return 'ok';
			}
		};

		$service = $this->service($sink);

		$result = $service->uploadFiles(
			destination: $this->destination(),
			files: [$this->file('client'), $this->file('lead')],
			context: ['run_id' => 'run-4']
		);

		$this->assertSame('partial', $result['status']);
		$this->assertSame(1, $result['file_count']);
	}//end testPartialUpload()

	/**
	 * An unsupported destination type fails closed without touching a sink.
	 *
	 * @return void
	 */
	public function testUnsupportedDestinationType(): void {
		$service = $this->service($this->alwaysSucceedSink());
		$destination = ['type' => 'mainframe', 'pathTemplate' => 'x'];

		$result = $service->uploadFiles(
			destination: $destination,
			files: [$this->file()],
			context: ['run_id' => 'run-5']
		);

		$this->assertSame('all_failed', $result['status']);
	}//end testUnsupportedDestinationType()

	/**
	 * The path resolver substitutes the template + naming placeholders.
	 *
	 * @return void
	 */
	public function testResolvePathSubstitutesPlaceholders(): void {
		$service = $this->service($this->alwaysSucceedSink());

		$path = $service->resolvePath(
			destination: [
				'pathTemplate' => 'warehouse/{partition}/{schema}',
				'namingConvention' => '{schema}_{run_id}_{timestamp}.parquet',
			],
			file: ['schema' => 'client'],
			context: ['run_id' => 'r9', 'timestamp' => '20260101T000000Z', 'partition' => '2026']
		);

		$this->assertSame('warehouse/2026/client/client_r9_20260101T000000Z.parquet', $path);
	}//end testResolvePathSubstitutesPlaceholders()

	/**
	 * A sink that records the credentials it receives on each upload().
	 *
	 * @return ExportSinkInterface The recording mock sink.
	 */
	private function credentialRecordingSink(): ExportSinkInterface {
		return new class implements ExportSinkInterface {
			/** @var array<int, array<string, mixed>> */
			public array $uploads = [];

			public function getType(): string {
				return 's3';
			}

			public function testConnection(array $credentials, array $destination): bool {
				return true;
			}

			public function upload(array $credentials, array $destination, string $remotePath, string $contents): string {
				$this->uploads[] = $credentials;
				return 'etag-recorded';
			}
		};
	}//end credentialRecordingSink()

	/**
	 * A legacy inline `apikey` on the referenced OpenConnector source reaches
	 * the sink adapter's upload() credentials argument, and the Source is
	 * looked up with the exact register/schema/`_render` shape — the same
	 * raw read OpenConnector's own CallService / RawSourceResolver use
	 * internally to survive the write-only field strip on a rendered read.
	 *
	 * @return void
	 */
	public function testUploadResolvesLegacyCredentialsFromRawSourceRead(): void {
		$calls = [];
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
			) use (&$calls) {
				$calls[] = ['id' => $id, 'register' => $register, 'schema' => $schema, '_render' => $_render];
				return $this->sourceEntity(['apikey' => 'super-secret-key']);
			}
		);

		$sink = $this->credentialRecordingSink();
		$service = $this->service($sink, $objectService);

		$destination = $this->destination();
		$destination['connectorSourceId'] = 'oc-source-1';

		$result = $service->uploadFiles(
			destination: $destination,
			files: [$this->file()],
			context: ['run_id' => 'run-cred']
		);

		$this->assertSame('all_succeeded', $result['status']);
		$this->assertCount(1, $sink->uploads);
		$this->assertSame('super-secret-key', $sink->uploads[0]['apikey']);

		$this->assertNotEmpty($calls);
		foreach ($calls as $call) {
			$this->assertSame('openconnector', $call['register']);
			$this->assertSame('source', $call['schema']);
			$this->assertFalse($call['_render'], 'the source must be read RAW so write-only secret fields survive');
		}
	}//end testUploadResolvesLegacyCredentialsFromRawSourceRead()

	/**
	 * A source lookup that throws fails closed: the upload still runs with
	 * an empty credentials map (and whatever the sink itself then does with
	 * that — e.g. reject the connection — is the sink's own concern).
	 *
	 * @return void
	 */
	public function testUploadResolvesEmptyCredentialsWhenSourceLookupThrows(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willThrowException(new RuntimeException('OpenConnector unavailable'));

		$sink = $this->credentialRecordingSink();
		$service = $this->service($sink, $objectService);

		$destination = $this->destination();
		$destination['connectorSourceId'] = 'oc-source-missing';

		$result = $service->uploadFiles(
			destination: $destination,
			files: [$this->file()],
			context: ['run_id' => 'run-cred-fail']
		);

		$this->assertSame('all_succeeded', $result['status']);
		$this->assertSame([], $sink->uploads[0]);
	}//end testUploadResolvesEmptyCredentialsWhenSourceLookupThrows()
}//end class
