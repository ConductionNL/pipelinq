<?php

/**
 * Unit tests for DetractorFollowUpService and SatisfactionAggregationService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\DetractorFollowUpService;
use OCA\Pipelinq\Service\SatisfactionAggregationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the closed loop and the per-client panel.
 */
class DetractorFollowUpServiceTest extends TestCase {
	/**
	 * The configured default assignee.
	 *
	 * @var string
	 */
	private string $defaultAssignee = 'jan';

	/**
	 * Every object written.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $written = [];

	/**
	 * Build the follow-up service over doubles.
	 *
	 * @return DetractorFollowUpService The service under test.
	 */
	private function service(): DetractorFollowUpService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'task_schema' => 'sch-task',
					'surveyResponse_schema' => 'sch-response',
					DetractorFollowUpService::THRESHOLD_KEY => '2',
					DetractorFollowUpService::DEFAULT_ASSIGNEE_KEY => $this->defaultAssignee,
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->written[] = $object;

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($object);
				$entity->method('getUuid')->willReturn('task-1');

				return $entity;
			}
		);

		return new DetractorFollowUpService(
			$appConfig,
			$objectService,
			$this->createMock(LoggerInterface::class),
		);
	}//end service()

	/**
	 * An NPS of 6 or lower is a detractor; 9 and up is a promoter.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
	 */
	public function testTheNpsBandsFollowBain(): void {
		$service = $this->service();

		$this->assertSame('detractor', $service->classify(['npsScore' => 3]));
		$this->assertSame('detractor', $service->classify(['npsScore' => 6]));
		$this->assertSame('passive', $service->classify(['npsScore' => 7]));
		$this->assertSame('passive', $service->classify(['npsScore' => 8]));
		$this->assertSame('promoter', $service->classify(['npsScore' => 9]));
		$this->assertSame('promoter', $service->classify(['npsScore' => 10]));
	}//end testTheNpsBandsFollowBain()

	/**
	 * A low rating is a detractor even when the NPS answer is not.
	 *
	 * @return void
	 */
	public function testALowRatingIsADetractorOnItsOwn(): void {
		$this->assertSame(
			'detractor',
			$this->service()->classify(['npsScore' => 9, 'averageRating' => 1.5])
		);
	}//end testALowRatingIsADetractorOnItsOwn()

	/**
	 * A detractor response raises a task assigned to the client's owner.
	 *
	 * @return void
	 */
	public function testADetractorRaisesATaskForTheOwner(): void {
		$task = $this->service()->followUpTaskFor(
			response: ['npsScore' => 3, 'clientRef' => 'client-1', 'verbatim' => 'Nooit meer'],
			client: ['owner' => 'maria'],
		);

		$this->assertNotNull($task);
		$this->assertSame('maria', $task['assigneeUserId']);
		$this->assertSame('client-1', $task['clientId']);
		$this->assertSame('Nooit meer', $task['description']);
	}//end testADetractorRaisesATaskForTheOwner()

	/**
	 * An ownerless client falls back to the configured assignee.
	 *
	 * @return void
	 */
	public function testAnOwnerlessClientFallsBackToTheDefaultAssignee(): void {
		$task = $this->service()->followUpTaskFor(
			response: ['npsScore' => 2],
			client: [],
		);

		$this->assertSame('jan', $task['assigneeUserId']);
	}//end testAnOwnerlessClientFallsBackToTheDefaultAssignee()

	/**
	 * A promoter response raises nothing.
	 *
	 * @return void
	 */
	public function testAPromoterStaysSilent(): void {
		$this->assertNull(
			$this->service()->followUpTaskFor(
				response: ['npsScore' => 9, 'averageRating' => 4.5],
				client: ['owner' => 'maria'],
			)
		);
	}//end testAPromoterStaysSilent()

	/**
	 * The classification is written onto the response, and the assignee with it.
	 *
	 * The notification is a SCHEMA RULE that transitions on this field, so a
	 * response that reached `detractor` without an assignee would notify
	 * nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-detractor-closed-loop-follow-up
	 */
	public function testTheClassificationAndAssigneeAreWrittenTogether(): void {
		$processed = $this->service()->process(
			response: ['id' => 'resp-1', 'npsScore' => 3, 'clientRef' => 'client-1'],
			client: ['owner' => 'maria'],
		);

		$this->assertSame('detractor', $processed['classification']);
		$this->assertSame('maria', $processed['followUpAssignee']);
		$this->assertSame('task-1', $processed['followUpTaskRef']);
		$this->assertCount(2, $this->written, 'One task and one classified response.');
	}//end testTheClassificationAndAssigneeAreWrittenTogether()

	/**
	 * The service dispatches no notification of its own.
	 *
	 * ADR-031: the notification belongs to the schema rule, and a notification
	 * sent from app code is invisible to the engine that owns them.
	 *
	 * @return void
	 */
	public function testNoNotificationIsDispatchedImperatively(): void {
		$source = file_get_contents(__DIR__ . '/../../../lib/Service/DetractorFollowUpService.php');

		$this->assertStringNotContainsString('IManager', $source);
		$this->assertStringNotContainsString('createNotification', $source);
	}//end testNoNotificationIsDispatchedImperatively()

	/**
	 * The per-client panel reports an empty state rather than zeroes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
	 */
	public function testAClientWithNoResponsesGetsAnEmptyState(): void {
		$panel = $this->aggregation()->summarise(responses: []);

		$this->assertTrue($panel['empty']);
		$this->assertSame(0, $panel['responseCount']);
		$this->assertNull($panel['nps'], 'A client nobody scored has no NPS, and zero is a real score.');
	}//end testAClientWithNoResponsesGetsAnEmptyState()

	/**
	 * The panel reports the count, the NPS, the rating and three verbatims.
	 *
	 * @return void
	 */
	public function testThePanelReportsWhatTheClientSaid(): void {
		$now = new DateTimeImmutable('2026-09-18T10:00:00+00:00');

		$responses = [
			['npsScore' => 10, 'averageRating' => 5, 'submittedAt' => '2026-09-10T10:00:00+00:00', 'verbatim' => 'Prima'],
			['npsScore' => 9, 'averageRating' => 4, 'submittedAt' => '2026-09-11T10:00:00+00:00', 'verbatim' => 'Goed'],
			['npsScore' => 3, 'averageRating' => 2, 'submittedAt' => '2026-09-12T10:00:00+00:00', 'verbatim' => 'Slecht'],
			['npsScore' => 8, 'averageRating' => 4, 'submittedAt' => '2026-09-13T10:00:00+00:00'],
			['npsScore' => 9, 'averageRating' => 5, 'submittedAt' => '2026-09-14T10:00:00+00:00', 'verbatim' => 'Netjes'],
			['npsScore' => 7, 'averageRating' => 3, 'submittedAt' => '2026-09-15T10:00:00+00:00'],
		];

		$panel = $this->aggregation()->summarise(responses: $responses, now: $now);

		$this->assertFalse($panel['empty']);
		$this->assertSame(6, $panel['responseCount']);
		// Three promoters, one detractor, six answered: (3 - 1) / 6 = 33.3%.
		$this->assertSame(33.3, $panel['nps']);
		$this->assertCount(3, $panel['verbatims']);
		$this->assertSame('Netjes', $panel['verbatims'][0]['text'], 'Newest first.');
	}//end testThePanelReportsWhatTheClientSaid()

	/**
	 * A trend needs two windows, and says `unknown` when it has one.
	 *
	 * @return void
	 */
	public function testATrendNeedsTwoWindows(): void {
		$now = new DateTimeImmutable('2026-09-18T10:00:00+00:00');

		$oneWindow = $this->aggregation()->summarise(
			responses: [['npsScore' => 10, 'submittedAt' => '2026-09-10T10:00:00+00:00']],
			now: $now,
		);
		$this->assertSame('unknown', $oneWindow['trend'], '"flat" would state something nobody measured.');

		$twoWindows = $this->aggregation()->summarise(
			responses: [
				['npsScore' => 10, 'submittedAt' => '2026-09-10T10:00:00+00:00'],
				['npsScore' => 3, 'submittedAt' => '2026-05-10T10:00:00+00:00'],
			],
			now: $now,
		);
		$this->assertSame('up', $twoWindows['trend']);
	}//end testATrendNeedsTwoWindows()

	/**
	 * Build the aggregation service over doubles.
	 *
	 * @return SatisfactionAggregationService The service.
	 */
	private function aggregation(): SatisfactionAggregationService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		return new SatisfactionAggregationService(
			$appConfig,
			$this->createMock(ObjectServiceInterface::class),
			$this->createMock(LoggerInterface::class),
		);
	}//end aggregation()
}//end class
