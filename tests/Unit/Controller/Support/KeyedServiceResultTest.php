<?php

/**
 * Behavioural tests for the KeyedServiceResult trait.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller\Support
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

namespace OCA\Pipelinq\Tests\Unit\Controller\Support;

use OCA\Pipelinq\Controller\Support\KeyedServiceResult;
use OCP\AppFramework\Http\JSONResponse;
use PHPUnit\Framework\TestCase;

/**
 * 'Not found' is a 404, another error a 400, otherwise the keyed record.
 *
 * @covers \OCA\Pipelinq\Controller\Support\KeyedServiceResult
 */
class KeyedServiceResultTest extends TestCase {

	/**
	 * @return void
	 */
	public function testStatuses(): void {
		$host = new class {
			use KeyedServiceResult;

			/**
			 * @param array<string, mixed> $result The result.
			 *
			 * @return JSONResponse
			 */
			public function map(array $result): JSONResponse {
				return $this->keyedResult(result: $result, key: 'list');
			}
		};

		$this->assertSame(404, $host->map(['error' => 'Not found'])->getStatus());
		$bad = $host->map(['error' => 'Name is required']);
		$this->assertSame([400, ['error' => 'Name is required']], [$bad->getStatus(), $bad->getData()]);
		$ok = $host->map(['list' => ['id' => 'l1']]);
		$this->assertSame([200, ['list' => ['id' => 'l1']]], [$ok->getStatus(), $ok->getData()]);
		$this->assertSame(['list' => null], $host->map([])->getData());
	}//end testStatuses()
}//end class
