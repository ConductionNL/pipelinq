<?php

/**
 * Behavioural tests for the OcsErrorResponse trait.
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

use OCA\Pipelinq\Util\OcsErrorResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * OCS exceptions map to 404 / 403 / 422 with their message; others to null.
 *
 * @covers \OCA\Pipelinq\Util\OcsErrorResponse
 */
class OcsErrorResponseTest extends TestCase {

	/**
	 * @return void
	 */
	public function testMapping(): void {
		$host = new class {
			use OcsErrorResponse;

			/**
			 * @param Throwable $failure The failure.
			 * @return JSONResponse|null
			 */
			public function map(Throwable $failure): ?JSONResponse {
				return $this->ocsErrorResponse(failure: $failure);
			}
		};

		foreach ([[new OCSNotFoundException('gone'), 404], [new OCSForbiddenException('no'), 403], [new OCSBadRequestException('bad'), 422]] as [$failure, $status]) {
			$response = $host->map($failure);
			$this->assertNotNull($response);
			$this->assertSame($status, $response->getStatus());
			$this->assertSame(['error' => $failure->getMessage()], $response->getData());
		}

		$this->assertNull($host->map(new \RuntimeException('other')));
	}//end testMapping()
}//end class
