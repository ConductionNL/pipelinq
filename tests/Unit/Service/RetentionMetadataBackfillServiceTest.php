<?php

/**
 * Unit tests for RetentionMetadataBackfillService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/platform-client-retention/tasks.md#3-review-and-back-fill
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\RetentionMetadataBackfillService;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Existing clients and contact persons get retention metadata once.
 */
#[CoversClass(RetentionMetadataBackfillService::class)]
class RetentionMetadataBackfillServiceTest extends TestCase {

	/**
	 * Stored records, by uuid: [schema id, retention block].
	 *
	 * @var array<string, object>
	 */
	private array $store = [];

	/**
	 * Uuids written back, in order.
	 *
	 * @var array<int, string>
	 */
	private array $updates = [];

	/**
	 * The retention service fake: records each record it was asked about.
	 *
	 * @var object
	 */
	private object $retention;

	/**
	 * Set up the fake store with two clients and one contact.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = [
			'c-1' => $this->record(uuid: 'c-1', schema: 'client-schema', retention: null),
			'c-2' => $this->record(uuid: 'c-2', schema: 'client-schema', retention: ['archiefnominatie' => 'blijvend_bewaren']),
			'p-1' => $this->record(uuid: 'p-1', schema: 'contact-schema', retention: []),
		];
		$this->updates = [];
		$this->retention = new class {
			/**
			 * Uuids it was asked to apply metadata to.
			 *
			 * @var array<int, string>
			 */
			public array $asked = [];

