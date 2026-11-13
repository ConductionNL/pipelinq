<?php

/**
 * Unit tests for BerichtenboxAdminController — paged delivery stats and
 * message retry.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
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
 *
 * @spec openspec/changes/berichtenbox-admin-stats-pagination-and-tests/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\BerichtenboxAdminController;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for BerichtenboxAdminController.
 */
class BerichtenboxAdminControllerTest extends TestCase {

	/**
	 * The zeroed counter map stats() answers with.
	 *
	 * @var array<string, int>
	 */
	private const ZERO = [
		'queued' => 0,
		'sent' => 0,
		'read' => 0,
		'fallback-emailed' => 0,
		'failed' => 0,
		'opted-out' => 0,
	];

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objectService = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The controller under test, with the given register and message schema configured.
	 *
	 * @param string $register Configured register.
	 * @param string $schema   Configured message schema.
	 *
	 * @return BerichtenboxAdminController
	 */
	private function controller(string $register = 'reg-1', string $schema = 'msg-schema'): BerichtenboxAdminController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => match ($key) {
				'register' => $register,
				'berichtenboxMessage_schema' => $schema,
				default => $default,
			}
		);

		return new BerichtenboxAdminController(
			$this->createMock(IRequest::class),
			$appConfig,
			$this->objectService
		);
	}//end controller()

	/**
	 * A message entity double serialising to the given data.
	 *
	 * @param array<string, mixed> $data The message data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function message(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('jsonSerialize')->willReturn($data);

		return $entity;
	}//end message()

	/**
	 * A page of messages all in the given status.
	 *
	 * @param int                  $size   Rows on the page.
	 * @param array<string, mixed> $fields The fields of every row.
	 *
	 * @return array<int, ObjectEntityInterface>
	 */
	private function page(int $size, array $fields): array {
		$rows = [];
		for ($i = 0; $i < $size; $i++) {
			$rows[] = $this->message($fields);
		}

		return $rows;
	}//end page()

	/**
	 * Without a configured register or schema the zeroed counters are answered
	 * and nothing is queried.
	 *
	 * @return void
	 */
	public function testStatsWithoutConfigurationAnswersZeroesWithoutAQuery(): void {
		$this->objectService->expects(self::never())->method('findAll');

		foreach ([['', 'msg-schema'], ['reg-1', '']] as [$register, $schema]) {
			$response = $this->controller(register: $register, schema: $schema)->stats();

			self::assertSame(Http::STATUS_OK, $response->getStatus());
			self::assertSame(['counters' => self::ZERO, 'unread' => 0], $response->getData());
		}
	}//end testStatsWithoutConfigurationAnswersZeroesWithoutAQuery()

	/**
	 * A single short page is tallied per status; unread counts sent messages without readAt.
	 *
	 * @return void
	 */
	public function testStatsTalliesOneShortPage(): void {
		$rows = [
			$this->message(['deliveryStatus' => 'queued']),
			$this->message(['deliveryStatus' => 'sent']),
			$this->message(['deliveryStatus' => 'sent', 'readAt' => null]),
			$this->message(['deliveryStatus' => 'sent', 'readAt' => '2026-11-01T10:00:00Z']),
			$this->message(['deliveryStatus' => 'read', 'readAt' => '']),
			$this->message(['deliveryStatus' => 'failed']),
			$this->message(['deliveryStatus' => 'opted-out']),
			$this->message(['deliveryStatus' => 'fallback-emailed']),
			$this->message(['deliveryStatus' => 'bogus']),
			$this->message([]),
		];
		$this->objectService->expects(self::once())->method('findAll')
			->with(
				[
					'filters' => ['register' => 'reg-1', 'schema' => 'msg-schema'],
					'limit' => 500,
					'offset' => 0,
				]
			)
			->willReturn($rows);

		$response = $this->controller()->stats();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			[
				'counters' => [
					'queued' => 1,
					'sent' => 3,
					'read' => 1,
					'fallback-emailed' => 1,
					'failed' => 1,
					'opted-out' => 1,
				],
				'unread' => 2,
			],
			$response->getData()
		);
	}//end testStatsTalliesOneShortPage()

	/**
	 * A register larger than one page is read in bounded pages, each with a
	 * limit and the next offset, until a short page; the totals span all pages.
	 *
	 * @return void
	 */
	public function testStatsWalksBoundedPagesUntilAShortPage(): void {
		$pages = [
			0 => $this->page(size: 500, fields: ['deliveryStatus' => 'sent']),
			500 => $this->page(size: 500, fields: ['deliveryStatus' => 'read', 'readAt' => '2026-11-01T10:00:00Z']),
			1000 => $this->page(size: 3, fields: ['deliveryStatus' => 'failed']),
		];
		$calls = [];
		$this->objectService->expects(self::exactly(3))->method('findAll')->willReturnCallback(
			static function (array $config) use (&$calls, $pages): array {
				$calls[] = $config;

				return $pages[$config['offset']];
			}
		);

		$response = $this->controller()->stats();

		self::assertSame(
			[
				'counters' => array_replace(self::ZERO, ['sent' => 500, 'read' => 500, 'failed' => 3]),
				'unread' => 500,
			],
			$response->getData()
		);
		self::assertSame([0, 500, 1000], array_column($calls, 'offset'));
		self::assertSame([500, 500, 500], array_column($calls, 'limit'));
		foreach ($calls as $config) {
			self::assertSame(['register' => 'reg-1', 'schema' => 'msg-schema'], $config['filters']);
		}
	}//end testStatsWalksBoundedPagesUntilAShortPage()

	/**
	 * A register holding exactly one full page ends on the empty page after it.
	 *
	 * @return void
	 */
	public function testStatsStopsOnTheEmptyPageAfterAFullOne(): void {
		$this->objectService->expects(self::exactly(2))->method('findAll')->willReturnCallback(
			fn (array $config): array => $config['offset'] === 0 ? $this->page(size: 500, fields: ['deliveryStatus' => 'queued']) : []
		);

		$response = $this->controller()->stats();

		self::assertSame(['counters' => array_replace(self::ZERO, ['queued' => 500]), 'unread' => 0], $response->getData());
	}//end testStatsStopsOnTheEmptyPageAfterAFullOne()

	/**
	 * A failing query is a 500 with an error message.
	 *
	 * @return void
	 */
	public function testStatsQueryFailureIsA500(): void {
		$this->objectService->method('findAll')->willThrowException(new RuntimeException('db down'));

		$response = $this->controller()->stats();

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Stats query failed: db down'], $response->getData());
	}//end testStatsQueryFailureIsA500()

	/**
	 * Retry resets the retry state, re-queues the message and saves it under its uuid.
	 *
	 * @return void
	 */
	public function testRetryRequeuesTheMessage(): void {
		$lookups = [];
		$this->objectService->expects(self::once())->method('find')->willReturnCallback(
			function (int|string $id, ?array $extend, bool $files, string|int|null $register, string|int|null $schema) use (&$lookups): ObjectEntityInterface {
				$lookups[] = [$id, $register, $schema];

				return $this->message(
					[
						'uuid' => 'uuid-1',
						'deliveryStatus' => 'failed',
						'retryCount' => 4,
						'nextRetryAt' => '2026-11-14T00:00:00Z',
						'subject' => 'Uw aanvraag',
					]
				);
			}
		);
		$saves = [];
		$this->objectService->expects(self::once())->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend, string|int|null $register, string|int|null $schema, ?string $uuid) use (&$saves): ObjectEntityInterface {
				$saves[] = [$object, $extend, $register, $schema, $uuid];

				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$response = $this->controller()->retry(id: 'msg-1');

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(['ok' => true, 'id' => 'msg-1', 'status' => 'queued'], $response->getData());
		self::assertSame([['msg-1', 'reg-1', 'msg-schema']], $lookups);
		self::assertSame(
			[
				[
					[
						'uuid' => 'uuid-1',
						'deliveryStatus' => 'queued',
						'retryCount' => 0,
						'nextRetryAt' => '',
						'subject' => 'Uw aanvraag',
					],
					[],
					'reg-1',
					'msg-schema',
					'uuid-1',
				],
			],
			$saves
		);
	}//end testRetryRequeuesTheMessage()

	/**
	 * A failing save is a 500 with an error message.
	 *
	 * @return void
	 */
	public function testRetrySaveFailureIsA500(): void {
		$this->objectService->method('find')->willReturn($this->message(['deliveryStatus' => 'failed']));
		$this->objectService->expects(self::once())->method('saveObject')
			->willThrowException(new RuntimeException('locked'));

		$response = $this->controller()->retry(id: 'msg-1');

		self::assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		self::assertSame(['error' => 'Save failed: locked'], $response->getData());
	}//end testRetrySaveFailureIsA500()

	/**
	 * A message that cannot be found is a 404 and nothing is saved.
	 *
	 * @return void
	 */
	public function testRetryOfAnUnknownMessageIsA404(): void {
		$this->objectService->method('find')->willReturn(null);
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->retry(id: 'missing');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Message not found'], $response->getData());
	}//end testRetryOfAnUnknownMessageIsA404()

	/**
	 * A failing lookup is a 404 carrying the cause, and nothing is saved.
	 *
	 * @return void
	 */
	public function testRetryLookupFailureIsA404(): void {
		$this->objectService->method('find')->willThrowException(new RuntimeException('gone'));
		$this->objectService->expects(self::never())->method('saveObject');

		$response = $this->controller()->retry(id: 'msg-1');

		self::assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		self::assertSame(['error' => 'Message not found: gone'], $response->getData());
	}//end testRetryLookupFailureIsA404()
}//end class
