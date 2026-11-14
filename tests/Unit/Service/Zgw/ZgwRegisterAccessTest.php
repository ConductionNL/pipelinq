<?php

/**
 * Unit tests for ZgwRegisterAccess.
 *
 * Pins the facade over OpenRegister's ObjectService: the lazy, cached
 * container lookup, the register/schema arguments handed to OR, the
 * normalisation of entities/arrays/objects to plain arrays, and the
 * null / empty answers when OR is missing or throws.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Zgw;

use OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for ZgwRegisterAccess.
 *
 * @covers \OCA\Pipelinq\Service\Zgw\ZgwRegisterAccess
 *
 * @spec openspec/specs/zgw-api-bridge/spec.md
 */
class ZgwRegisterAccessTest extends TestCase {
	/**
	 * Container id of OpenRegister's ObjectService.
	 */
	private const OBJECT_SERVICE = 'OCA\\OpenRegister\\Service\\ObjectService';

	/**
	 * Build a fake ObjectService that records its calls and answers from the supplied callables.
	 *
	 * @param callable|null $find     Answer for find().
	 * @param callable|null $findAll  Answer for findAll().
	 * @param callable|null $save     Answer for saveObject().
	 *
	 * @return object
	 */
	private function objectService(?callable $find = null, ?callable $findAll = null, ?callable $save = null): object {
		return new class($find, $findAll, $save) {
			/**
			 * Recorded calls: [method, named args].
			 *
			 * @var array<int, array{0: string, 1: array<string, mixed>}>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param callable|null $find    Answer for find().
			 * @param callable|null $findAll Answer for findAll().
			 * @param callable|null $save    Answer for saveObject().
			 */
			public function __construct(
				private $find,
				private $findAll,
				private $save,
			) {
			}

			/**
			 * Fake find().
			 *
			 * @param string $id       Id.
			 * @param string $register Register.
			 * @param string $schema   Schema.
			 *
			 * @return mixed
			 */
			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', ['id' => $id, 'register' => $register, 'schema' => $schema]];
				return ($this->find)();
			}

			/**
			 * Fake findAll().
			 *
			 * @param array<string, mixed> $config Config.
			 *
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				$this->calls[] = ['findAll', ['config' => $config]];
				return ($this->findAll)();
			}

			/**
			 * Fake saveObject().
			 *
			 * @param array<string, mixed> $object   Object.
			 * @param array<int, mixed>    $extend   Extend.
			 * @param string               $register Register.
			 * @param string               $schema   Schema.
			 * @param string|null          $uuid     Uuid.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, ?string $uuid): mixed {
				$this->calls[] = ['saveObject', ['object' => $object, 'extend' => $extend, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid]];
				return ($this->save)();
			}
		};
	}//end objectService()

	/**
	 * Build the facade over a container that hands out the given ObjectService.
	 *
	 * @param object               $objectService The fake ObjectService.
	 * @param LoggerInterface|null $logger        Optional logger mock.
	 *
	 * @return ZgwRegisterAccess
	 */
	private function access(object $objectService, ?LoggerInterface $logger = null): ZgwRegisterAccess {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(self::OBJECT_SERVICE)->willReturn($objectService);
		return new ZgwRegisterAccess($container, $logger ?? $this->createMock(LoggerInterface::class));
	}//end access()

	/**
	 * Build the facade over a container in which OpenRegister is not installed.
	 *
	 * @param LoggerInterface $logger Logger mock.
	 *
	 * @return array{0: ZgwRegisterAccess, 1: ContainerInterface}
	 */
	private function accessWithoutOpenRegister(LoggerInterface $logger): array {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())
			->method('get')
			->with(self::OBJECT_SERVICE)
			->willThrowException(new RuntimeException('no such service'));
		return [new ZgwRegisterAccess($container, $logger), $container];
	}//end accessWithoutOpenRegister()

	/**
	 * find() asks OR for the id in the pipelinq register and the given schema.
	 *
	 * @return void
	 */
	public function testFindPassesIdRegisterAndSchemaAndReturnsTheArray(): void {
		$or = $this->objectService(find: static fn (): array => ['id' => 'ep-1', 'active' => true]);

		$result = $this->access($or)->find(ZgwRegisterAccess::SCHEMA_ENDPOINT, 'ep-1');

		self::assertSame(['id' => 'ep-1', 'active' => true], $result);
		self::assertSame(
			[['find', ['id' => 'ep-1', 'register' => 'pipelinq', 'schema' => 'zgwEndpoint']]],
			$or->calls
		);
	}//end testFindPassesIdRegisterAndSchemaAndReturnsTheArray()

	/**
	 * An entity is normalised through jsonSerialize().
	 *
	 * @return void
	 */
	public function testFindSerialisesAJsonSerializableEntity(): void {
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string, string>
			 */
			public function jsonSerialize(): array {
				return ['id' => 'from-json'];
			}
		};
		$or = $this->objectService(find: static fn (): object => $entity);

