<?php

/**
 * Behavioural tests for the RegisterSchemaFindAll trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Support
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

use OCA\Pipelinq\Service\Support\RegisterSchemaFindAll;
use PHPUnit\Framework\TestCase;

/**
 * The findAll config built from the ids and extra filters.
 *
 * @covers \OCA\Pipelinq\Service\Support\RegisterSchemaFindAll
 */
class RegisterSchemaFindAllTest extends TestCase {

	/**
	 * @return void
	 */
	public function testFindAllIn(): void {
		$store = new class {
			/** @var array<int, array<string, mixed>> */
			public array $configs = [];

			/**
			 * @param array<string, mixed> $config The config.
			 * @return array<int, string>
			 */
			public function findAll(array $config): array {
				$this->configs[] = $config;
				return ['row'];
			}
		};
		$host = new class($store) {
			use RegisterSchemaFindAll;

			/**
			 * @param object $store The object service.
			 */
			public function __construct(private object $store) {
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return $this->store;
			}

			/**
			 * @param array<int, string>   $ids     The ids.
			 * @param array<string, mixed> $filters The filters.
			 * @return mixed
			 */
			public function run(array $ids, array $filters = []): mixed {
				return $this->findAllIn(ids: $ids, filters: $filters);
			}
		};

		$this->assertSame(['row'], $host->run(['r', 's']));
		$host->run(['r', 's'], ['shift' => 'x', 'schema' => 'override']);
		$this->assertSame(
			[
				['filters' => ['register' => 'r', 'schema' => 's']],
				['filters' => ['register' => 'r', 'schema' => 'override', 'shift' => 'x']],
			],
			$store->configs
		);
	}//end testFindAllIn()
}//end class
