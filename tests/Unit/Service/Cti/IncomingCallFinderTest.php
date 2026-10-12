<?php

/**
 * Tests for IncomingCallFinder.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\Calls\MirroredCallReader;
use OCA\Pipelinq\Service\Cti\IncomingCallFinder;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * Which call is on an agent's line, and when the answer is "none".
 *
 * The finder reads the contactmoment the telephony webhook writes. So the
 * rows here carry the fields `CtiService::createPendingContactmoment()` really
 * sets, and one test checks the three filter fields against the ticket schema
 * as the import composes it: a filter on a field the schema does not have
 * would match nothing and the card would stay empty for ever.
 *
 * @covers \OCA\Pipelinq\Service\Cti\IncomingCallFinder
 * @uses   \OCA\Pipelinq\Service\Calls\MirroredCallReader
 * @uses   \OCA\Pipelinq\Service\ConfigFileLoaderService
 *
 * @spec openspec/changes/screen-pop-incoming-call/specs/cti-screenpop-adapter/spec.md#requirement-the-incoming-call-card-opens-the-screen-pop-req-cti-012
 */
class IncomingCallFinderTest extends TestCase {
	/**
	 * A fixed "now": 12 October 2026, 14:00:00 UTC.
	 */
	private const NOW = 1791813600;

	/**
	 * The filters the finder handed to the ticket service, when it asked.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $askedFilters = null;

	/**
	 * The order the finder asked for.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $askedSort = null;

	/**
	 * Build a finder over a telephony configuration and a set of ticket rows.
	 *
	 * @param array<string, mixed> $config The stored telephony configuration.
	 * @param array<int, mixed> $rows The rows the ticket service returns.
	 *
	 * @return IncomingCallFinder The finder.
	 */
	/**
	 * The agent's open call in the call mirror, or null; `false` means calls are not mirrored here.
	 *
	 * @var array<string, string>|false|null
	 */
	private array|false|null $mirrored = false;

	/**
	 * The call mirror's reader.
	 *
	 * @return MirroredCallReader The reader.
	 */
	private function mirror(): MirroredCallReader {
		$mirror = $this->createMock(MirroredCallReader::class);
		$mirror->method('isActive')->willReturn($this->mirrored !== false);
		$mirror->method('openCallFor')->willReturnCallback(
			function (string $agentId, ?int $now = null): ?array {
				$this->assertSame('agent-1', $agentId);
				$this->assertNotFalse($this->mirrored, 'A mirror that is not active is not read.');
				return $this->mirrored;
			}
		);

		return $mirror;
	}//end mirror()

	private function finder(array $config, array $rows): IncomingCallFinder {
		$cti = $this->createMock(CtiService::class);
		$cti->method('loadConfig')->willReturn($config);

		$tickets = $this->createMock(TicketService::class);
		$tickets->method('findByType')->willReturnCallback(
			function (string $ticketType, array $extraFilters = [], int $limit = 10000, array $sort = []) use ($rows): array {
				$this->assertSame(TicketService::TYPE_CONTACTMOMENT, $ticketType);
				$this->assertLessThanOrEqual(10, $limit, 'The card must not read an agent\'s whole call history.');
				$this->askedFilters = $extraFilters;
				$this->askedSort = $sort;
				return $rows;
			}
		);

		return new IncomingCallFinder($cti, $tickets, $this->mirror());
	}//end finder()

	/**
	 * A contactmoment as the telephony webhook writes it.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function call(array $overrides = []): array {
		return array_merge(
			[
				'id' => 'c0ffee00-0000-4000-8000-000000000001',
				'ticketType' => 'contactmoment',
				'channel' => 'telephony',
				'direction' => 'inbound',
				'from_number' => '+31612345678',
				'to_number' => '+31786123341',
				'cti_extension' => '201',
				'assignee' => 'agent-1',
				'started_at' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 8)),
				'title' => 'CTI inbound call',
				'queue_name' => 'Vergunningen',
			],
			$overrides
		);
	}//end call()

	/**
	 * Without a telephony platform nothing is enabled and no ticket is read.
	 *
	 * @return void
	 */
	public function testNoTelephonyPlatformReadsNothing(): void {
		$cti = $this->createMock(CtiService::class);
		$cti->method('loadConfig')->willReturn([]);
		$tickets = $this->createMock(TicketService::class);
		$tickets->expects($this->never())->method('findByType');

		$answer = (new IncomingCallFinder($cti, $tickets, $this->mirror()))->forAgent(userId: 'agent-1', now: self::NOW);

		$this->assertSame(['enabled' => false, 'delayMs' => 0, 'call' => null], $answer);
	}//end testNoTelephonyPlatformReadsNothing()

