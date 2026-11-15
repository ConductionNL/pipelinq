<?php

/**
 * Unit tests for the edge cases of the API contract middleware.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Middleware
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Middleware;

use OCA\Pipelinq\Middleware\ApiContractMiddleware;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Request URIs without the path info, subdirectory installs, success bodies,
 * the detail precedence and the title of every error status.
 *
 * @covers \OCA\Pipelinq\Middleware\ApiContractMiddleware
 * @uses   \OCA\Pipelinq\Middleware\Support\ApiPathAliases
 * @uses   \OCA\Pipelinq\Middleware\Support\StandardProtocolPaths
 */
class ApiContractMiddlewareEdgeCasesTest extends TestCase {

	/**
	 * A versioned API path, which is not deprecated.
	 *
	 * @var string
	 */
	private const V1 = '/apps/pipelinq/api/v1/leads';

	/**
	 * Run the middleware for a request.
	 *
	 * @param mixed    $path     The request path info.
	 * @param string   $uri      The request URI.
	 * @param Response $response The controller response.
	 *
	 * @return Response The decorated response.
	 */
	private function decorate(mixed $path, string $uri, Response $response): Response {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getPathInfo')->willReturn($path);
		$request->method('getRequestUri')->willReturn($uri);
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getAppVersion')->with('pipelinq')->willReturn('4.5.6');

		return (new ApiContractMiddleware(request: $request, appManager: $appManager))
			->afterController(controller: null, methodName: 'any', response: $response);
	}//end decorate()

	/**
	 * Request URIs and the successor link they announce.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function links(): array {
		return [
			'pretty url'             => ['/apps/pipelinq/api/leads', '</apps/pipelinq/api/v1/leads>; rel="successor-version"'],
			'subdirectory install'   => [
				'/cloud/index.php/apps/pipelinq/api/leads?x=1',
				'</cloud/index.php/apps/pipelinq/api/v1/leads>; rel="successor-version"',
			],
			'uri without path info'  => ['/rewritten/elsewhere', '</apps/pipelinq/api/v1/leads>; rel="successor-version"'],
		];
	}//end links()

	/**
	 * The successor link keeps the front controller the request URI carries, and none when it carries none.
	 *
	 * @param string $uri  The request URI.
	 * @param string $link The expected Link header.
	 *
	 * @return void
	 */
	#[DataProvider('links')]
	public function testTheSuccessorLinkFollowsTheRequestUri(string $uri, string $link): void {
		$headers = $this->headersOf(response: $this->decorate(path: '/apps/pipelinq/api/leads', uri: $uri, response: new Response()));

		$this->assertSame(expected: $link, actual: $headers['Link']);
		$this->assertSame(expected: '@1794441600', actual: $headers['Deprecation']);
		$this->assertSame(expected: '4.5.6', actual: $headers['API-Version']);
	}//end testTheSuccessorLinkFollowsTheRequestUri()

	/**
	 * A request without path info is not an API request.
	 *
	 * @return void
	 */
	public function testARequestWithoutPathInfoIsUntouched(): void {
		$response = $this->decorate(path: false, uri: '/apps/pipelinq/api/leads', response: new JSONResponse(data: ['error' => 'x'], statusCode: 404));

		$this->assertSame(expected: ['error' => 'x'], actual: $response->getData());
		$this->assertArrayNotHasKey(key: 'API-Version', array: $this->headersOf(response: $response));
	}//end testARequestWithoutPathInfoIsUntouched()

