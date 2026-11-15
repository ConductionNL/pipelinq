<?php

/**
 * Behavioural tests for the FindAllAsArrays trait.
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

use OCA\Pipelinq\Util\FindAllAsArrays;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Query rows come back as arrays; a failed query logs and is empty.
 *
 * @covers \OCA\Pipelinq\Util\FindAllAsArrays
 */
class FindAllAsArraysTest extends TestCase {

	/**
	 * @return void
	 */
	public function testFindAll(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('query failed', ['exception' => 'down']);
		$host = new class($logger) {
			use FindAllAsArrays;

			/**
			 * @param LoggerInterface $logger The logger.
			 */
			public function __construct(private LoggerInterface $logger) {
			}

			/**
			 * @param array<string, mixed> $config The query.
			 * @return array<int, array<string, mixed>>
			 */
			public function find(array $config): array {
				return $this->findAllAsArrays(config: $config, failure: 'query failed');
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return new class {
					/**
					 * @param array<string, mixed> $config The query.
					 * @return array<int, mixed>|null
					 */
					public function findAll(array $config): ?array {
						if (($config['fail'] ?? false) === true) {
							throw new \RuntimeException('down');
						}

						return ($config['empty'] ?? false) === true ? null : [(object)['id' => 'a'], ['id' => 'b']];
					}
				};
			}

			/**
			 * @param mixed $value The row.
			 * @return array<string, mixed>
			 */
			private function toArray(mixed $value): array {
				return (array)$value;
			}
		};

		$this->assertSame([['id' => 'a'], ['id' => 'b']], $host->find([]));
		$this->assertSame([], $host->find(['empty' => true]));
		$this->assertSame([], $host->find(['fail' => true]));
	}//end testFindAll()
}//end class