	/**
	 * With the screen pop switched off nothing is enabled and no ticket is read.
	 *
	 * @return void
	 */
	public function testScreenPopSwitchedOffReadsNothing(): void {
		$cti = $this->createMock(CtiService::class);
		$cti->method('loadConfig')->willReturn(['platform' => 'asterisk', 'screen_pop_enabled' => false]);
		$tickets = $this->createMock(TicketService::class);
		$tickets->expects($this->never())->method('findByType');

		$answer = (new IncomingCallFinder($cti, $tickets, $this->mirror()))->forAgent(userId: 'agent-1', now: self::NOW);

		$this->assertFalse($answer['enabled']);
		$this->assertNull($answer['call']);
	}//end testScreenPopSwitchedOffReadsNothing()

	/**
	 * A ringing call on the agent's line is returned with what the card shows.
	 *
	 * @return void
	 */
	public function testARingingCallIsReturned(): void {
		$answer = $this->finder(config: ['platform' => 'asterisk', 'screen_pop_delay_ms' => 2000], rows: [$this->call()])
			->forAgent(userId: 'agent-1', now: self::NOW);

		$this->assertTrue($answer['enabled']);
		$this->assertSame(2000, $answer['delayMs']);
		$this->assertSame(
			[
				'id' => 'c0ffee00-0000-4000-8000-000000000001',
				'source' => 'pipelinq',
				'fromNumber' => '+31612345678',
				'queueName' => 'Vergunningen',
				'extension' => '201',
				'startedAt' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 8)),
				'answeredAt' => '',
			],
			$answer['call']
		);
		$this->assertSame(['assignee' => 'agent-1', 'channel' => 'telephony', 'direction' => 'inbound'], $this->askedFilters);
		$this->assertSame(['started_at' => 'desc'], $this->askedSort);
	}//end testARingingCallIsReturned()

	/**
	 * A call that ended, a stale call and a colleague's call are not incoming.
	 *
	 * The colleague's row is the control for a store that ignores the assignee
	 * filter: the finder must not trust the filter alone.
	 *
	 * @return void
	 */
	public function testEndedStaleAndForeignCallsAreNotIncoming(): void {
		$rows = [
			$this->call(['id' => 'ended', 'ended_at' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 2))]),
			$this->call(['id' => 'stale', 'started_at' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - IncomingCallFinder::MAX_AGE_SECONDS - 1))]),
			$this->call(['id' => 'colleague', 'assignee' => 'agent-2']),
			$this->call(['id' => 'outbound', 'direction' => 'outbound']),
			$this->call(['id' => 'mail', 'channel' => 'email']),
			$this->call(['id' => 'no-start', 'started_at' => 'not a date']),
			'not a row',
		];

		$answer = $this->finder(config: ['platform' => 'asterisk'], rows: $rows)->forAgent(userId: 'agent-1', now: self::NOW);

		$this->assertTrue($answer['enabled']);
		$this->assertNull($answer['call']);
	}//end testEndedStaleAndForeignCallsAreNotIncoming()

	/**
	 * Of two open calls the newest wins, whatever order the store returns.
	 *
	 * @return void
	 */
	public function testTheNewestOpenCallWins(): void {
		$rows = [
			$this->call(['id' => 'older', 'started_at' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 600))]),
			$this->call(['id' => 'newer', 'started_at' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 5)), 'answered_at' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 1))]),
		];

		$answer = $this->finder(config: ['platform' => 'callvoip'], rows: $rows)->forAgent(userId: 'agent-1', now: self::NOW);

		$this->assertSame('newer', $answer['call']['id']);
		$this->assertSame(gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 1)), $answer['call']['answeredAt']);
	}//end testTheNewestOpenCallWins()

	/**
	 * An entity row (jsonSerialize) reads the same as an array row.
	 *
	 * @return void
	 */
	public function testAnEntityRowIsRead(): void {
		$call = $this->call();
		$entity = new class ($call) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The row.
			 */
			public function __construct(private array $data) {
			}//end __construct()

			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}//end jsonSerialize()
		};

		$answer = $this->finder(config: ['platform' => 'asterisk'], rows: [$entity])->forAgent(userId: 'agent-1', now: self::NOW);

		$this->assertSame($call['id'], $answer['call']['id']);
	}//end testAnEntityRowIsRead()

	/**
	 * An empty uid asks nothing: a session without a user has no line.
	 *
	 * @return void
	 */
	public function testAnEmptyUidAsksNothing(): void {
		$cti = $this->createMock(CtiService::class);
		$cti->method('loadConfig')->willReturn(['platform' => 'asterisk']);
		$tickets = $this->createMock(TicketService::class);
		$tickets->expects($this->never())->method('findByType');

		$this->assertFalse((new IncomingCallFinder($cti, $tickets, $this->mirror()))->forAgent(userId: '')['enabled']);
	}//end testAnEmptyUidAsksNothing()

	/**
	 * Every field the finder filters, orders or reads exists on the ticket schema.
	 *
	 * Control: a field the schema does not have fails the same check.
	 *
	 * @return void
	 */
	public function testTheFieldsReadExistOnTheTicketSchema(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$properties = ($register['components']['schemas']['ticket']['properties'] ?? []);
		$this->assertNotSame([], $properties, 'The ticket schema was not read.');

		$this->finder(config: ['platform' => 'asterisk'], rows: [])->forAgent(userId: 'agent-1', now: self::NOW);
		$read = array_merge(
			array_keys((array)$this->askedFilters),
			array_keys((array)$this->askedSort),
			['from_number', 'queue_name', 'cti_extension', 'started_at', 'answered_at', 'ended_at'],
		);
		foreach ($read as $field) {
			$this->assertArrayHasKey($field, $properties, "The ticket schema has no {$field}.");
		}

		$this->assertContains('inbound', $properties['direction']['enum']);
		$this->assertArrayNotHasKey('caller_number', $properties, 'Control: an unknown field is absent.');
	}//end testTheFieldsReadExistOnTheTicketSchema()
	/**
	 * A mirrored call shows without a telephony platform in pipelinq, marked as coming from the mirror.
	 *
	 * @return void
	 */
	public function testAMirroredCallShowsWithoutAPlatformInPipelinq(): void {
		$this->mirrored = [
			'id' => 'mirror-row-1',
			'fromNumber' => '+31612345678',
			'startedAt' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 5)),
			'answeredAt' => '',
		];
		$cti = $this->createMock(CtiService::class);
		$cti->method('loadConfig')->willReturn([]);
		$tickets = $this->createMock(TicketService::class);
		$tickets->expects($this->never())->method('findByType');

		$answer = (new IncomingCallFinder($cti, $tickets, $this->mirror()))->forAgent(userId: 'agent-1', now: self::NOW);

		$this->assertTrue($answer['enabled']);
		$this->assertSame(
			[
				'id' => 'mirror-row-1',
				'source' => 'mirror',
				'fromNumber' => '+31612345678',
				'queueName' => '',
				'extension' => '',
				'startedAt' => $this->mirrored['startedAt'],
				'answeredAt' => '',
			],
			$answer['call']
		);
	}//end testAMirroredCallShowsWithoutAPlatformInPipelinq()

	/**
	 * Of pipelinq's own call and a mirrored one, the one that started last shows; the switch turns both off.
	 *
	 * @return void
	 */
	public function testTheNewerOfTheTwoSourcesWinsAndTheSwitchStopsBoth(): void {
		$stamp = static fn (int $ago): string => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - $ago));
		$this->mirrored = ['id' => 'mirror-row-1', 'fromNumber' => '+31201234567', 'startedAt' => $stamp(3), 'answeredAt' => ''];

		$answer = $this->finder(['platform' => 'voys'], [$this->call(['started_at' => $stamp(8)])])->forAgent(userId: 'agent-1', now: self::NOW);
		$this->assertSame('mirror', $answer['call']['source']);

		$answer = $this->finder(['platform' => 'voys'], [$this->call(['started_at' => $stamp(1)])])->forAgent(userId: 'agent-1', now: self::NOW);
		$this->assertSame('pipelinq', $answer['call']['source']);
		$this->assertSame('c0ffee00-0000-4000-8000-000000000001', $answer['call']['id']);

		$this->mirrored = null;
		$answer = $this->finder(['platform' => 'voys'], [$this->call()])->forAgent(userId: 'agent-1', now: self::NOW);
		$this->assertSame('pipelinq', $answer['call']['source']);

		// Screen pop off: the mirror is active, and still nothing is read.
		$this->mirrored = ['id' => 'mirror-row-1', 'fromNumber' => '+31201234567', 'startedAt' => $stamp(3), 'answeredAt' => ''];
		$cti = $this->createMock(CtiService::class);
		$cti->method('loadConfig')->willReturn(['platform' => 'voys', 'screen_pop_enabled' => false]);
		$tickets = $this->createMock(TicketService::class);
		$tickets->expects($this->never())->method('findByType');
		$mirror = $this->createMock(MirroredCallReader::class);
		$mirror->method('isActive')->willReturn(true);
		$mirror->expects($this->never())->method('openCallFor');
		$answer = (new IncomingCallFinder($cti, $tickets, $mirror))->forAgent(userId: 'agent-1', now: self::NOW);
		$this->assertSame(['enabled' => false, 'delayMs' => 0, 'call' => null], $answer);
	}//end testTheNewerOfTheTwoSourcesWinsAndTheSwitchStopsBoth()
}//end class
