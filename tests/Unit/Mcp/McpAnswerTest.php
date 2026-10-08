<?php

/**
 * Unit tests for McpAnswer.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Mcp;

use JsonSerializable;
use OCA\Pipelinq\Mcp\McpAnswer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the shape pipelinq's MCP tools answer in.
 */
class McpAnswerTest extends TestCase {
	/**
	 * An error carries its code and message under `error`.
	 *
	 * @return void
	 */
	public function testAnErrorNamesItsCode(): void {
		$answer = new McpAnswer($this->createMock(LoggerInterface::class));

		$this->assertSame(
			['error' => ['code' => 'invalid_arguments', 'message' => 'Required argument title is missing.']],
			$answer->error(code: 'invalid_arguments', message: 'Required argument title is missing.')
		);
	}//end testAnErrorNamesItsCode()

	/**
	 * A permission failure answers `forbidden` and says nothing further.
	 *
	 * The model must not learn from the answer whether the object exists, so
	 * the store's own message is deliberately not passed through.
	 *
	 * @return void
	 */
	public function testAPermissionFailureIsForbiddenAndLeaksNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$answer = new McpAnswer($logger);

		$result = $answer->fromException(
			operation: 'log contactmoment',
			exception: new RuntimeException('User has no permission to read object 42')
		);

		$this->assertSame('forbidden', $result['error']['code']);
		$this->assertStringNotContainsString('42', $result['error']['message']);
	}//end testAPermissionFailureIsForbiddenAndLeaksNothing()

	/**
	 * Any other failure is an internal error, and the detail goes to the log.
	 *
	 * @return void
	 */
	public function testAnyOtherFailureIsLoggedAndGeneralised(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string|\Stringable $message, array $context = []) use (&$logged): void {
				$logged[] = [(string)$message, $context];
			}
		);
		$answer = new McpAnswer($logger);

		$result = $answer->fromException(
			operation: 'log contactmoment',
			exception: new RuntimeException('the column did not exist')
		);

		$this->assertSame('internal_error', $result['error']['code']);
		$this->assertStringNotContainsString('column', $result['error']['message']);
		$this->assertCount(1, $logged);
		$this->assertSame('the column did not exist', $logged[0][1]['exception']);
	}//end testAnyOtherFailureIsLoggedAndGeneralised()

	/**
	 * Each of the shapes ObjectService answers in becomes a plain array.
	 *
	 * @return void
	 */
	public function testEveryStoreShapeBecomesAnArray(): void {
		$answer = new McpAnswer($this->createMock(LoggerInterface::class));

		$serialisable = new class implements JsonSerializable {
			/**
			 * The object's properties.
			 *
			 * @return array<string, mixed> The properties.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'lead-1'];
			}
		};

		$this->assertSame(['id' => 'lead-1'], $answer->toArray(item: ['id' => 'lead-1']));
		$this->assertSame(['id' => 'lead-1'], $answer->toArray(item: $serialisable));
		$this->assertSame([], $answer->toArray(item: null));
	}//end testEveryStoreShapeBecomesAnArray()
}//end class
