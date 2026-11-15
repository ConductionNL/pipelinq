<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Mcp;

use JsonSerializable;
use OCA\Pipelinq\Mcp\McpAnswer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

/**
 * Which failures read as forbidden, what the generic failure says and logs,
 * and the store shapes the existing tests leave out.
 *
 * @covers \OCA\Pipelinq\Mcp\McpAnswer
 */
class McpAnswerEdgeCasesTest extends TestCase {
	/**
	 * @return array<string, array{0: string}>
	 */
	public static function refusals(): array {
		return [
			'capitalised permission' => ['Permission denied'],
			'not authorized' => ['User is NOT AUTHORIZED for register 3'],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARefusalInAnyCaseIsForbiddenAndNotLogged(string $message): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$this->assertSame(
			['error' => ['code' => 'forbidden', 'message' => 'You are not allowed to access this resource.']],
			(new McpAnswer(logger: $logger))->fromException(operation: 'list leads', exception: new RuntimeException($message))
		);
	}

	public function testTheGenericFailureNamesTheOperationAndLogsTheCause(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with('Pipelinq MCP: failed to list leads', ['exception' => 'authorisation server down']);

		$this->assertSame(
			['error' => ['code' => 'internal_error', 'message' => 'Failed to list leads. See server log for details.']],
			(new McpAnswer(logger: $logger))->fromException(operation: 'list leads', exception: new RuntimeException('authorisation server down'))
		);
	}

	public function testAnEntityIsReadThroughItsObjectBeforeAnyOtherShape(): void {
		$entity = new class implements JsonSerializable {
			public string $id = 'public-field';

			public function getObject(): array {
				return ['id' => 'lead-7', 'title' => 'Acme'];
			}

			public function jsonSerialize(): array {
				return ['id' => 'serialised'];
			}
		};

		$answer = new McpAnswer(logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertSame(['id' => 'lead-7', 'title' => 'Acme'], $answer->toArray(item: $entity));
	}

	public function testAPlainObjectAndAScalarAreCast(): void {
		$plain        = new stdClass();
		$plain->id    = 'lead-1';
		$plain->value = 10;

		$answer = new McpAnswer(logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertSame(['id' => 'lead-1', 'value' => 10], $answer->toArray(item: $plain));
		$this->assertSame(['lead-1'], $answer->toArray(item: 'lead-1'));
	}
}
