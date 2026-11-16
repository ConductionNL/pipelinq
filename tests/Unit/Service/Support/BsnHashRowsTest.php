<?php

/**
 * Behavioural tests for the BsnHashRows trait.
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

use OCA\Pipelinq\Service\BsnValidationService;
use OCA\Pipelinq\Service\Support\BsnHashRows;
use PHPUnit\Framework\TestCase;

/**
 * Rows are looked up by the BSN's hash in the host's register and schema.
 *
 * @covers \OCA\Pipelinq\Service\Support\BsnHashRows
 * @uses \OCA\Pipelinq\Service\BsnValidationService
 */
class BsnHashRowsTest extends TestCase {

	/**
	 * @return void
	 */
	public function testLookupByHash(): void {
		$store = new class {
			/** @var array<string, mixed> */
			public array $config = [];

			/**
			 * @param array<string, mixed> $config The query.
			 * @return array<int, array<string, string>>
			 */
			public function findAll(array $config): array {
				$this->config = $config;
				return [['id' => 'row']];
			}
		};
		$host = new class($store) {
			use BsnHashRows;

			/**
			 * @param object $store The store.
			 */
			public function __construct(private object $store) {
			}

			/**
			 * @param string $bsn The BSN.
			 * @return mixed
			 */
			public function rows(string $bsn): mixed {
				return $this->rowsForBsn(rawBsn: $bsn);
			}

			/**
			 * @return array{0: string, 1: string}
			 */
			private function config(): array {
				return ['reg', 'sch'];
			}

			/**
			 * @return object
			 */
			private function getObjectService(): object {
				return $this->store;
			}
		};

		$this->assertSame([['id' => 'row']], $host->rows('111222333'));
		$this->assertSame(
			['filters' => ['bsnHash' => BsnValidationService::hash('111222333'), 'register' => 'reg', 'schema' => 'sch']],
			$store->config
		);
	}//end testLookupByHash()
}//end class
