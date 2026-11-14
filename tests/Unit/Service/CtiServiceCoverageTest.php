<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Cti\AdapterRegistry;
use OCA\Pipelinq\Service\Cti\CtiAdapterInterface;
use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult;
use OCA\Pipelinq\Service\Cti\Result\ScreenPopResult;
use OCA\Pipelinq\Service\CtiContactMatcher;
use OCA\Pipelinq\Service\CtiDispositionService;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Coverage tests for CtiService: webhook dispatch per event type, screen-pop,
 * click-to-dial, presence, the CTI config singleton, the event-log purge and
 * id extraction.
 *
 * @covers \OCA\Pipelinq\Service\CtiService
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 * @uses   \OCA\Pipelinq\Service\Cti\CallAgent
 * @uses   \OCA\Pipelinq\Service\Cti\CallRecording
 * @uses   \OCA\Pipelinq\Service\Cti\CtiWebhookDelivery
 * @uses   \OCA\Pipelinq\Service\Cti\PendingCall
 * @uses   \OCA\Pipelinq\Service\Cti\Result\CtiCallResult
 * @uses   \OCA\Pipelinq\Service\Cti\Result\CtiWebhookResult
 * @uses   \OCA\Pipelinq\Service\Cti\Result\OriginateResult
 * @uses   \OCA\Pipelinq\Service\Cti\Result\ScreenPopResult
 * @uses   \OCA\Pipelinq\Util\PayloadToArray
 * @uses   \OCA\Pipelinq\Util\EntityPayload
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 */
class CtiServiceCoverageTest extends TestCase {

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * In-memory OpenRegister object service, served by the container.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Log lines, as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logs = [];

	/**
	 * Ticket saves, as [payload, uuid].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $ticketSaves = [];

	/**
	 * Ticket service mock.
	 *
	 * @var TicketService&MockObject
	 */
	private TicketService $tickets;

	/**
	 * Adapter registry mock.
	 *
	 * @var AdapterRegistry&MockObject
	 */
	private AdapterRegistry $registry;

	/**
	 * Contact matcher mock.
	 *
	 * @var CtiContactMatcher&MockObject
	 */
	private CtiContactMatcher $matcher;

	/**
	 * Disposition service mock.
	 *
	 * @var CtiDispositionService&MockObject
	 */
	private CtiDispositionService $disposition;

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Set up shared doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->config = [
			'register' => 'reg-1',
			'ctiAdapterConfig_schema' => 'sch-config',
			'ctiAgentPresence_schema' => 'sch-presence',
			'ctiEventLog_schema' => 'sch-log',
		];

		$this->store = new class {
			/** @var array<string, mixed> Rows findAll answers, by schema. */
			public array $rows = [];
			/** @var array<int, array<string, mixed>> */
			public array $findAllCalls = [];
			/** @var array<int, array<int, mixed>> */
			public array $saves = [];
			/** @var array<int, string> */
			public array $deleted = [];
			/** @var string|null A schema whose findAll throws. */
			public ?string $throwOn = null;
			/** @var bool */
			public bool $saveThrows = false;
			/** @var mixed */
			public mixed $saveReturn = null;

			public function findAll(array $config): mixed {
				$this->findAllCalls[] = $config;
				$schema = (string)($config['filters']['schema'] ?? '');
				if ($schema === $this->throwOn) {
					throw new RuntimeException('find failed');
				}

				return ($this->rows[$schema] ?? []);
			}

			public function saveObject(...$args): mixed {
				if ($this->saveThrows === true) {
					throw new RuntimeException('save failed');
				}

				$this->saves[] = $args;
				return ($this->saveReturn ?? $args[0]);
			}

			public function deleteObject(string $register, string $schema, string $uuid): bool {
				if ($uuid === 'boom') {
					throw new RuntimeException('cannot delete');
				}

				$this->deleted[] = $register.'/'.$schema.'/'.$uuid;
				return true;
			}
		};

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(fn () => $this->store);

