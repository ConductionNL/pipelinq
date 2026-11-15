<?php

/**
 * Behavioural tests for the ReadsSchemaRows trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair\Support
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

namespace OCA\Pipelinq\Tests\Unit\Repair\Support;

use OCA\Pipelinq\Repair\Support\ReadsSchemaRows;
use OCA\Pipelinq\Repair\Support\SchemaWriteTarget;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Rows are read without RBAC up to the batch limit; a failed read logs and is [].
 *
 * @covers \OCA\Pipelinq\Repair\Support\ReadsSchemaRows
 * @uses \OCA\Pipelinq\Repair\Support\SchemaWriteTarget
 */
class ReadsSchemaRowsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testReadAll(): void {
		$store = new class {
			/** @var array<int, mixed> */
			public array $args = [];

			/**
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         RBAC.
			 * @param bool                 $_multitenancy Multitenancy.
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac, bool $_multitenancy): array {
				if ($config['filters']['schema'] === 'broken') {
					throw new \RuntimeException('down');
				}

				$this->args = func_get_args();
				return [['id' => 'a'], 'not-a-row', ['id' => 'b']];
			}
		};
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('ReadsSchemaRowsHost: findAll failed', ['schema' => 'broken', 'error' => 'down']);
		$host = new ReadsSchemaRowsHost($logger);

		$rows = $host->read(new SchemaWriteTarget(objectService: $store, register: 'r', schema: 's', actingAdmin: null));
		$this->assertSame([['id' => 'a'], ['id' => 'b']], $rows);
		$this->assertSame([['filters' => ['register' => 'r', 'schema' => 's'], 'limit' => 7], false, false], $store->args);
		$this->assertSame([], $host->read(new SchemaWriteTarget(objectService: $store, register: 'r', schema: 'broken', actingAdmin: null)));
	}//end testReadAll()
}//end class

/**
 * A named host, so the log prefix is a predictable short class name.
 */
final class ReadsSchemaRowsHost {
	use ReadsSchemaRows;

	private const BATCH_LIMIT = 7;

	/**
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(private LoggerInterface $logger) {
	}

	/**
	 * @param SchemaWriteTarget $target The schema.
	 * @return array<int, array<string, mixed>>
	 */
	public function read(SchemaWriteTarget $target): array {
		return $this->readAll(target: $target);
	}
}//end class