		self::assertSame(['id' => 'from-json'], $this->access($or)->find(ZgwRegisterAccess::SCHEMA_CLIENT, 'x'));
	}//end testFindSerialisesAJsonSerializableEntity()

	/**
	 * jsonSerialize() that yields no array falls through to toArray().
	 *
	 * @return void
	 */
	public function testFindFallsBackToToArrayWhenJsonSerializeIsNotAnArray(): void {
		$entity = new class {
			/**
			 * Serialise to a scalar.
			 *
			 * @return string
			 */
			public function jsonSerialize(): string {
				return 'scalar';
			}

			/**
			 * Array form.
			 *
			 * @return array<string, string>
			 */
			public function toArray(): array {
				return ['id' => 'from-to-array'];
			}
		};
		$or = $this->objectService(find: static fn (): object => $entity);

		self::assertSame(['id' => 'from-to-array'], $this->access($or)->find(ZgwRegisterAccess::SCHEMA_CLIENT, 'x'));
	}//end testFindFallsBackToToArrayWhenJsonSerializeIsNotAnArray()

	/**
	 * A plain object without either method is cast to its public properties.
	 *
	 * @return void
	 */
	public function testFindCastsAPlainObject(): void {
		$plain = new \stdClass();
		$plain->id = 'plain';
		$plain->name = 'Zoetermeer';
		$or = $this->objectService(find: static fn (): object => $plain);

		self::assertSame(['id' => 'plain', 'name' => 'Zoetermeer'], $this->access($or)->find(ZgwRegisterAccess::SCHEMA_CLIENT, 'x'));
	}//end testFindCastsAPlainObject()

	/**
	 * A scalar or null from OR is reported as missing.
	 *
	 * @return void
	 */
	public function testFindReturnsNullForANonObjectNonArrayResult(): void {
		$or = $this->objectService(find: static fn (): ?string => null);

		self::assertNull($this->access($or)->find(ZgwRegisterAccess::SCHEMA_CLIENT, 'x'));
	}//end testFindReturnsNullForANonObjectNonArrayResult()

	/**
	 * A throwing find() is logged at info and answered with null.
	 *
	 * @return void
	 */
	public function testFindSwallowsAnOpenRegisterFailure(): void {
		$or = $this->objectService(find: static function (): never {
			throw new RuntimeException('object not found');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with('ZGW: find failed', ['schema' => 'zgwClient', 'id' => 'gone', 'err' => 'object not found']);

		self::assertNull($this->access($or, $logger)->find(ZgwRegisterAccess::SCHEMA_CLIENT, 'gone'));
	}//end testFindSwallowsAnOpenRegisterFailure()

	/**
	 * Without OpenRegister every read and write no-ops, and the lookup runs once.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterEveryCallNoOpsAndTheLookupIsCached(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with('ZGW: OR ObjectService unavailable', ['err' => 'no such service']);
		[$access] = $this->accessWithoutOpenRegister($logger);

		self::assertNull($access->find(ZgwRegisterAccess::SCHEMA_ENDPOINT, 'ep-1'));
		self::assertSame([], $access->findAll(ZgwRegisterAccess::SCHEMA_ENDPOINT));
		self::assertNull($access->save(ZgwRegisterAccess::SCHEMA_ENDPOINT, ['id' => 'ep-1']));
	}//end testWithoutOpenRegisterEveryCallNoOpsAndTheLookupIsCached()

	/**
	 * The ObjectService is looked up once and reused.
	 *
	 * @return void
	 */
	public function testTheObjectServiceIsResolvedOnlyOnce(): void {
		$or = $this->objectService(find: static fn (): array => ['id' => 'a']);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())->method('get')->with(self::OBJECT_SERVICE)->willReturn($or);
		$access = new ZgwRegisterAccess($container, $this->createMock(LoggerInterface::class));

		$access->find(ZgwRegisterAccess::SCHEMA_CLIENT, 'a');
		$access->find(ZgwRegisterAccess::SCHEMA_CLIENT, 'b');

		self::assertCount(2, $or->calls);
	}//end testTheObjectServiceIsResolvedOnlyOnce()

	/**
	 * findAll() puts register and schema inside config.filters, over the caller's filters.
	 *
	 * @return void
	 */
	public function testFindAllMergesRegisterAndSchemaIntoTheFilters(): void {
		$or = $this->objectService(findAll: static fn (): array => [['id' => 'a'], ['id' => 'b']]);

		$rows = $this->access($or)->findAll(
			ZgwRegisterAccess::SCHEMA_ABONN,
			['municipalityCode' => '0637', 'schema' => 'spoofed']
		);

		self::assertSame([['id' => 'a'], ['id' => 'b']], $rows);
		self::assertSame(
			[['findAll', ['config' => ['filters' => ['municipalityCode' => '0637', 'schema' => 'nrcAbonnement', 'register' => 'pipelinq']]]]],
			$or->calls
		);
	}//end testFindAllMergesRegisterAndSchemaIntoTheFilters()

	/**
	 * findAll() normalises each row and drops rows that are neither arrays nor objects.
	 *
	 * @return void
	 */
	public function testFindAllNormalisesRowsAndDropsScalars(): void {
		$entity = new class {
			/**
			 * Array form.
			 *
			 * @return array<string, string>
			 */
			public function toArray(): array {
				return ['id' => 'entity'];
			}
		};
		$or = $this->objectService(findAll: static fn (): array => [['id' => 'arr'], 'junk', $entity, null]);

		self::assertSame(
			[['id' => 'arr'], ['id' => 'entity']],
			$this->access($or)->findAll(ZgwRegisterAccess::SCHEMA_MAPPING)
		);
	}//end testFindAllNormalisesRowsAndDropsScalars()

	/**
	 * A non-array findAll() result yields an empty list.
	 *
	 * @return void
	 */
	public function testFindAllReturnsEmptyForANonArrayResult(): void {
		$or = $this->objectService(findAll: static fn (): string => 'not a list');

		self::assertSame([], $this->access($or)->findAll(ZgwRegisterAccess::SCHEMA_MAPPING));
	}//end testFindAllReturnsEmptyForANonArrayResult()

	/**
	 * A throwing findAll() is logged at info and yields an empty list.
	 *
	 * @return void
	 */
	public function testFindAllSwallowsAnOpenRegisterFailure(): void {
		$or = $this->objectService(findAll: static function (): never {
			throw new RuntimeException('Unknown named parameter $register');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('info')
			->with('ZGW: findAll failed', ['schema' => 'zgwEndpoint', 'err' => 'Unknown named parameter $register']);

		self::assertSame([], $this->access($or, $logger)->findAll(ZgwRegisterAccess::SCHEMA_ENDPOINT));
	}//end testFindAllSwallowsAnOpenRegisterFailure()

	/**
	 * save() strips the @self envelope and passes register, schema and uuid.
	 *
	 * @return void
	 */
	public function testSaveStripsSelfAndPassesTheUuid(): void {
		$or = $this->objectService(save: static fn (): array => ['id' => 'm-1', 'etag' => 'W/"2"']);

		$saved = $this->access($or)->save(
			ZgwRegisterAccess::SCHEMA_MAPPING,
			['@self' => ['uuid' => 'm-1'], 'etag' => 'W/"2"'],
			'm-1'
		);

		self::assertSame(['id' => 'm-1', 'etag' => 'W/"2"'], $saved);
		self::assertSame(
			[['saveObject', ['object' => ['etag' => 'W/"2"'], 'extend' => [], 'register' => 'pipelinq', 'schema' => 'zgwResourceMapping', 'uuid' => 'm-1']]],
			$or->calls
		);
	}//end testSaveStripsSelfAndPassesTheUuid()

	/**
	 * save() without a uuid creates (uuid null) and keeps a payload without @self intact.
	 *
	 * @return void
	 */
	public function testSaveWithoutUuidCreates(): void {
		$or = $this->objectService(save: static fn (): array => ['id' => 'new']);

		self::assertSame(['id' => 'new'], $this->access($or)->save(ZgwRegisterAccess::SCHEMA_ABONN, ['kanaal' => 'zaken']));
		self::assertSame(['kanaal' => 'zaken'], $or->calls[0][1]['object']);
		self::assertNull($or->calls[0][1]['uuid']);
	}//end testSaveWithoutUuidCreates()

	/**
	 * A throwing saveObject() is logged as a warning and answered with null.
	 *
	 * @return void
	 */
	public function testSaveSwallowsAnOpenRegisterFailureAsWarning(): void {
		$or = $this->objectService(save: static function (): never {
			throw new RuntimeException('validation failed');
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())
			->method('warning')
			->with('ZGW: saveObject failed', ['schema' => 'zgwClient', 'uuid' => 'c-1', 'err' => 'validation failed']);

		self::assertNull($this->access($or, $logger)->save(ZgwRegisterAccess::SCHEMA_CLIENT, ['id' => 'c-1'], 'c-1'));
	}//end testSaveSwallowsAnOpenRegisterFailureAsWarning()

	/**
	 * findClientForEndpoint() looks the endpoint's clientId up in the zgwClient schema.
	 *
	 * @return void
	 */
	public function testFindClientForEndpointLooksUpTheClientId(): void {
		$or = $this->objectService(find: static fn (): array => ['id' => 'zgw-client-zoetermeer']);

		$client = $this->access($or)->findClientForEndpoint(['id' => 'ep-1', 'clientId' => 'zgw-client-zoetermeer']);

		self::assertSame(['id' => 'zgw-client-zoetermeer'], $client);
		self::assertSame(
			[['find', ['id' => 'zgw-client-zoetermeer', 'register' => 'pipelinq', 'schema' => 'zgwClient']]],
			$or->calls
		);
	}//end testFindClientForEndpointLooksUpTheClientId()

	/**
	 * An endpoint without a clientId resolves to null without touching OR.
	 *
	 * @return void
	 */
	public function testFindClientForEndpointWithoutClientIdIsNull(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::never())->method('get');
		$access = new ZgwRegisterAccess($container, $this->createMock(LoggerInterface::class));

		self::assertNull($access->findClientForEndpoint(['id' => 'ep-1']));
		self::assertNull($access->findClientForEndpoint(['id' => 'ep-1', 'clientId' => '']));
	}//end testFindClientForEndpointWithoutClientIdIsNull()
}//end class
