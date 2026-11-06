<?php

/**
 * Tests the dispatcher double's handling of the `limit` range.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Support;

use OCP\AppFramework\Http\DataResponse;
use PHPUnit\Framework\TestCase;

/**
 * Pins the range Nextcloud 34 and 35 apply to a typed `limit` argument.
 *
 * @coversNothing
 */
class NextcloudDispatchTest extends TestCase {
	/**
	 * A target with a typed limit, a limit whose default lies outside the range and a differently named int.
	 *
	 * @return object
	 */
	private function target(): object {
		return new class {
			/**
			 * Echo a typed limit.
			 *
			 * @param int $limit The limit.
			 *
			 * @return DataResponse
			 */
			public function typed(int $limit=20): DataResponse {
				return new DataResponse(['limit' => $limit]);
			}//end typed()

			/**
			 * Echo a typed limit whose default lies above the range.
			 *
			 * @param int $limit The limit.
			 *
			 * @return DataResponse
			 */
			public function wideDefault(int $limit=1000): DataResponse {
				return new DataResponse(['limit' => $limit]);
			}//end wideDefault()

			/**
			 * Echo an int with another name and an untyped limit.
			 *
			 * @param int   $offset The offset.
			 * @param mixed $limit  The limit.
			 *
			 * @return DataResponse
			 */
			public function others(int $offset=0, mixed $limit=null): DataResponse {
				return new DataResponse(['offset' => $offset, 'limit' => $limit]);
			}//end others()
		};
	}//end target()

	/**
	 * A limit outside 1..500 gets the middleware's 400, without calling the action.
	 *
	 * @return void
	 */
	public function testLimitOutsideRangeIsRefused(): void {
		foreach (['0', '-1', '501', 'abc', 1000] as $limit) {
			$response = NextcloudDispatch::call(controller: $this->target(), method: 'typed', params: ['limit' => $limit]);

			$this->assertSame(400, $response->getStatus(), (string)$limit);
			$this->assertSame(['message' => 'Parameter limit must be between 1 and 500'], $response->getData(), (string)$limit);
		}
	}//end testLimitOutsideRangeIsRefused()

	/**
	 * The range's ends, and the default, reach the action.
	 *
	 * @return void
	 */
	public function testLimitInsideRangeReachesTheAction(): void {
		$cases = [[['limit' => '1'], 1], [['limit' => '500'], 500], [['limit' => 25], 25], [[], 20]];
		foreach ($cases as [$params, $expected]) {
			$response = NextcloudDispatch::call(controller: $this->target(), method: 'typed', params: $params);

			$this->assertSame(200, $response->getStatus());
			$this->assertSame(['limit' => $expected], $response->getData());
		}
	}//end testLimitInsideRangeReachesTheAction()

	/**
	 * A value equal to the parameter's default is exempt, also outside the range.
	 *
	 * @return void
	 */
	public function testDefaultOutsideRangeIsExempt(): void {
		foreach ([[], ['limit' => '1000']] as $params) {
			$response = NextcloudDispatch::call(controller: $this->target(), method: 'wideDefault', params: $params);

			$this->assertSame(['limit' => 1000], $response->getData());
		}
	}//end testDefaultOutsideRangeIsExempt()

	/**
	 * Only a typed argument named limit is held to the range.
	 *
	 * @return void
	 */
	public function testOtherArgumentsAreNotRanged(): void {
		$response = NextcloudDispatch::call(controller: $this->target(), method: 'others', params: ['offset' => '9999', 'limit' => '9999']);

		$this->assertSame(['offset' => 9999, 'limit' => '9999'], $response->getData());
	}//end testOtherArgumentsAreNotRanged()
}//end class