	/**
	 * A redirect or other status below 400 keeps its body and content type.
	 *
	 * @return void
	 */
	public function testAStatusBelowFourHundredIsNotAProblem(): void {
		$response = $this->decorate(path: self::V1, uri: self::V1, response: new JSONResponse(data: ['error' => 'moved'], statusCode: 399));

		$this->assertSame(expected: ['error' => 'moved'], actual: $response->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $response)['Content-Type']);
	}//end testAStatusBelowFourHundredIsNotAProblem()

	/**
	 * Error bodies and the detail they get.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string|null}>
	 */
	public static function details(): array {
		return [
			'own detail kept'         => [['detail' => 'd', 'message' => 'm', 'error' => 'e'], 'd'],
			'message before error'    => [['message' => 'm', 'error' => 'e'], 'm'],
			'empty message skipped'   => [['message' => '', 'error' => 'e'], 'e'],
			'non-string skipped'      => [['message' => ['m'], 'error' => 'e'], 'e'],
			'no text'                 => [['code' => 7], null],
		];
	}//end details()

	/**
	 * The detail is the first non-empty string of `detail`, `message` and `error`,
	 * and the body keeps its members after the problem members.
	 *
	 * @param array<string,mixed> $body   The error body.
	 * @param string|null         $detail The expected detail.
	 *
	 * @return void
	 */
	#[DataProvider('details')]
	public function testTheDetailOfAnErrorBody(array $body, ?string $detail): void {
		$response = $this->decorate(path: self::V1, uri: self::V1, response: new JSONResponse(data: $body, statusCode: 422));
		$data     = $response->getData();

		$expected = ['type' => 'about:blank', 'title' => 'Unprocessable Content', 'status' => 422];
		if ($detail !== null) {
			$expected['detail'] = $detail;
		}

		$this->assertSame(expected: array_merge($expected, $body), actual: $data);
		$this->assertSame(expected: ApiContractMiddleware::PROBLEM_CONTENT_TYPE, actual: $this->headersOf(response: $response)['Content-Type']);
	}//end testTheDetailOfAnErrorBody()

	/**
	 * Error statuses and their title.
	 *
	 * @return array<string,array{0:int,1:string}>
	 */
	public static function titles(): array {
		return [
			'400' => [400, 'Bad Request'],
			'401' => [401, 'Unauthorized'],
			'403' => [403, 'Forbidden'],
			'409' => [409, 'Conflict'],
			'413' => [413, 'Content Too Large'],
			'429' => [429, 'Too Many Requests'],
			'500' => [500, 'Internal Server Error'],
			'503' => [503, 'Service Unavailable'],
			'418' => [418, 'Error'],
			'599' => [599, 'Error'],
		];
	}//end titles()

	/**
	 * Each error status gets its reason phrase as title, and `Error` when it has none here.
	 *
	 * @param int    $status The status.
	 * @param string $title  The expected title.
	 *
	 * @return void
	 */
	#[DataProvider('titles')]
	public function testTheTitleOfAnErrorStatus(int $status, string $title): void {
		$response = $this->decorate(path: self::V1, uri: self::V1, response: new JSONResponse(data: [], statusCode: $status));

		$this->assertSame(expected: ['type' => 'about:blank', 'title' => $title, 'status' => $status], actual: $response->getData());
	}//end testTheTitleOfAnErrorStatus()

	/**
	 * A body that already carries the same problem members is completed, not refused.
	 *
	 * @return void
	 */
	public function testMatchingProblemMembersAreKept(): void {
		$body     = ['status' => 404, 'type' => 'about:blank', 'error' => 'No lead'];
		$response = $this->decorate(path: self::V1.'/1', uri: self::V1.'/1', response: new JSONResponse(data: $body, statusCode: 404));

		$this->assertSame(
			expected: ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => 'No lead', 'error' => 'No lead'],
			actual: $response->getData()
		);
	}//end testMatchingProblemMembersAreKept()

	/**
	 * A status member of another type than the response status is a conflict,
	 * so the body is left alone.
	 *
	 * @return void
	 */
	public function testAStatusMemberOfAnotherTypeIsAConflict(): void {
		$body     = ['status' => '404', 'error' => 'No lead'];
		$response = $this->decorate(path: self::V1.'/1', uri: self::V1.'/1', response: new JSONResponse(data: $body, statusCode: 404));

		$this->assertSame(expected: $body, actual: $response->getData());
		$this->assertSame(expected: 'application/json; charset=utf-8', actual: $this->headersOf(response: $response)['Content-Type']);
	}//end testAStatusMemberOfAnotherTypeIsAConflict()

	/**
	 * The headers a response set, read without the server container.
	 *
	 * @param Response $response The response.
	 *
	 * @return array<string,string> The headers by name.
	 */
	private function headersOf(Response $response): array {
		$property = new ReflectionProperty(Response::class, 'headers');

		return $property->getValue($response);
	}//end headersOf()
}//end class
