<?php

/**
 * JourneyStepRunner: audiences, tasks and the ledger's failure modes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\Marketing\JourneyService;
use OCA\Pipelinq\Service\Marketing\JourneyStepRunner;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\SegmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who a journey step reaches, how a task step is written, and how a mailing
 * fails when its ledger blast or delivery cannot be stored.
 *
 * @covers \OCA\Pipelinq\Service\Marketing\JourneyStepRunner
 * @uses   \OCA\Pipelinq\Service\Marketing\JourneyStep
 * @uses   \OCA\Pipelinq\Service\Marketing\JourneyMailing
 */
class JourneyStepRunnerEdgesTest extends TestCase {

	/**
	 * Rows per schema, keyed by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Schemas whose save fails.
	 *
	 * @var array<int, string>
	 */
	private array $failingSaves = [];

	/**
	 * Saves seen, as [schema, payload].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $saved = [];

	/**
	 * Runs recorded through the journey service.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $runs = [];

	/**
	 * Journey service mock.
	 *
	 * @var JourneyService&MockObject
	 */
	private JourneyService&MockObject $journeys;

	/**
	 * Segment service mock.
	 *
	 * @var SegmentService&MockObject
	 */
	private SegmentService&MockObject $segments;

	/**
	 * Seed the contacts and the template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rows = [
			'contact' => [
				'c-1' => ['uuid' => 'c-1', 'email' => 'one@example.org', 'client' => 'client-1'],
				'c-2' => ['uuid' => 'c-2', 'email' => 'two@example.org', 'client' => 'client-1'],
				'c-3' => ['uuid' => 'c-3', 'email' => 'three@example.org', 'client' => ' client-2 '],
			],
			'campaignTemplate' => ['tpl-1' => ['uuid' => 'tpl-1']],
		];
		$this->journeys = $this->createMock(JourneyService::class);
		$this->journeys->method('recordRun')->willReturnCallback(
			function (array $run): array {
				$this->runs[] = $run;
				return $run;
			}
		);
		$this->segments = $this->createMock(SegmentService::class);
	}//end setUp()

	/**
	 * A runner over an array-backed store and the given journey.
	 *
	 * @param array<string, mixed>|null $journey The journey find() returns.
	 *
	 * @return JourneyStepRunner
	 */
	private function runner(?array $journey): JourneyStepRunner {
		$this->journeys->method('find')->willReturn($journey);

		$store = $this->createMock(ListObjectStore::class);
		$store->method('schemaSlug')->willReturnCallback(static fn (string $key, string $default): string => $default);
		$store->method('find')->willReturnCallback(
			fn (string $schema, string $id): ?array => ($this->rows[$schema][$id] ?? null)
		);
		$store->method('findAll')->willReturnCallback(
			fn (string $schema, array $filters = []): array => array_values($this->rows[$schema] ?? [])
		);
		$store->method('save')->willReturnCallback(
			function (string $schema, array $payload): ?array {
				if (in_array($schema, $this->failingSaves, true) === true) {
					return null;
				}

				$this->saved[] = [$schema, $payload];
				$payload['uuid'] = $schema . '-' . count($this->saved);
				$this->rows[$schema][$payload['uuid']] = $payload;
				return $payload;
			}
		);
		$store->method('idOf')->willReturnCallback(static fn (?array $row): string => (string)(($row ?? [])['uuid'] ?? ''));

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('permitsSend')->willReturn(['allowed' => true, 'reason' => '']);
		$transports = $this->createMock(MailTransportService::class);
		$transports->method('resolveTransport')->willReturn(['uuid' => 'transport-1']);
		$transports->method('sendOneDelivery')->willReturn(true);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1767225600);

