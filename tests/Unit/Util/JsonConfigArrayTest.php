<?php

/**
 * Unit tests for the JsonConfigArray trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Util
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Util;

use OCA\Pipelinq\Util\JsonConfigArray;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A JSON array is decoded; unset or non-array JSON is empty.
 *
 * @covers \OCA\Pipelinq\Util\JsonConfigArray
 */
class JsonConfigArrayTest extends TestCase {

	/**
	 * @return void
	 */
	public function testDecodesOnlyAnArray(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ($key) {
				'list' => '[{"a":1}]',
				'scalar' => '"text"',
				default => $default,
			}
		);
		$host = new class($appConfig) {
			use JsonConfigArray;

			/**
			 * @param IAppConfig $appConfig The app config.
			 */
			public function __construct(
				private IAppConfig $appConfig,
			) {
			}

			/**
			 * @param string $key The key.
			 *
			 * @return array<mixed>
			 */
			public function read(string $key): array {
				return $this->jsonConfigArray(key: $key);
			}
		};

		$this->assertSame([['a' => 1]], $host->read('list'));
		$this->assertSame([], $host->read('scalar'));
		$this->assertSame([], $host->read('unset'));
	}//end testDecodesOnlyAnArray()
}//end class
