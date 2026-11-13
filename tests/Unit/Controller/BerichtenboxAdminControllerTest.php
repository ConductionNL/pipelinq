<?php

/**
 * Unit tests for BerichtenboxAdminController.
 *
 * The retry endpoint puts a failed Berichtenbox message back in the queue;
 * the stats endpoint tallies delivery statuses and unread sent messages. The
 * tests pin the reset written on a retry, every error answer, and the tally
 * rules (unknown statuses ignored, unreadable rows skipped, unread = sent
 * without readAt).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Controller\BerichtenboxAdminController;
use OCA\Pipelinq\Util\EntityPayload;
use OCA\Pipelinq\Util\OptionalPayloadToArray;
use OCA\Pipelinq\Util\SerialisedPayload;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for BerichtenboxAdminController.
 */
#[CoversClass(BerichtenboxAdminController::class)]
#[UsesClass(OptionalPayloadToArray::class)]
#[UsesClass(EntityPayload::class)]
#[UsesClass(SerialisedPayload::class)]
class BerichtenboxAdminControllerTest extends TestCase {

	/**
	 * The object service.
	 *
	 * @var ObjectServiceInterface&MockObject
	 */
	private ObjectServiceInterface&MockObject $objects;

	/**
	 * The app config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg-1', 'berichtenboxMessage_schema' => 'sch-msg'];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->objects = $this->createMock(ObjectServiceInterface::class);
	}//end setUp()

	/**
	 * The controller under test.
	 *
	 * @return BerichtenboxAdminController
	 */
	private function controller(): BerichtenboxAdminController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($this->config[$key] ?? $default)
		);

		return new BerichtenboxAdminController($this->createMock(IRequest::class), $appConfig, $this->objects);
	}//end controller()

	/**
	 * An entity carrying the given data.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntityInterface
	 */
	private function entity(array $data): ObjectEntityInterface {
		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getObject')->willReturn($data);
		return $entity;
	}//end entity()

	/**
	 * A retry resets the retry state, re-queues the message and saves it under its own uuid.
	 *
	 * @return void
	 */
	public function testRetryRequeuesTheMessage(): void {
		$this->objects->expects($this->once())->method('find')
			->with('m-1', [], false, 'reg-1', 'sch-msg')
			->willReturn($this->entity(['uuid' => 'm-uuid', 'retryCount' => 4, 'nextRetryAt' => '2026-11-14', 'deliveryStatus' => 'failed', 'subject' => 'Aanslag']));
		$this->objects->expects($this->once())->method('saveObject')->with(
			['uuid' => 'm-uuid', 'retryCount' => 0, 'nextRetryAt' => '', 'deliveryStatus' => 'queued', 'subject' => 'Aanslag'],
			[],
			'reg-1',
			'sch-msg',
			'm-uuid'
		);

		$response = $this->controller()->retry('m-1');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(['ok' => true, 'id' => 'm-1', 'status' => 'queued'], $response->getData());
	}//end testRetryRequeuesTheMessage()

	/**
	 * A message without its own uuid is saved under the requested id.
	 *
	 * @return void
	 */
	public function testRetryFallsBackToTheRequestedId(): void {
		$this->objects->method('find')->willReturn($this->entity(['deliveryStatus' => 'failed']));
		$this->objects->expects($this->once())->method('saveObject')->with(
			$this->anything(),
			$this->anything(),
			$this->anything(),
			$this->anything(),
			'm-1'
		);

		$this->assertSame(200, $this->controller()->retry('m-1')->getStatus());
	}//end testRetryFallsBackToTheRequestedId()

	/**
	 * A missing message, or one whose read throws, is refused without a save.
	 *
	 * @return void
	 */
	public function testRetryRefusesAMissingMessage(): void {
		$this->objects->method('find')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('no such object')),
			null
		);
		$this->objects->expects($this->never())->method('saveObject');

		$thrown = $this->controller()->retry('m-1');
		$missing = $this->controller()->retry('m-1');

		$this->assertSame(404, $thrown->getStatus());
		$this->assertSame(['error' => 'Message not found: no such object'], $thrown->getData());
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['error' => 'Message not found'], $missing->getData());
	}//end testRetryRefusesAMissingMessage()

	/**
	 * A save that throws is a 500 naming the failure.
	 *
	 * @return void
	 */
	public function testRetryReportsAFailedSave(): void {
		$this->objects->method('find')->willReturn($this->entity(['uuid' => 'm-1']));
		$this->objects->method('saveObject')->willThrowException(new RuntimeException('locked'));

		$response = $this->controller()->retry('m-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Save failed: locked'], $response->getData());
	}//end testRetryReportsAFailedSave()

	/**
	 * Without a register or schema the stats are all zero and nothing is queried.
	 *
	 * @return void
	 */
	public function testStatsAreZeroWithoutConfiguration(): void {
		$this->config['berichtenboxMessage_schema'] = '';
		$this->objects->expects($this->never())->method('findAll');

		$response = $this->controller()->stats();

		$this->assertSame(
			[
				'counters' => ['queued' => 0, 'sent' => 0, 'read' => 0, 'fallback-emailed' => 0, 'failed' => 0, 'opted-out' => 0],
				'unread' => 0,
			],
			$response->getData()
		);
	}//end testStatsAreZeroWithoutConfiguration()

	/**
	 * The stats count each known status and the sent messages nobody read.
	 *
	 * @return void
	 */
	public function testStatsTallyTheDeliveryStatuses(): void {
		$this->objects->expects($this->once())->method('findAll')
			->with(['filters' => ['register' => 'reg-1', 'schema' => 'sch-msg']])
			->willReturn(
				[
					['deliveryStatus' => 'sent', 'readAt' => ''],
					['deliveryStatus' => 'sent', 'readAt' => null],
					['deliveryStatus' => 'sent'],
					['deliveryStatus' => 'sent', 'readAt' => '2026-11-10'],
					$this->entity(['deliveryStatus' => 'queued']),
					['deliveryStatus' => 'failed'],
					['deliveryStatus' => 'opted-out'],
					['deliveryStatus' => 'bounced'],
					[],
					'not a row',
				]
			);

		$response = $this->controller()->stats();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			[
				'counters' => ['queued' => 1, 'sent' => 4, 'read' => 0, 'fallback-emailed' => 0, 'failed' => 1, 'opted-out' => 1],
				'unread' => 3,
			],
			$response->getData()
		);
	}//end testStatsTallyTheDeliveryStatuses()

	/**
	 * A failing query is a 500 naming the failure.
	 *
	 * @return void
	 */
	public function testStatsReportAFailedQuery(): void {
		$this->objects->method('findAll')->willThrowException(new RuntimeException('timeout'));

		$response = $this->controller()->stats();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['error' => 'Stats query failed: timeout'], $response->getData());
	}//end testStatsReportAFailedQuery()
}//end class
