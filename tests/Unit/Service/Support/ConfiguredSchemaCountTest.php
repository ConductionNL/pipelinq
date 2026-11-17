<?php

/**
 * Behavioural tests for the ConfiguredSchemaCount trait.
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

namespace OCA\Pipelinq\Tests\Unit\Service\Support;

use OCA\Pipelinq\Service\Support\ConfiguredSchemaCount;
use PHPUnit\Framework\TestCase;

/**
 * Matching rows are counted; unconfigured or failing reads count 0.
 *
 * @covers \OCA\Pipelinq\Service\Support\ConfiguredSchemaCount
 */
class ConfiguredSchemaCountTest extends TestCase {

	/**
	 * Build a host whose store answers the given rows.
	 *
	 * @param mixed  $rows   The rows findAll answers, or an exception to throw.
	 * @param string $schema The configured schema id.
	 *
	 * @return object The host.
	 */
	private function host(mixed $rows, string $schema = 's'): object {
		$store = new class($rows) {
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

		return new class($store, $schema) {
			use ConfiguredSchemaCount;

			/**
			 * @param object $store  The store.
			 * @param string $schema The schema id.
			 */
			public function __construct(public object $store, private string $schema) {
			}

			/**
			 * @param array<string, mixed> $filters The filters.
			 * @return int
			 */
			public function count(array $filters): int {
				return $this->countMatching(schemaKey: 'thing_schema', filters: $filters);
			}

			/**
			 * @param string $schemaKey The key.
			 * @return array{0: string, 1: string}
			 */
			private function config(string $schemaKey): array {
				return ['r', $this->schema];
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return $this->store;
			}
		};
	}//end host()

	/**
	 * @return void
	 */
	public function testCount(): void {
		$host = $this->host([['a'], ['b']]);
		$this->assertSame(2, $host->count(['programmeId' => 'p1']));
		$this->assertSame(
			['filters' => ['programmeId' => 'p1', 'register' => 'r', 'schema' => 's'], 'limit' => 1000],
			$host->store->config
		);
		$this->assertSame(0, $this->host([['a']], '')->count([]));
		$this->assertSame(0, $this->host(null)->count([]));
		$this->assertSame(0, $this->host(new \RuntimeException('down'))->count([]));
	}//end testCount()
}//end class
