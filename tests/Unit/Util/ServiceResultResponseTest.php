<?php

/**
 * Behavioural tests for the ServiceResultResponse trait.
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

use OCA\Pipelinq\Util\ServiceResultResponse;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * The 'status' key becomes the HTTP status and leaves the body.
 *
 * @covers \OCA\Pipelinq\Util\ServiceResultResponse
 */
class ServiceResultResponseTest extends TestCase {

	/**
	 * @return void
	 */
	public function testStatusMovesFromBodyToResponse(): void {
		$host = new class {
			use ServiceResultResponse;

			/**
			 * @param array<string, mixed> $result The result.
			 * @return JSONResponse
			 */
			public function answer(array $result): JSONResponse {
				return $this->respond(result: $result);
			}
		};

		$created = $host->answer(result: ['status' => 201, 'id' => 'x']);
		$this->assertSame(201, $created->getStatus());
		$this->assertSame(['id' => 'x'], $created->getData());

		$plain = $host->answer(result: ['id' => 'y']);
		$this->assertSame(200, $plain->getStatus());
		$this->assertSame(['id' => 'y'], $plain->getData());
	}//end testStatusMovesFromBodyToResponse()
}//end class
