<?php

/**
 * Tests for RequestBody: the raw body, else the request params as JSON.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\RequestBody;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Under the CLI php://input is empty, so these exercise the params fallback.
 *
 * @covers \OCA\Pipelinq\Controller\RequestBody
 */
class RequestBodyTest extends TestCase {

	/**
	 * Without a raw body the params are returned as JSON.
	 *
	 * @return void
	 */
	public function testTheParamsAreTheBodyWhenThereIsNoRawInput(): void {
		$this->assertSame(
			expected: '{"name":"Acme","tags":["a","b"]}',
			actual: $this->body(params: ['name' => 'Acme', 'tags' => ['a', 'b']])->raw()
		);
	}//end testTheParamsAreTheBodyWhenThereIsNoRawInput()

	/**
	 * No params is an empty body.
	 *
	 * @return void
	 */
	public function testNoParamsIsAnEmptyBody(): void {
		$this->assertSame(expected: '', actual: $this->body(params: [])->raw());
	}//end testNoParamsIsAnEmptyBody()

	/**
	 * Params that cannot be encoded as JSON are an empty body, not a partial one.
	 *
	 * @return void
	 */
	public function testUnencodableParamsAreAnEmptyBody(): void {
		$this->assertSame(expected: '', actual: $this->body(params: ['name' => "\xB1\x31"])->raw());
	}//end testUnencodableParamsAreAnEmptyBody()

	/**
	 * A request body over the given params.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return RequestBody The subject.
	 */
	private function body(array $params): RequestBody {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new RequestBody(request: $request);
	}//end body()
}//end class
