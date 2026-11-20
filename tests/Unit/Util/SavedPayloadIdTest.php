<?php

/**
 * Behavioural tests for the SavedPayloadId trait.
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

use OCA\Pipelinq\Util\SavedPayloadId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * uuid beats id beats slug, top level before @self; a miss is ''.
 *
 * @covers \OCA\Pipelinq\Util\SavedPayloadId
 */
class SavedPayloadIdTest extends TestCase {

	/**
	 * Payloads and the id each yields.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function payloads(): array {
		return [
			'uuid first'               => [['slug' => 's', 'id' => 'i', 'uuid' => 'u'], 'u'],
			'id before slug'           => [['slug' => 's', 'id' => 'i'], 'i'],
			'integer id stringified'   => [['id' => 7], '7'],
			'empty uuid skipped'       => [['uuid' => '', 'slug' => 's'], 's'],
			'top level before @self'   => [['slug' => 's', '@self' => ['uuid' => 'su']], 's'],
			'@self when no top level'  => [['@self' => ['id' => 'si']], 'si'],
			'@self not an array'       => [['@self' => 'x'], ''],
			'non-scalar id skipped'    => [['id' => ['x'], '@self' => ['slug' => 'ss']], 'ss'],
			'nothing'                  => [[], ''],
		];
	}//end payloads()

	/**
	 * @param array<string, mixed> $payload  The payload.
	 * @param string               $expected The id.
	 *
	 * @return void
	 */
	#[DataProvider('payloads')]
	public function testExtractId(array $payload, string $expected): void {
		$host = new class {
			use SavedPayloadId;

			/**
			 * @param array<string, mixed> $payload The payload.
			 * @return string
			 */
			public function read(array $payload): string {
				return $this->extractId(payload: $payload);
			}
		};

		$this->assertSame($expected, $host->read(payload: $payload));
	}//end testExtractId()
}//end class