		$this->tickets = $this->createMock(TicketService::class);
		$this->tickets->method('isConfigured')->willReturn(true);
		$this->tickets->method('save')->willReturnCallback(
			function (string $ticketType, array $payload, ?string $uuid = null): object {
				$this->ticketSaves[] = [$payload, $uuid];
				return $this->entity(['uuid' => ($uuid ?? 'cm-new')]);
			}
		);

		$this->registry = $this->createMock(AdapterRegistry::class);
		$this->matcher = $this->createMock(CtiContactMatcher::class);
		$this->disposition = $this->createMock(CtiDispositionService::class);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return CtiService
	 */
	private function service(): CtiService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logs[] = [$level, $message, $context];
				}
			);
		}

		return new CtiService(
			$this->container,
			$appConfig,
			$this->registry,
			new PhoneNormaliser($appConfig, $this->createMock(LoggerInterface::class)),
			$this->matcher,
			$this->disposition,
			$this->tickets,
			$logger,
		);
	}//end service()

	/**
	 * An entity double that serialises to the given array.
	 *
	 * @param array<string, mixed> $data The serialised data.
	 *
	 * @return object
	 */
	private function entity(array $data): object {
		return new class($data) implements \JsonSerializable {
			public function __construct(private array $data) {
			}

			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * A registry whose adapter accepts every signature and parses to $event.
	 *
	 * @param CtiWebhookResult $event The parsed event.
	 *
	 * @return void
	 */
	private function adapterParsing(CtiWebhookResult $event): void {
		$adapter = $this->createMock(CtiAdapterInterface::class);
		$adapter->method('verifyWebhookSignature')->willReturn(true);
		$adapter->method('handleInboundWebhook')->willReturn($event);
		$this->registry->method('get')->willReturn($adapter);
	}//end adapterParsing()

	/**
	 * The ticket store's findByType answers $found for every lookup.
	 *
	 * @param array<int, mixed> $found The rows.
	 *
	 * @return void
	 */
	private function ticketsFind(array $found): void {
		$this->tickets->method('findByType')->willReturn($found);
	}//end ticketsFind()

	/**
	 * Ticket saves without the clock fields, which must be ISO timestamps.
	 *
	 * @return array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private function savesWithoutTimes(): array {
		return array_map(
			function (array $save): array {
				foreach (['started_at', 'ended_at', 'answered_at'] as $field) {
					if (array_key_exists($field, $save[0]) === true) {
						$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $save[0][$field]);
						$save[0][$field] = 'T';
					}
				}

				return $save;
			},
			$this->ticketSaves
		);
	}//end savesWithoutTimes()

	/**
	 * Screen-pop chooses navigate, chooser or intake by the number of matches.
	 *
	 * @return void
	 */
	public function testScreenPopActionFollowsMatchCount(): void {
		$answers = [
			['matches' => [['id' => 'c1']], 'totalMatches' => 1],
			['matches' => [['id' => 'c1'], ['id' => 'c2']], 'totalMatches' => 2],
			['matches' => [], 'totalMatches' => 0],
		];
		$this->matcher->expects($this->exactly(3))->method('findByPhoneNumber')
			->with('+31201234567', 'org-7')
			->willReturnOnConsecutiveCalls(...$answers);
		$service = $this->service();

		$navigate = $service->initiateScreenPop('020-1234567', 'org-7');
		$this->assertSame(ScreenPopResult::ACTION_NAVIGATE, $navigate->action);
		$this->assertSame([['id' => 'c1']], $navigate->matches);
		$this->assertSame('+31201234567', $navigate->e164);
		$this->assertSame('020-1234567', $navigate->rawNumber);
		$this->assertSame(ScreenPopResult::ACTION_CHOOSER, $service->initiateScreenPop('020-1234567', 'org-7')->action);
		$this->assertSame(ScreenPopResult::ACTION_INTAKE, $service->initiateScreenPop('020-1234567', 'org-7')->action);
	}//end testScreenPopActionFollowsMatchCount()

	/**
	 * Without a configured platform a call is not originated.
	 *
	 * @return void
	 */
	public function testOriginateWithoutPlatformFails(): void {
		$this->registry->expects($this->never())->method('get');

		$result = $this->service()->originateCall('alice', '201', '0612345678');

		$this->assertFalse($result->success);
		$this->assertSame('No CTI platform configured.', $result->error);
		$this->assertNull($result->platform);
	}//end testOriginateWithoutPlatformFails()

	/**
	 * An adapter error is reported with the platform, no ticket is written.
	 *
	 * @return void
	 */
	public function testOriginateAdapterErrorIsReported(): void {
		$this->store->rows['sch-config'] = [['platform' => 'asterisk']];
		$this->registry->expects($this->once())->method('get')->with('asterisk')
			->willThrowException(new RuntimeException('not registered'));

		$result = $this->service()->originateCall('alice', '201', '0612345678');

		$this->assertFalse($result->success);
		$this->assertSame('CTI adapter error: not registered', $result->error);
		$this->assertSame('asterisk', $result->platform);
		$this->assertSame([], $this->ticketSaves);
	}//end testOriginateAdapterErrorIsReported()

	/**
	 * A successful originate pre-creates an outbound contactmoment.
	 *
	 * @return void
	 */
	public function testOriginateSuccessCreatesOutboundContactmoment(): void {
		$this->store->rows['sch-config'] = [$this->entity(['platform' => 'callvoip', 'default_outbound_caller_id' => '0201234567'])];
		$adapter = $this->createMock(CtiAdapterInterface::class);
		$adapter->expects($this->once())->method('originateCall')
			->with('201', '0612345678', '0201234567')
			->willReturn(new CtiCallResult(success: true, externalCallId: 'ext-1'));
		$this->registry->method('get')->willReturn($adapter);

		$result = $this->service()->originateCall('alice', '201', '0612345678');

		$this->assertTrue($result->success);
		$this->assertSame('ext-1', $result->externalCallId);
		$this->assertSame('cm-new', $result->interactionId);
		$this->assertSame('callvoip', $result->platform);
		$this->assertSame(
			[[[
				'channel' => 'telephony',
				'direction' => 'outbound',
				'from_number' => '+31201234567',
				'to_number' => '+31612345678',
				'cti_extension' => '201',
				'assignee' => 'alice',
				'started_at' => 'T',
				'title' => 'CTI outbound call',
				'telephony_platform' => 'callvoip',
				'external_call_id' => 'ext-1',
			], null]],
			$this->savesWithoutTimes()
		);
	}//end testOriginateSuccessCreatesOutboundContactmoment()

	/**
	 * A failed originate passes the adapter's error through and writes nothing.
	 *
	 * @return void
	 */
	public function testOriginateFailureWritesNoTicket(): void {
		$this->store->rows['sch-config'] = [['platform' => 'callvoip']];
		$adapter = $this->createMock(CtiAdapterInterface::class);
		$adapter->method('originateCall')->willReturn(new CtiCallResult(success: false, error: 'busy'));
		$this->registry->method('get')->willReturn($adapter);

		$result = $this->service()->originateCall('alice', '201', '0612345678');

		$this->assertFalse($result->success);
		$this->assertNull($result->interactionId);
		$this->assertSame('busy', $result->error);
		$this->assertSame([], $this->ticketSaves);
	}//end testOriginateFailureWritesNoTicket()

	/**
	 * Presence is not synced while its schema is unconfigured.
	 *
	 * @return void
	 */
	public function testSyncPresenceUnconfiguredDoesNothing(): void {
		$this->config['ctiAgentPresence_schema'] = '';
		$this->container->expects($this->never())->method('get');

		$this->service()->syncPresence('alice', 'available');
	}//end testSyncPresenceUnconfiguredDoesNothing()

	/**
	 * Presence updates the agent's existing row and defaults the platform to the active one.
	 *
	 * @return void
	 */
	public function testSyncPresenceUpdatesExistingRow(): void {
		$existing = new class {
			public function getUuid(): ?string {
				return null;
			}

			public function getId(): int {
				return 42;
			}
		};
		$this->store->rows['sch-presence'] = [$existing];
		$this->store->rows['sch-config'] = [['platform' => 'asterisk']];

		$this->service()->syncPresence('alice', 'on-call', '201');

		$this->assertSame(['register' => 'reg-1', 'schema' => 'sch-presence', 'user_id' => 'alice'], $this->store->findAllCalls[0]['filters']);
		$save = $this->store->saves[0];
		$this->assertSame('on-call', $save[0]['presence_state']);
		$this->assertSame('201', $save[0]['extension']);
		$this->assertSame('asterisk', $save[0]['platform']);
		$this->assertSame(['reg-1', 'sch-presence', '42'], [$save[2], $save[3], $save[4]]);
	}//end testSyncPresenceUpdatesExistingRow()

	/**
	 * A new presence row is created without uuid; a failure is logged.
	 *
	 * @return void
	 */
	public function testSyncPresenceCreatesAndLogsFailure(): void {
		$service = $this->service();
		$service->syncPresence('bob', 'away', null, 'callvoip');
		$this->assertSame('', $this->store->saves[0][0]['extension']);
		$this->assertSame('callvoip', $this->store->saves[0][0]['platform']);
		$this->assertNull($this->store->saves[0][4]);

		$this->store->saveThrows = true;
		$service->syncPresence('bob', 'away', null, 'callvoip');
		$this->assertSame([['warning', 'CTI syncPresence failed', ['exception' => 'save failed', 'userId' => 'bob']]], $this->logs);
	}//end testSyncPresenceCreatesAndLogsFailure()

	/**
	 * The config singleton reads as empty when unconfigured, absent or failing.
	 *
	 * @return void
	 */
	public function testLoadConfigFallbacks(): void {
		$this->assertSame([], $this->service()->loadConfig());
		$this->assertSame('', $this->service()->activePlatform());

		$this->store->throwOn = 'sch-config';
		$this->assertSame([], $this->service()->loadConfig());
		$this->assertSame([['warning', 'CTI loadConfig failed', ['exception' => 'find failed']]], $this->logs);

		$this->config['ctiAdapterConfig_schema'] = '';
		$calls = count($this->store->findAllCalls);
		$this->assertSame([], $this->service()->loadConfig());
		$this->assertCount($calls, $this->store->findAllCalls);
	}//end testLoadConfigFallbacks()

	/**
	 * Saving the config updates the existing singleton and returns what was saved.
	 *
	 * @return void
	 */
	public function testSaveConfigUpdatesSingleton(): void {
		$this->store->rows['sch-config'] = [['id' => 'cfg-1', 'platform' => 'asterisk']];
		$this->store->saveReturn = $this->entity(['id' => 'cfg-1', 'platform' => 'callvoip']);

		$result = $this->service()->saveConfig(['platform' => 'callvoip']);

		$this->assertSame(['id' => 'cfg-1', 'platform' => 'callvoip'], $result);
		$this->assertSame([['platform' => 'callvoip'], [], 'reg-1', 'sch-config', 'cfg-1'], $this->store->saves[0]);
	}//end testSaveConfigUpdatesSingleton()

	/**
	 * Saving the config creates it when absent, fails soft, and refuses unconfigured.
	 *
	 * @return void
	 */
	public function testSaveConfigCreateFailureAndUnconfigured(): void {
		$service = $this->service();
		$this->assertSame(['platform' => 'x'], $service->saveConfig(['platform' => 'x']));
		$this->assertNull($this->store->saves[0][4]);

		$this->store->saveThrows = true;
		$this->assertSame([], $service->saveConfig(['platform' => 'x']));
		$this->assertSame([['error', 'CTI saveConfig failed', ['exception' => 'save failed']]], $this->logs);

		$this->config['register'] = '';
		$this->assertSame([], $this->service()->saveConfig(['platform' => 'x']));
	}//end testSaveConfigCreateFailureAndUnconfigured()

	/**
	 * testConnection reports unconfigured, adapter errors and success.
	 *
	 * @return void
	 */
	public function testConnectionOutcomes(): void {
		$this->assertSame(['ok' => false, 'platform' => '', 'message' => 'No CTI platform configured.'], $this->service()->testConnection());

		$this->store->rows['sch-config'] = [['platform' => 'asterisk']];
		$this->registry->method('get')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('no adapter')),
			$this->createMock(CtiAdapterInterface::class)
		);
		$service = $this->service();
		$this->assertSame(['ok' => false, 'platform' => 'asterisk', 'message' => 'no adapter'], $service->testConnection());
		$this->assertSame(['ok' => true, 'platform' => 'asterisk', 'message' => 'Adapter resolved and configuration present.'], $service->testConnection());
	}//end testConnectionOutcomes()

	/**
	 * The purge deletes only dated entries older than 30 days that carry an id.
	 *
	 * @return void
	 */
	public function testPurgeDeletesOnlyOldIdentifiedEntries(): void {
		$this->store->rows['sch-log'] = [
			['id' => 'old-1', 'received_at' => '2000-01-01T00:00:00Z'],
			$this->entity(['uuid' => 'old-2', 'received_at' => '2001-01-01T00:00:00Z']),
			['id' => 'new-1', 'received_at' => '2999-01-01T00:00:00Z'],
			['id' => 'undated'],
			['received_at' => '2000-01-01T00:00:00Z'],
			['id' => 'boom', 'received_at' => '2000-01-01T00:00:00Z'],
		];

		$this->assertSame(2, $this->service()->purgeOldEventLog());
		$this->assertSame(['reg-1/sch-log/old-1', 'reg-1/sch-log/old-2'], $this->store->deleted);
		$this->assertSame(1000, $this->store->findAllCalls[0]['limit']);
		$this->assertSame([['warning', 'CTI purgeOldEventLog: deleteObject failed', ['exception' => 'cannot delete', 'id' => 'boom']]], $this->logs);
	}//end testPurgeDeletesOnlyOldIdentifiedEntries()

	/**
	 * The purge returns 0 when unconfigured, on a non-list answer, and on a failing read.
	 *
	 * @return void
	 */
	public function testPurgeFallbacks(): void {
		$this->store->rows['sch-log'] = 'not-a-list';
		$this->assertSame(0, $this->service()->purgeOldEventLog());

		$this->store->throwOn = 'sch-log';
		$this->assertSame(0, $this->service()->purgeOldEventLog());
		$this->assertSame([['warning', 'CTI purgeOldEventLog failed', ['exception' => 'find failed']]], $this->logs);

		$this->config['ctiEventLog_schema'] = '';
		$this->assertSame(0, $this->service()->purgeOldEventLog());
		$this->assertCount(2, $this->store->findAllCalls);
	}//end testPurgeFallbacks()

	/**
	 * An answered call with no prior contactmoment creates one with the routing fields.
	 *
	 * @return void
	 */
	public function testAnsweredWebhookCreatesContactmoment(): void {
		$this->ticketsFind([]);
		$this->adapterParsing(new CtiWebhookResult(
			eventType: 'answered',
			externalCallId: 'call-1',
			fromNumber: '0612345678',
			toNumber: '0201234567',
			extension: '201',
			userId: 'alice',
			queueName: 'Burgerzaken',
		));

		$result = $this->service()->handleWebhook('asterisk', ['x' => 1], '{}', 'sig');

		$this->assertSame(['logged' => true, 'valid' => true, 'interactionId' => 'cm-new'], $result);
		$saves = $this->savesWithoutTimes();
		$this->assertCount(1, $saves);
		$this->assertSame('inbound', $saves[0][0]['direction']);
		$this->assertSame('+31612345678', $saves[0][0]['from_number']);
		$this->assertSame('Burgerzaken', $saves[0][0]['queue_name']);
		$this->assertSame('', $saves[0][0]['agent_skill']);
		$this->assertSame('T', $saves[0][0]['answered_at']);
		$this->assertSame('asterisk', $saves[0][0]['telephony_platform']);
		$this->assertNull($saves[0][1]);
	}//end testAnsweredWebhookCreatesContactmoment()

	/**
	 * A ringing event for a known call updates the existing contactmoment.
	 *
	 * @return void
	 */
	public function testRingingWebhookUpdatesExistingContactmoment(): void {
		$this->tickets->expects($this->once())->method('findByType')
			->with(TicketService::TYPE_CONTACTMOMENT, ['external_call_id' => 'call-1'], 1)
			->willReturn([['id' => 'cm-1']]);
		$this->adapterParsing(new CtiWebhookResult(eventType: 'ringing', externalCallId: 'call-1', agentSkill: 'nl'));

		$result = $this->service()->handleWebhook('asterisk', [], '{}', 'sig');

		$this->assertSame('cm-1', $result['interactionId']);
		$this->assertSame(
			[[['telephony_platform' => 'asterisk', 'external_call_id' => 'call-1', 'queue_name' => '', 'agent_skill' => 'nl'], 'cm-1']],
			$this->ticketSaves
		);
	}//end testRingingWebhookUpdatesExistingContactmoment()

	/**
	 * An ended event writes the duration and recording fields on the known contactmoment.
	 *
	 * @return void
	 */
	public function testEndedWebhookFinalisesKnownContactmoment(): void {
		$this->ticketsFind([$this->entity(['uuid' => 'cm-2'])]);
		$this->adapterParsing(new CtiWebhookResult(
			eventType: 'ended',
			externalCallId: 'call-2',
			durationSeconds: 95,
			recordingUrl: 'https://rec/2',
			recordingExpiresAt: '2027-01-01T00:00:00Z',
		));

		$result = $this->service()->handleWebhook('callvoip', [], '{}', 'sig');

		$this->assertSame('cm-2', $result['interactionId']);
		$this->assertSame(
			[[['ended_at' => 'T', 'duration_seconds' => 95, 'recording_url' => 'https://rec/2', 'recording_retention_expires_at' => '2027-01-01T00:00:00Z'], 'cm-2']],
			$this->savesWithoutTimes()
		);
	}//end testEndedWebhookFinalisesKnownContactmoment()

	/**
	 * A late ended event with no prior record creates one, then finalises it.
	 *
	 * @return void
	 */
	public function testLateEndedWebhookCreatesThenFinalises(): void {
		$this->ticketsFind([]);
		$this->adapterParsing(new CtiWebhookResult(eventType: 'ended', externalCallId: 'call-3', direction: 'outbound'));

		$result = $this->service()->handleWebhook('callvoip', [], '{}', 'sig');

		$this->assertSame('cm-new', $result['interactionId']);
		$saves = $this->savesWithoutTimes();
		$this->assertCount(2, $saves);
		$this->assertSame('outbound', $saves[0][0]['direction']);
		$this->assertSame('call-3', $saves[0][0]['external_call_id']);
		$this->assertSame([['ended_at' => 'T', 'duration_seconds' => 0], 'cm-new'], $saves[1]);
	}//end testLateEndedWebhookCreatesThenFinalises()

	/**
	 * Without a configured ticket store an ended or abandoned event yields no contactmoment.
	 *
	 * @return void
	 */
	public function testEndedAndAbandonedWithoutTicketStoreYieldNothing(): void {
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(false);
		$tickets->method('findByType')->willReturn([]);
		$tickets->expects($this->never())->method('save');
		$this->tickets = $tickets;

		foreach (['ended', 'abandoned'] as $type) {
			$adapter = $this->createMock(CtiAdapterInterface::class);
			$adapter->method('verifyWebhookSignature')->willReturn(true);
			$adapter->method('handleInboundWebhook')->willReturn(new CtiWebhookResult(eventType: $type, externalCallId: 'call-4'));
			$this->registry = $this->createMock(AdapterRegistry::class);
			$this->registry->method('get')->willReturn($adapter);

			$this->assertNull($this->service()->handleWebhook('callvoip', [], '{}', 'sig')['interactionId']);
		}
	}//end testEndedAndAbandonedWithoutTicketStoreYieldNothing()

	/**
	 * An abandoned call is upserted and marked abandoned with zero duration.
	 *
	 * @return void
	 */
	public function testAbandonedWebhookMarksContactmoment(): void {
		$this->ticketsFind([['uuid' => 'cm-5']]);
		$this->adapterParsing(new CtiWebhookResult(eventType: 'abandoned', externalCallId: 'call-5'));

		$result = $this->service()->handleWebhook('callvoip', [], '{}', 'sig');

		$this->assertSame('cm-5', $result['interactionId']);
		$saves = $this->savesWithoutTimes();
		$this->assertCount(2, $saves);
		$this->assertSame([['ended_at' => 'T', 'duration_seconds' => 0, 'disposition_outcome' => 'abandoned'], 'cm-5'], $saves[1]);
	}//end testAbandonedWebhookMarksContactmoment()

	/**
	 * A presence event syncs the agent by extension; one without agent is ignored.
	 *
	 * @return void
	 */
	public function testPresenceWebhookSyncsAgent(): void {
		$this->adapterParsing(new CtiWebhookResult(eventType: 'presence', externalCallId: '', extension: '305'));

		$result = $this->service()->handleWebhook('asterisk', [], '{}', 'sig');

		$this->assertNull($result['interactionId']);
		$presence = array_values(array_filter($this->store->saves, static fn (array $s) => $s[3] === 'sch-presence'));
		$this->assertCount(1, $presence);
		$this->assertSame('305', $presence[0][0]['user_id']);
		$this->assertSame('available', $presence[0][0]['presence_state']);
		$this->assertSame('asterisk', $presence[0][0]['platform']);
	}//end testPresenceWebhookSyncsAgent()

	/**
	 * A presence event without user or extension writes only the event log row.
	 *
	 * @return void
	 */
	public function testPresenceWebhookWithoutAgentIsIgnored(): void {
		$this->adapterParsing(new CtiWebhookResult(eventType: 'presence', externalCallId: ''));

		$this->service()->handleWebhook('asterisk', [], '{}', 'sig');

		$this->assertCount(1, $this->store->saves);
		$this->assertSame('sch-log', $this->store->saves[0][3]);
		$this->assertSame('presence', $this->store->saves[0][0]['event_type']);
	}//end testPresenceWebhookWithoutAgentIsIgnored()

	/**
	 * A recording event attaches the recording to the known contactmoment.
	 *
	 * @return void
	 */
	public function testRecordingWebhookAttachesRecording(): void {
		$this->ticketsFind([['id' => 'cm-6']]);
		$this->adapterParsing(new CtiWebhookResult(eventType: 'recording', externalCallId: 'call-6', recordingUrl: 'https://rec/6'));

		$result = $this->service()->handleWebhook('callvoip', [], '{}', 'sig');

		$this->assertSame('cm-6', $result['interactionId']);
		$this->assertSame([[['recording_url' => 'https://rec/6', 'recording_retention_expires_at' => ''], 'cm-6']], $this->ticketSaves);
	}//end testRecordingWebhookAttachesRecording()

	/**
	 * A recording event without url, or for an unknown call, does nothing.
	 *
	 * @return void
	 */
	public function testRecordingWebhookWithoutUrlOrCallDoesNothing(): void {
		$this->tickets->expects($this->once())->method('findByType')->willReturn([]);
		$adapter = $this->createMock(CtiAdapterInterface::class);
		$adapter->method('verifyWebhookSignature')->willReturn(true);
		$adapter->method('handleInboundWebhook')->willReturnOnConsecutiveCalls(
			new CtiWebhookResult(eventType: 'recording', externalCallId: 'call-7'),
			new CtiWebhookResult(eventType: 'recording', externalCallId: 'call-7', recordingUrl: 'https://rec/7'),
		);
		$this->registry->method('get')->willReturn($adapter);
		$service = $this->service();

		$this->assertNull($service->handleWebhook('callvoip', [], '{}', 'sig')['interactionId']);
		$this->assertNull($service->handleWebhook('callvoip', [], '{}', 'sig')['interactionId']);
		$this->assertSame([], $this->ticketSaves);
	}//end testRecordingWebhookWithoutUrlOrCallDoesNothing()

	/**
	 * A call without external id is never looked up and gets a fresh contactmoment.
	 *
	 * @return void
	 */
	public function testEmptyCallIdSkipsLookup(): void {
		$this->tickets->expects($this->never())->method('findByType');
		$this->adapterParsing(new CtiWebhookResult(eventType: 'ringing', externalCallId: ''));

		$this->assertSame('cm-new', $this->service()->handleWebhook('callvoip', [], '{}', 'sig')['interactionId']);
	}//end testEmptyCallIdSkipsLookup()

	/**
	 * A failing contactmoment update is logged and the id still answered.
	 *
	 * @return void
	 */
	public function testFailingUpdateIsLogged(): void {
		$tickets = $this->createMock(TicketService::class);
		$tickets->method('isConfigured')->willReturn(true);
		$tickets->method('findByType')->willReturn([['id' => 'cm-8']]);
		$tickets->method('save')->willThrowException(new RuntimeException('locked'));
		$this->tickets = $tickets;
		$this->adapterParsing(new CtiWebhookResult(eventType: 'ringing', externalCallId: 'call-8'));

		$this->assertSame('cm-8', $this->service()->handleWebhook('callvoip', [], '{}', 'sig')['interactionId']);
		$this->assertSame([['warning', 'CTI updateContactmoment failed', ['exception' => 'locked', 'id' => 'cm-8']]], $this->logs);
	}//end testFailingUpdateIsLogged()

	/**
	 * A failing event-log write is logged and the webhook still answers.
	 *
	 * @return void
	 */
	public function testFailingEventLogWriteIsLogged(): void {
		$this->adapterParsing(new CtiWebhookResult(eventType: 'unknown', externalCallId: 'call-9'));
		$this->store->saveThrows = true;

		$result = $this->service()->handleWebhook('callvoip', [], '{}', 'sig');

		$this->assertSame(['logged' => true, 'valid' => true, 'interactionId' => null], $result);
		$this->assertSame([['warning', 'CTI logEvent failed', ['exception' => 'save failed']]], $this->logs);
	}//end testFailingEventLogWriteIsLogged()

	/**
	 * Lookup rows whose id cannot be extracted are treated as unknown calls.
	 *
	 * @return void
	 */
	public function testUnidentifiableLookupRowsCreateFreshContactmoment(): void {
		$rows = [
			['name' => 'no id'],
			$this->entity(['name' => 'no id']),
			new class {
				public function jsonSerialize(): string {
					return 'scalar';
				}
			},
			new \stdClass(),
			'scalar',
		];
		$this->tickets->method('findByType')->willReturnOnConsecutiveCalls(...array_map(static fn ($row) => [$row], $rows));
		$adapter = $this->createMock(CtiAdapterInterface::class);
		$adapter->method('verifyWebhookSignature')->willReturn(true);
		$adapter->method('handleInboundWebhook')->willReturn(new CtiWebhookResult(eventType: 'ringing', externalCallId: 'call-10'));
		$this->registry->method('get')->willReturn($adapter);
		$service = $this->service();

		foreach ($rows as $row) {
			$this->assertSame('cm-new', $service->handleWebhook('callvoip', [], '{}', 'sig')['interactionId']);
		}

		$this->assertCount(count($rows), $this->ticketSaves);
		foreach ($this->ticketSaves as $save) {
			$this->assertNull($save[1]);
		}
	}//end testUnidentifiableLookupRowsCreateFreshContactmoment()

	/**
	 * Disposition and escalation queues are delegated to the disposition service.
	 *
	 * @return void
	 */
	public function testDispositionAndQueuesAreDelegated(): void {
		$this->disposition->expects($this->once())->method('processDisposition')
			->with('cm-1', 'Paspoort', 'resolved', 'notes', 'q-2')
			->willReturn(['outcome' => 'resolved', 'interactionId' => 'cm-1', 'taskId' => null]);
		$this->disposition->expects($this->once())->method('queueChoices')
			->willReturn([['id' => 'q-2', 'label' => 'Backoffice']]);
		$service = $this->service();

		$this->assertSame(
			['outcome' => 'resolved', 'interactionId' => 'cm-1', 'taskId' => null],
			$service->processDisposition('cm-1', 'Paspoort', 'resolved', 'notes', 'q-2')
		);
		$this->assertSame([['id' => 'q-2', 'label' => 'Backoffice']], $service->escalationQueues());
	}//end testDispositionAndQueuesAreDelegated()
}//end class
