<?php

/**
 * Behavioural tests for the SchemaRowQuery trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\SchemaRowArrays;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Filters go ahead of the register and schema; failures log and are null.
 *
 * @covers \OCA\Pipelinq\Util\SchemaRowQuery
 * @covers \OCA\Pipelinq\Util\SchemaRowArrays
 */
class SchemaRowQueryTest extends TestCase {

	/**
	 * A fake object service answering the given rows, or throwing.
	 *
	 * @param mixed $rows The rows, or a Throwable to throw.
	 *
	 * @return object The fake.
	 */
	public static function store(mixed $rows): object {
		return new class($rows) {
			/** @var array<string, mixed> */
			public array $config = [];

			/**
			 * @param mixed $rows The rows.
			 */
			public function __construct(private mixed $rows) {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 * @return mixed
			 */
			public function findAll(array $config): mixed {
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				$this->config = $config;
				return $this->rows;
			}
		};
	}//end store()

	/**
	 * Build a host over the given store and logger.
	 *
	 * @param object|null     $store  The object service.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return SchemaRowQueryHost The host.
	 */
	public static function host(?object $store, LoggerInterface $logger): SchemaRowQueryHost {
		return new SchemaRowQueryHost($store, $logger);
	}//end host()

	/**
	 * @return void
	 */
	public function testFindSchemaRows(): void {
		$quiet = $this->createMock(LoggerInterface::class);
		$quiet->expects($this->never())->method('warning');
		$store = self::store([['id' => 'a']]);

		$this->assertSame([['id' => 'a']], self::host($store, $quiet)->find($store, ['kind' => 'sms']));
		$this->assertSame(['filters' => ['kind' => 'sms', 'register' => 'reg', 'schema' => 'sch']], $store->config);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('read failed', ['kind' => 'sms', 'exception' => 'down']);
		$failing = self::store(new \RuntimeException('down'));
		$this->assertNull(self::host($failing, $logger)->find($failing, ['kind' => 'sms']));
	}//end testFindSchemaRows()
}//end class

/**
 * A host of both traits over a fixed register and schema.
 */
final class SchemaRowQueryHost {
	use SchemaRowArrays;

	/**
	 * @param object|null     $store  The object service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(private ?object $store, private LoggerInterface $logger) {
	}

	/**
	 * @param object               $store   The object service.
	 * @param array<string, mixed> $filters The filters.
	 * @return mixed
	 */
	public function find(object $store, array $filters): mixed {
		return $this->findSchemaRows(objectService: $store, filters: $filters, failure: 'read failed', context: $filters);
	}

	/**
	 * @param array<string, mixed> $filters The filters.
	 * @return array<int, array<string, mixed>>
	 */
	public function arrays(array $filters): array {
		return $this->schemaRowArrays(filters: $filters, failure: 'read failed', context: []);
	}

	/**
	 * @return string
	 */
	private function getRegisterSlug(): string {
		return 'reg';
	}

	/**
	 * @return string
	 */
	private function getSchemaSlug(): string {
		return 'sch';
	}

	/**
	 * @return object|null
	 */
	private function getObjectService(): ?object {
		return $this->store;
	}

	/**
	 * @param mixed $value The row.
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		return (array)$value;
	}
}//end class
