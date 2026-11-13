<?php

/**
 * SearchQueryDailyStore: one searchQueryDaily object per property, date,
 * query and page, updated in place on a rerun, and every OpenRegister failure
 * answered as "not stored" rather than thrown.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\SearchConsole;

use JsonSerializable;
use OCA\Pipelinq\Service\SearchConsole\SearchQueryDailyStore;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Upserting one Search Console row.
 *
 * @covers \OCA\Pipelinq\Service\SearchConsole\SearchQueryDailyStore
 */
class SearchQueryDailyStoreTest extends TestCase {

	/**
	 * A Search Console row.
	 *
	 * @var array<string, mixed>
	 */
	private const ROW = ['date' => '2026-11-01', 'query' => 'vergunning', 'page' => '/aanvragen', 'clicks' => 4, 'impressions' => 90];

	/**
	 * An object-service fake that records its calls.
	 *
	 * @param mixed           $found     What findAll answers.
	 * @param \Throwable|null $findFails What findAll throws, if anything.
	 * @param \Throwable|null $saveFails What saveObject throws, if anything.
	 *
	 * @return object
	 */
	private function objectService(mixed $found = [], ?\Throwable $findFails = null, ?\Throwable $saveFails = null): object {
		return new class ($found, $findFails, $saveFails) {
			/**
			 * The calls, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed           $found     What findAll answers.
			 * @param \Throwable|null $findFails What findAll throws.
			 * @param \Throwable|null $saveFails What saveObject throws.
			 */
			public function __construct(private mixed $found, private ?\Throwable $findFails, private ?\Throwable $saveFails) {
			}//end __construct()

			/**
			 * Look up objects.
			 *
			 * @param array $config        The query.
			 * @param bool  $_rbac         RBAC flag.
			 * @param bool  $_multitenancy Multitenancy flag.
			 *
			 * @return mixed
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$this->calls[] = ['findAll' => $config, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->findFails !== null) {
					throw $this->findFails;
				}

				return $this->found;
			}//end findAll()

			/**
			 * Save an object.
			 *
			 * @param array       $object        The object.
			 * @param string      $register      The register.
			 * @param string      $schema        The schema.
			 * @param string|null $uuid          The uuid to update, or null to create.
			 * @param bool        $_rbac         RBAC flag.
			 * @param bool        $_multitenancy Multitenancy flag.
			 *
			 * @return array
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['saveObject' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->saveFails !== null) {
					throw $this->saveFails;
				}

				return $object;
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * The store over an object service, with app config answering the defaults.
	 *
	 * @param object|null          $objectService The object service, or null when OpenRegister is unavailable.
	 * @param LoggerInterface|null $logger        The logger.
	 *
	 * @return SearchQueryDailyStore
	 */
	private function store(?object $objectService, ?LoggerInterface $logger = null): SearchQueryDailyStore {
		$container = $this->createMock(ContainerInterface::class);
		if ($objectService === null) {
			$container->method('get')->willThrowException(new RuntimeException('no OpenRegister'));
		} else {
			$container->method('get')->with('OCA\\OpenRegister\\Service\\ObjectService')->willReturn($objectService);
		}

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'register') ? 'crm' : $default
		);

		return new SearchQueryDailyStore($container, $appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end store()

	/**
	 * A new row is looked up by its identity and created, unscoped, with its source and import time.
	 *
	 * @return void
	 */
	public function testANewRowIsCreated(): void {
		$objects = $this->objectService();

		self::assertTrue($this->store($objects)->upsert(property: 'sc-domain:example.nl', row: self::ROW, importedAt: '2026-11-02T03:00:00Z'));

		$identity = ['property' => 'sc-domain:example.nl', 'date' => '2026-11-01', 'query' => 'vergunning', 'page' => '/aanvragen'];
		self::assertSame(
			[
				['findAll' => ['filters' => ['register' => 'crm', 'schema' => 'searchQueryDaily'] + $identity, 'limit' => 1], 'rbac' => false, 'multitenancy' => false],
				[
					'saveObject' => $identity + self::ROW + ['source' => 'gsc', 'importedAt' => '2026-11-02T03:00:00Z'],
					'register' => 'crm',
					'schema' => 'searchQueryDaily',
					'uuid' => null,
					'rbac' => false,
					'multitenancy' => false,
				],
			],
			$objects->calls
		);
	}//end testANewRowIsCreated()

	/**
	 * The ways an existing object names its id.
	 *
	 * @return array<string, array{mixed, string|null}>
	 */
	public static function existing(): array {
		$serializable = new class implements JsonSerializable {
			/**
			 * The serialised object.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['@self' => ['id' => 'via-json']];
			}//end jsonSerialize()
		};

		return [
			'uuid'                => [[['uuid' => 'u-1', 'id' => 'i-1']], 'u-1'],
			'id only'             => [[['id' => 42]], '42'],
			'@self uuid'          => [[['@self' => ['uuid' => 's-1']]], 's-1'],
			'serialisable entity' => [[$serializable], 'via-json'],
			'empty ids'           => [[['uuid' => '', 'id' => '']], null],
			'not an array'        => [['just text'], null],
			'nothing found'       => [[], null],
			'not iterable'        => ['nothing', null],
		];
	}//end existing()

	/**
	 * A rerun updates the object it finds, under the id it reads from it.
	 *
	 * @param mixed       $found The lookup answer.
	 * @param string|null $uuid  The uuid saved under.
	 *
	 * @return void
	 */
	#[DataProvider('existing')]
	public function testAnExistingObjectIsUpdatedInPlace(mixed $found, ?string $uuid): void {
		$objects = $this->objectService(found: $found);

		self::assertTrue($this->store($objects)->upsert(property: 'p', row: self::ROW, importedAt: 'now'));
		self::assertSame($uuid, $objects->calls[1]['uuid']);
	}//end testAnExistingObjectIsUpdatedInPlace()

	/**
	 * A failed lookup still creates the row, and says so.
	 *
	 * @return void
	 */
	public function testAFailedLookupCreatesTheRow(): void {
		$objects = $this->objectService(findFails: new RuntimeException('index down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('SearchQueryDailyStore: lookup failed, creating', ['exception' => 'index down']);

		self::assertTrue($this->store($objects, $logger)->upsert(property: 'p', row: self::ROW, importedAt: 'now'));
		self::assertNull($objects->calls[1]['uuid']);
	}//end testAFailedLookupCreatesTheRow()

	/**
	 * A refused save answers false and is logged.
	 *
	 * @return void
	 */
	public function testARefusedSaveIsNotStored(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('SearchQueryDailyStore: save failed', ['exception' => 'refused']);

		self::assertFalse(
			$this->store($this->objectService(saveFails: new RuntimeException('refused')), $logger)->upsert(property: 'p', row: self::ROW, importedAt: 'now')
		);
	}//end testARefusedSaveIsNotStored()

	/**
	 * Without OpenRegister nothing is stored, and that is logged.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingIsStored(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('SearchQueryDailyStore: OpenRegister unavailable', ['exception' => 'no OpenRegister']);

		self::assertFalse($this->store(null, $logger)->upsert(property: 'p', row: self::ROW, importedAt: 'now'));
	}//end testWithoutOpenRegisterNothingIsStored()
}//end class