			/**
			 * Mimic OpenRegister: nominate unless nominated, when the schema's term is enabled.
			 *
			 * @param object $object The record.
			 * @param object $schema The schema.
			 *
			 * @return object
			 */
			public function applyArchivalMetadata(object $object, object $schema): object {
				$this->asked[] = $object->uuid;
				$retention = ($object->getRetention() ?? []);
				if ($schema->archiveEnabled === true && empty($retention['archiefnominatie']) === true) {
					$object->setRetention(['archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P2Y', 'archiefactiedatum' => null]);
				}

				return $object;
			}
		};
	}//end setUp()

	/**
	 * A stored record fake.
	 *
	 * @param string     $uuid      Its uuid.
	 * @param string     $schema    Its schema id.
	 * @param array|null $retention Its retention block.
	 *
	 * @return object
	 */
	private function record(string $uuid, string $schema, ?array $retention): object {
		return new class($uuid, $schema, $retention) {
			/**
			 * Constructor.
			 *
			 * @param string     $uuid      Its uuid.
			 * @param string     $schema    Its schema id.
			 * @param array|null $retention Its retention block.
			 */
			public function __construct(public string $uuid, public string $schema, private ?array $retention) {
			}

			/**
			 * The retention block.
			 *
			 * @return array|null
			 */
			public function getRetention(): ?array {
				return $this->retention;
			}

			/**
			 * Replace the retention block.
			 *
			 * @param array|null $retention The block.
			 *
			 * @return void
			 */
			public function setRetention(?array $retention): void {
				$this->retention = $retention;
			}
		};
	}//end record()

	/**
	 * The service under test, over the fakes.
	 *
	 * @param array<string, string> $config      App config values.
	 * @param bool                  $archiveOn   Whether the schemas enable a term.
	 * @param string|null           $missing     An OpenRegister class the container cannot give.
	 *
	 * @return RetentionMetadataBackfillService
	 */
	private function service(array $config, bool $archiveOn = true, ?string $missing = null): RetentionMetadataBackfillService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($config[$key] ?? $default)
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac, bool $_multitenancy): array {
				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				$this->assertSame(500, $config['limit']);
				$rows = [];
				foreach ($this->store as $uuid => $record) {
					if ($record->schema === $config['filters']['schema'] && $config['filters']['register'] === 'reg-1') {
						$row = $this->createMock(ObjectEntityInterface::class);
						$row->method('getUuid')->willReturn($uuid);
						$rows[] = $row;
					}
				}

				return $rows;
			}
		);

		$objects = new class($this) {
			/**
			 * Constructor.
			 *
			 * @param RetentionMetadataBackfillServiceTest $test The test holding the store.
			 */
			public function __construct(private RetentionMetadataBackfillServiceTest $test) {
			}

			/**
			 * Find a stored record.
			 *
			 * @param string $uuid The uuid.
			 *
			 * @return object
			 */
			public function find(string $uuid): object {
				return $this->test->stored($uuid);
			}

			/**
			 * Write a record back.
			 *
			 * @param object $object The record.
			 *
			 * @return object
			 */
			public function update(object $object): object {
				$this->test->wrote($object->uuid);

				return $object;
			}
		};

		$schemaEntity = new class($archiveOn) {
			/**
			 * Constructor.
			 *
			 * @param bool $archiveEnabled Whether the term is enabled.
			 */
			public function __construct(public bool $archiveEnabled) {
			}
		};
		$schemas = new class($schemaEntity) {
			/**
			 * Constructor.
			 *
			 * @param object $schema The schema entity every find gives.
			 */
			public function __construct(private object $schema) {
			}

			/**
			 * Find a schema.
			 *
			 * @return object
			 */
			public function find(): object {
				return $this->schema;
			}
		};
		$registers = new class {
			/**
			 * Find a register.
			 *
			 * @return object
			 */
			public function find(): object {
				return new \stdClass();
			}
		};

		$byClass = [
			'OCA\OpenRegister\Service\RetentionService' => $this->retention,
			'OCA\OpenRegister\Db\MagicMapper' => $objects,
			'OCA\OpenRegister\Db\RegisterMapper' => $registers,
			'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $class) use ($byClass, $missing): object {
				if ($class === $missing || isset($byClass[$class]) === false) {
					throw new class('not found: ' . $class) extends RuntimeException implements NotFoundExceptionInterface {
					};
				}

				return $byClass[$class];
			}
		);

		return new RetentionMetadataBackfillService($appConfig, $objectService, $container, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * A stored record, for the mapper fake.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return object
	 */
	public function stored(string $uuid): object {
		return $this->store[$uuid];
	}//end stored()

	/**
	 * Record a write, for the mapper fake.
	 *
	 * @param string $uuid The uuid written.
	 *
	 * @return void
	 */
	public function wrote(string $uuid): void {
		$this->updates[] = $uuid;
	}//end wrote()

	/**
	 * The configured client and contact schemas.
	 *
	 * @return array<string, string>
	 */
	private static function configured(): array {
		return ['register' => 'reg-1', 'client_schema' => 'client-schema', 'contact_schema' => 'contact-schema'];
	}//end configured()

	/**
	 * Run twice, the metadata is applied and written once per record without a
	 * nomination; a nominated record is never touched.
	 *
	 * @return void
	 */
	public function testTwoRunsApplyMetadataOncePerRecord(): void {
		$service = $this->service(config: self::configured());

		$first = $service->backfill(schemaKeys: ['client_schema', 'contact_schema']);
		$second = $service->backfill(schemaKeys: ['client_schema', 'contact_schema']);

		$this->assertSame(['status' => 'done', 'applied' => 2, 'skipped' => 1, 'failed' => 0], $first);
		$this->assertSame(['status' => 'done', 'applied' => 0, 'skipped' => 3, 'failed' => 0], $second);
		$this->assertSame(['c-1', 'p-1'], $this->updates);
		$this->assertSame(['c-1', 'p-1'], $this->retention->asked);
		$this->assertSame('vernietigen', $this->store['c-1']->getRetention()['archiefnominatie']);
		$this->assertSame(['archiefnominatie' => 'blijvend_bewaren'], $this->store['c-2']->getRetention());
	}//end testTwoRunsApplyMetadataOncePerRecord()

	/**
	 * A schema without an enabled term gives no nomination, so nothing is written.
	 *
	 * @return void
	 */
	public function testNothingIsWrittenWhenTheSchemaDeclaresNoTerm(): void {
		$result = $this->service(config: self::configured(), archiveOn: false)->backfill(schemaKeys: ['client_schema', 'contact_schema']);

		$this->assertSame(['status' => 'done', 'applied' => 0, 'skipped' => 3, 'failed' => 0], $result);
		$this->assertSame([], $this->updates);
	}//end testNothingIsWrittenWhenTheSchemaDeclaresNoTerm()

	/**
	 * Without a configured register or schema nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredAppIsLeftAlone(): void {
		$withoutRegister = $this->service(config: ['client_schema' => 'client-schema'])->backfill(schemaKeys: ['client_schema']);
		$withoutSchema = $this->service(config: ['register' => 'reg-1'])->backfill(schemaKeys: ['client_schema', 'contact_schema']);

		$this->assertSame(['status' => 'not-configured', 'applied' => 0, 'skipped' => 0, 'failed' => 0], $withoutRegister);
		$this->assertSame(['status' => 'not-configured', 'applied' => 0, 'skipped' => 0, 'failed' => 0], $withoutSchema);
		$this->assertSame([], $this->retention->asked);
	}//end testAnUnconfiguredAppIsLeftAlone()

	/**
	 * Without OpenRegister's retention service nothing is written.
	 *
	 * @return void
	 */
	public function testAnUnavailableRetentionServiceWritesNothing(): void {
		$result = $this->service(config: self::configured(), missing: 'OCA\OpenRegister\Service\RetentionService')
			->backfill(schemaKeys: ['client_schema', 'contact_schema']);

		$this->assertSame(['status' => 'openregister-unavailable', 'applied' => 0, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertSame([], $this->updates);
	}//end testAnUnavailableRetentionServiceWritesNothing()

	/**
	 * Only the schemas the keys name are walked.
	 *
	 * @return void
	 */
	public function testOnlyTheNamedSchemasAreWalked(): void {
		$result = $this->service(config: self::configured())->backfill(schemaKeys: ['contact_schema']);

		$this->assertSame(['status' => 'done', 'applied' => 1, 'skipped' => 0, 'failed' => 0], $result);
		$this->assertSame(['p-1'], $this->updates);
	}//end testOnlyTheNamedSchemasAreWalked()
}//end class
