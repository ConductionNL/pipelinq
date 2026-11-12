<?php

/**
 * Unit tests for the Search Console PropertyEndpoint.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\SearchConsole
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\SearchConsole;

use OCA\Pipelinq\Service\SearchConsole\PropertyEndpoint;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the request, the rows returned and every refusal of the API answer.
 *
 * @covers \OCA\Pipelinq\Service\SearchConsole\PropertyEndpoint
 */
class PropertyEndpointTest extends TestCase {

	/**
	 * An endpoint whose client answers the given body.
	 *
	 * @param string $body The response body.
	 * @param array  $sent Receives the posted url and options.
	 *
	 * @return PropertyEndpoint The endpoint.
	 */
	private function endpoint(string $body, array &$sent = []): PropertyEndpoint {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(
			static function (string $url, array $options) use ($response, &$sent): IResponse {
				$sent = [$url, $options];
				return $response;
			}
		);

		return new PropertyEndpoint($client, 'https://searchconsole.example/query');
	}//end endpoint()

	/**
	 * The options are posted to the endpoint url and the rows come back.
	 *
	 * @return void
	 */
	public function testPostsTheOptionsAndReturnsTheRows(): void {
		$sent = [];
		$rows = $this->endpoint('{"rows":[{"keys":["q"],"clicks":3}]}', $sent)->page(['json' => ['startRow' => 0]]);

		$this->assertSame([['keys' => ['q'], 'clicks' => 3]], $rows);
		$this->assertSame(['https://searchconsole.example/query', ['json' => ['startRow' => 0]]], $sent);
	}//end testPostsTheOptionsAndReturnsTheRows()

	/**
	 * An answer without rows, or with rows that are not a list, is an empty page.
	 *
	 * @return void
	 */
	public function testMissingOrMalformedRowsAreAnEmptyPage(): void {
		$this->assertSame([], $this->endpoint('{"responseAggregationType":"byPage"}')->page([]));
		$this->assertSame([], $this->endpoint('{"rows":"none"}')->page([]));
	}//end testMissingOrMalformedRowsAreAnEmptyPage()

	/**
	 * A body that is not JSON is refused.
	 *
	 * @return void
	 */
	public function testANonJsonAnswerIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Search Console: the API answered with something that is not JSON');

		$this->endpoint('<html>')->page([]);
	}//end testANonJsonAnswerIsRefused()

	/**
	 * An error object is refused with its message.
	 *
	 * @return void
	 */
	public function testAnErrorObjectIsRefusedWithItsMessage(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Search Console: User does not have sufficient permission');

		$this->endpoint('{"error":{"code":403,"message":"User does not have sufficient permission"}}')->page([]);
	}//end testAnErrorObjectIsRefusedWithItsMessage()

	/**
	 * An error without a message, or a plain error string, is refused too.
	 *
	 * @return void
	 */
	public function testOtherErrorShapesAreRefused(): void {
		foreach (['{"error":{"code":500}}' => 'Search Console: error', '{"error":"invalid_grant"}' => 'Search Console: invalid_grant'] as $body => $message) {
			try {
				$this->endpoint($body)->page([]);
				$this->fail('Expected a refusal for '.$body);
			} catch (RuntimeException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testOtherErrorShapesAreRefused()
}//end class
