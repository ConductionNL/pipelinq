<?php

/**
 * Golden test for the update events of ObjectEventHandlerService::handleUpdated().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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
 *
 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ObjectEventDispatcher;
use OCA\Pipelinq\Service\ObjectEventHandlerService;
use OCA\Pipelinq\Service\ObjectUpdateDiffService;
use OCA\Pipelinq\Service\SchemaMapService;
use PHPUnit\Framework\TestCase;

/**
 * Every dispatcher call (by name, arguments by name) that an update of a
 * lead, a request, a contact or another type makes, for assignee, stage and
 * status changes, compared with ObjectUpdateEventsGolden.json. Set
 * OBJECT_UPDATE_EVENTS_GOLDEN_RECORD=1 to rewrite the file, then run once
 * more without it.
 *
 * @covers \OCA\Pipelinq\Service\ObjectEventHandlerService
 * @covers \OCA\Pipelinq\Service\ObjectUpdateDiffService
 *
 * @spec openspec/specs/crm-workflow-automation/spec.md#requirement-trigger-conditions
 */
class ObjectUpdateEventsGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ObjectUpdateEventsGolden.json';

	/**
	 * Dispatcher calls in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$base = ['title' => 'Deal', 'assignee' => 'ann', 'stage' => 'new', 'status' => 'open', 'value' => 10];
		$cases = [];
		foreach (['lead', 'request', 'contact', 'product'] as $type) {
			$cases[$type . ' unchanged'] = [$type, $base, $base];
			$cases[$type . ' reassigned'] = [$type, ['assignee' => 'bob'] + $base, $base];
			$cases[$type . ' unassigned'] = [$type, ['assignee' => ''] + $base, $base];
			$cases[$type . ' stage moved'] = [$type, ['stage' => 'proposal'] + $base, $base];
			$cases[$type . ' won'] = [$type, ['stage' => 'Closed Won', 'value' => 1250.5] + $base, $base];
			$cases[$type . ' lost'] = [$type, ['stage' => 'Verloren'] + $base, $base];
			$cases[$type . ' status moved'] = [$type, ['status' => 'done'] + $base, $base];
			$cases[$type . ' everything moved'] = [$type, ['assignee' => 'bob', 'stage' => 'gewonnen', 'status' => 'done'] + $base, $base];
		}

		$cases['lead created stage'] = ['lead', $base, null];
		$cases['lead stage cleared'] = ['lead', ['stage' => ''] + $base, $base];
		$cases['request status cleared'] = ['request', ['status' => ''] + $base, $base];
		$cases['lead translated title'] = ['lead', ['title' => ['nl' => 'Koop', 'en' => 'Buy'], 'stage' => 'won', 'value' => null] + $base, $base];
		$cases['lead won without value'] = ['lead', ['stage' => 'won'] + array_diff_key($base, ['value' => true]), $base];
		$cases['lead assignee not scalar'] = ['lead', ['assignee' => ['x'], 'stage' => 'lost'] + $base, ['assignee' => ''] + $base];
		$cases['unmapped schema'] = [null, ['assignee' => 'bob'] + $base, $base];

		$actual = [];
		foreach ($cases as $name => [$type, $new, $old]) {
			$this->calls = [];
			$oldObject = null;
			if ($old !== null) {
				$oldObject = $this->entity(data: $old);
			}

			$this->handler(type: $type)->handleUpdated(newObject: $this->entity(data: $new), oldObject: $oldObject);
			$actual[$name] = $this->calls;
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('OBJECT_UPDATE_EVENTS_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testGolden()

	/**
	 * A stored object with the given data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return object
	 */
	private function entity(array $data): object {
		return new class($data) {
			/**
			 * @param array<string, mixed> $data The object data.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * @return string
			 */
			public function getSchema(): string {
				return 'schema-1';
			}

			/**
			 * @return array<string, mixed>
			 */
			public function getObject(): array {
				return $this->data;
			}

			/**
			 * @return int
			 */
			public function getId(): int {
				return 42;
			}
		};
	}//end entity()

	/**
	 * A handler whose schema resolves to the type and whose dispatcher records every call.
	 *
	 * @param string|null $type The entity type the schema resolves to.
	 *
	 * @return ObjectEventHandlerService
	 */
	private function handler(?string $type): ObjectEventHandlerService {
		$schemas = $this->createMock(originalClassName: SchemaMapService::class);
		$schemas->method('resolveEntityType')->willReturn($type);

		$dispatcher = $this->createMock(originalClassName: ObjectEventDispatcher::class);
		$methods = [
			'dispatchAssigneeChange' => ['entityType', 'title', 'objectId', 'assignee'],
			'dispatchStageChange' => ['title', 'objectId', 'newStage', 'assignee'],
			'dispatchStatusChange' => ['title', 'objectId', 'newStatus', 'assignee'],
			'dispatchDealWon' => ['title', 'value', 'objectId', 'assignee'],
			'dispatchDealLost' => ['title', 'objectId', 'assignee'],
		];
		foreach ($methods as $method => $names) {
			$dispatcher->method($method)->willReturnCallback(
				function (...$args) use ($method, $names): void {
					$this->calls[] = [$method => array_combine($names, $args)];
				}
			);
		}

		return new ObjectEventHandlerService(schemaMapService: $schemas, dispatcher: $dispatcher, diffService: new ObjectUpdateDiffService());
	}//end handler()
}//end class