		return new JourneyStepRunner(
			$store,
			$this->journeys,
			$compliance,
			$this->segments,
			$transports,
			$time,
			$this->createMock(LoggerInterface::class),
		);
	}//end runner()

	/**
	 * An unknown journey does nothing and records nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownJourneyDoesNothing(): void {
		$this->assertSame([], $this->runner(journey: null)->run(journeyId: 'gone', subject: ['contact' => 'c-1']));
		$this->assertSame([], $this->runs);
	}//end testAnUnknownJourneyDoesNothing()

	/**
	 * A scheduled journey (no subject) reaches its segment's members, once
	 * each, skipping blank ids; with no segment it reaches no one.
	 *
	 * @return void
	 */
	public function testAScheduledJourneyReachesItsSegment(): void {
		$this->segments->expects($this->once())->method('getMembersForBlast')->with('seg-1')->willReturn(
			[['contactId' => ' c-1 '], ['contactId' => ''], ['name' => 'no id'], ['contactId' => 'c-2'], ['contactId' => 'c-1']]
		);
		$journey = ['audienceSegment' => ' seg-1 ', 'action' => ['kind' => 'createTask', 'taskSubject' => 'Call']];

		$outcomes = $this->runner(journey: $journey)->run(journeyId: 'j-1', flowRunUuid: 'run-9');

		$this->assertSame(['c-1', 'c-2'], array_column($outcomes, 'contactId'));
		$this->assertSame(
			[
				'journeyId' => 'j-1',
				'contactId' => 'c-1',
				'clientId' => 'client-1',
				'state' => 'task-created',
				'reason' => '',
				'actionKind' => 'createTask',
				'flowRunUuid' => 'run-9',
			],
			$this->runs[0]
		);
	}//end testAScheduledJourneyReachesItsSegment()

	/**
	 * Without a segment a scheduled journey has no audience.
	 *
	 * @return void
	 */
	public function testAScheduledJourneyWithoutASegmentReachesNoOne(): void {
		$this->segments->expects($this->never())->method('getMembersForBlast');

		$this->assertSame([], $this->runner(journey: ['audienceSegment' => '  '])->run(journeyId: 'j-1'));
	}//end testAScheduledJourneyWithoutASegmentReachesNoOne()

	/**
	 * With a subject, the segment filters the subject's contacts.
	 *
	 * @return void
	 */
	public function testTheSegmentFiltersATriggeredAudience(): void {
		$this->segments->method('getMembersForBlast')->willReturn([['contactId' => 'c-2']]);
		$journey = ['audienceSegment' => 'seg-1', 'action' => ['kind' => 'createTask', 'taskSubject' => 'Call']];

		$outcomes = $this->runner(journey: $journey)->run(journeyId: 'j-1', subject: ['clientRef' => 'client-1']);

		$this->assertSame(['c-2'], array_column($outcomes, 'contactId'));
	}//end testTheSegmentFiltersATriggeredAudience()

	/**
	 * A subject names its contact directly by contactId; one with no
	 * contact and no client reaches no one; a client ref only matches
	 * contacts whose client is exactly that ref.
	 *
	 * @return void
	 */
	public function testTheSubjectNamesItsContacts(): void {
		$journey = ['action' => ['kind' => 'createTask', 'taskSubject' => 'Call']];
		$runner = $this->runner(journey: $journey);

		$this->assertSame(['c-3'], array_column($runner->run(journeyId: 'j-1', subject: ['contact' => ' ', 'contactId' => 'c-3']), 'contactId'));
		$this->assertSame([], $runner->run(journeyId: 'j-1', subject: ['clientRef' => '  ', 'other' => 'x']));
		$this->assertSame([], $runner->run(journeyId: 'j-1', subject: ['clientRef' => 'client-2']));
	}//end testTheSubjectNamesItsContacts()

	/**
	 * A task carries subject, type, client and trimmed assignee; an unknown
	 * contact has no client.
	 *
	 * @return void
	 */
	public function testATaskIsWrittenWithItsAssignee(): void {
		$journey = ['action' => ['taskSubject' => ' Renewal call ', 'taskType' => 'visit', 'taskAssignee' => ' ann ']];

		$outcomes = $this->runner(journey: $journey)->run(journeyId: 'j-1', subject: ['contact' => 'c-unknown']);

		$this->assertSame([['state' => 'task-created', 'reason' => '', 'contactId' => 'c-unknown']], $outcomes);
		$this->assertSame(
			[['crmTask', ['subject' => 'Renewal call', 'type' => 'visit', 'status' => 'open', 'clientId' => '', 'assigneeUserId' => 'ann']]],
			$this->saved
		);
		$this->assertSame('', $this->runs[0]['clientId']);
	}//end testATaskIsWrittenWithItsAssignee()

	/**
	 * A task without a subject, or one that cannot be stored, fails with its reason.
	 *
	 * @return void
	 */
	public function testATaskFailsWithItsReason(): void {
		$runner = $this->runner(journey: ['action' => ['kind' => 'createTask', 'taskSubject' => ' ']]);
		$this->assertSame('task_subject_missing', $runner->run(journeyId: 'j-1', subject: ['contact' => 'c-1'])[0]['reason']);
		$this->assertSame([], $this->saved);

		$this->setUp();
		$this->failingSaves = ['crmTask'];
		$runner = $this->runner(journey: ['action' => ['taskSubject' => 'Call']]);
		$this->assertSame(
			['state' => 'failed', 'reason' => 'task_write_failed', 'contactId' => 'c-1'],
			$runner->run(journeyId: 'j-1', subject: ['contact' => 'c-1'])[0]
		);
	}//end testATaskFailsWithItsReason()

	/**
	 * A mailing whose ledger blast cannot be stored fails as no_ledger, and
	 * one whose delivery cannot be stored as no_delivery.
	 *
	 * @return void
	 */
	public function testAMailingFailsWhenTheLedgerCannotBeWritten(): void {
		$journey = ['audienceSegment' => 'seg-1', 'action' => ['kind' => 'sendMailing', 'templateId' => 'tpl-1']];
		$this->segments->method('getMembersForBlast')->willReturn([['contactId' => 'c-1']]);

		$this->failingSaves = ['blast'];
		$this->assertSame('no_ledger', $this->runner(journey: $journey)->run(journeyId: 'j-1', subject: ['contact' => 'c-1'])[0]['reason']);

		$this->failingSaves = ['blastDelivery'];
		$outcome = $this->runner(journey: $journey)->run(journeyId: 'j-1', subject: ['contact' => 'c-1'])[0];
		$this->assertSame(['state' => 'failed', 'reason' => 'no_delivery', 'contactId' => 'c-1'], $outcome);
		$this->assertSame('blast', $this->saved[0][0]);
		$this->assertSame('seg-1', $this->saved[0][1]['segmentId']);
		$this->assertSame('tpl-1', $this->saved[0][1]['templateId']);
	}//end testAMailingFailsWhenTheLedgerCannotBeWritten()
}//end class
