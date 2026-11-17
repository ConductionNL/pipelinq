<?php

/**
 * Unit tests for RequestFields.
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

use OCA\Pipelinq\Controller\Support\RequestFields;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Absent params stay absent; strings are cast, arrays kept only when arrays.
 *
 * @covers \OCA\Pipelinq\Controller\Support\RequestFields
 */
class RequestFieldsTest extends TestCase {

	/**
	 * The fields of a request carrying the given params.
	 *
	 * @param array<string, mixed> $params The params.
	 *
	 * @return RequestFields
	 */
	private function fields(array $params): RequestFields {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key) => ($params[$key] ?? null)
		);

		return new RequestFields(request: $request);
	}//end fields()

	/**
	 * @return void
	 */
	public function testStrings(): void {
		$fields = $this->fields(['name' => 'N', 'count' => 3, 'empty' => '']);

		$this->assertSame(
			['name' => 'N', 'count' => '3', 'empty' => ''],
			$fields->strings(keys: ['name', 'missing', 'count', 'empty'])
		);
	}//end testStrings()

	/**
	 * @return void
	 */
	public function testArrays(): void {
		$fields = $this->fields(['tags' => ['a'], 'links' => 'not-a-list']);

		$this->assertSame(['tags' => ['a']], $fields->arrays(keys: ['links', 'tags', 'missing']));
	}//end testArrays()
}//end class
