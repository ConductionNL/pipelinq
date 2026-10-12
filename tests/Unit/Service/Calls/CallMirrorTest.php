<?php

/**
 * Tests for the call mirror and the caller resolver under it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Calls;

use OCA\Pipelinq\Service\Calls\CallerResolver;
use OCA\Pipelinq\Service\Calls\CallMirror;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/FakeObjectStore.php';

/**
 * One row per call event, the caller resolved once, thirty days kept.
 *
 * The resolver and the phone normaliser are the real ones; the only doubles
 * are the object store and the config. What the mirror writes is validated
 * against the `mirroredCall` schema as the import composes it, with a control
 * the schema refuses.
 *
 * @covers \OCA\Pipelinq\Service\Calls\CallMirror
 * @covers \OCA\Pipelinq\Service\Calls\CallerResolver
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 * @uses   \OCA\Pipelinq\Service\ConfigFileLoaderService
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-a-call-event-is-mirrored-and-resolved-once
 */
class CallMirrorTest extends TestCase {
	/**
	 * A fixed "now".
	 */
	private const NOW = 1791813600;

	/**
	 * The in-memory object store.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * App config values for this test.
	 *
	 * @var array<string, string>
	 */
	private array $config = [
		'register' => 'reg-1',
		'mirroredCall_schema' => 'sch-call',
		'client_schema' => 'sch-client',
		'contact_schema' => 'sch-contact',
		'ticket_schema' => 'sch-ticket',
	];

	/**
	 * Set up the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new FakeObjectStore();
	}//end setUp()

	/**
	 * Build the mirror over the fake store.
	 *
	 * @return CallMirror The mirror.
	 */
	private function mirror(): CallMirror {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->store);
		$logger = new NullLogger();

		return new CallMirror(
			$appConfig,
			$container,
			new CallerResolver($appConfig, $container, new PhoneNormaliser($appConfig, $logger), $logger),
			$logger,
		);
	}//end mirror()

	/**
	 * A ringing call for Sanne de Vries, as the listener hands it over.
	 *
	 * @param array<string, mixed> $overrides Fields to change.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function call(array $overrides = []): array {
		return array_merge(
			[
				'callId' => '42',
				'kind' => 'ringing',
				'sourceId' => 'source-1',
				'callerNumber' => '+31612345678',
				'callerName' => 'Sanne de Vries',
				'openCases' => [['reference' => 'ZAAK-2026-0001', 'source' => 'dossiq'], 'ZAAK-2026-0002'],
				'agentId' => 'noor',
				'at' => '2026-10-12T14:00:00+02:00',
				'durationSeconds' => 0,
			],
			$overrides
		);
	}//end call()

	/**
	 * Seed a client, a contact with another number, and tickets.
	 *
	 * @return void
	 */
	private function seedParties(): void {
		$this->store->rows = [
			'client-sanne' => ['id' => 'client-sanne', '_schema' => 'sch-client', 'name' => 'Sanne de Vries', 'phone' => '06 12 34 56 78'],
			// Same last nine digits, another country: a different person.
			'client-other' => ['id' => 'client-other', '_schema' => 'sch-client', 'name' => 'Stefan Vogel', 'phone' => '+49612345678'],
			'contact-henk' => ['id' => 'contact-henk', '_schema' => 'sch-contact', 'name' => 'Henk de Vries', 'phone' => '+31786123341'],
			't-open' => ['id' => 't-open', '_schema' => 'sch-ticket', 'client' => 'client-sanne', 'title' => 'Afvalpas werkt niet', 'status' => 'in_progress'],
			't-new' => ['id' => 't-new', '_schema' => 'sch-ticket', 'client' => 'client-sanne', 'title' => 'Zonder status'],
			't-closed' => ['id' => 't-closed', '_schema' => 'sch-ticket', 'client' => 'client-sanne', 'title' => 'Afgerond', 'status' => 'resolved'],
			't-foreign' => ['id' => 't-foreign', '_schema' => 'sch-ticket', 'client' => 'client-other', 'title' => 'Van een ander', 'status' => 'new'],
		];
	}//end seedParties()

	/**
	 * The mirroredCall schema as the import composes it.
	 *
	 * @return object The schema as a JSON Schema object.
	 */
	private function schema(): object {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas']['mirroredCall'] ?? null);
		$this->assertIsArray($schema, 'The mirroredCall schema was not read.');
		$this->assertContains('mirroredCall', $register['components']['registers']['pipelinq']['schemas']);

		$keywords = array_intersect_key($schema, array_flip(['type', 'required', 'properties']));

		return json_decode((string)json_encode($keywords));
	}//end schema()

	/**
	 * A ringing call is written once, with the caller resolved, in system context.
	 *
	 * @return void
	 */
	public function testARingingCallIsMirroredWithTheCallerResolved(): void {
		$this->seedParties();

		$id = $this->mirror()->mirror(call: $this->call(), now: self::NOW);

		$row = $this->store->rows[$id];
		$this->assertSame('sch-call', $row['_schema']);
		$this->assertSame('42', $row['callId']);
		$this->assertSame('ringing', $row['kind']);
		$this->assertSame(gmdate('Y-m-d\TH:i:s\Z', self::NOW), $row['mirroredAt']);
		$this->assertSame([['reference' => 'ZAAK-2026-0001', 'source' => 'dossiq'], ['reference' => 'ZAAK-2026-0002']], $row['openCases']);
		$this->assertSame(
			[
				[
					'type' => 'client',
					'id' => 'client-sanne',
					'name' => 'Sanne de Vries',
					'openTickets' => [
						['id' => 't-open', 'title' => 'Afvalpas werkt niet', 'status' => 'in_progress'],
						['id' => 't-new', 'title' => 'Zonder status', 'status' => 'new'],
					],
				],
			],
			$row['inPipelinq']
		);
		$this->assertSame([], $this->store->userContextCalls, 'Every read and write runs in system context.');
	}//end testARingingCallIsMirroredWithTheCallerResolved()

	/**
	 * What the mirror writes is valid against the real schema; a wrong kind is not.
	 *
	 * @return void
	 */
	public function testThePayloadIsValidAgainstTheRealSchema(): void {
		$this->seedParties();
		$schema = $this->schema();
		$mirror = $this->mirror();
		$validator = new Validator();

		$payloads = [
			$mirror->payload(call: $this->call(), now: self::NOW),
			// A withheld number, an unknown caller, an ended call.
			$mirror->payload(call: $this->call(['callerNumber' => '', 'callerName' => '', 'openCases' => [], 'kind' => 'ended', 'durationSeconds' => 95, 'agentId' => '', 'at' => '']), now: self::NOW),
			// A number that is not E.164 is stored as withheld, never as typed.
			$mirror->payload(call: $this->call(['callerNumber' => '0612345678']), now: self::NOW),
		];
		foreach ($payloads as $payload) {
			$result = $validator->validate(json_decode((string)json_encode($payload)), $schema);
			$this->assertTrue($result->isValid(), 'The schema refuses what the mirror writes: ' . json_encode($payload));
		}

		$this->assertSame('', $payloads[2]['callerNumber']);
		$this->assertSame([], $payloads[2]['inPipelinq']);

		$control = array_merge($payloads[0], ['kind' => 'voicemail']);
		$this->assertFalse($validator->validate(json_decode((string)json_encode($control)), $schema)->isValid(), 'Control: the schema refuses an unknown kind.');
		$control = $payloads[0];
		unset($control['mirroredAt']);
		$this->assertFalse($validator->validate(json_decode((string)json_encode($control)), $schema)->isValid(), 'Control: the schema requires mirroredAt.');
	}//end testThePayloadIsValidAgainstTheRealSchema()

	/**
	 * A repeated delivery writes nothing; another kind of the same call writes a second row.
	 *
	 * @return void
	 */
	public function testARepeatedDeliveryWritesNothing(): void {
		$mirror = $this->mirror();

		$first = $mirror->mirror(call: $this->call(), now: self::NOW);
		// A real store hands a row's fields back in its own order.
		$this->store->rows[$first] = array_reverse($this->store->rows[$first], true);
		$again = $mirror->mirror(call: $this->call(), now: (self::NOW + 5));
		$answered = $mirror->mirror(call: $this->call(['kind' => 'answered']), now: (self::NOW + 9));
		$otherSource = $mirror->mirror(call: $this->call(['sourceId' => 'source-2']), now: (self::NOW + 9));

		$this->assertSame($first, $again);
		$this->assertNotSame($first, $answered);
		$this->assertNotSame($first, $otherSource);
		$this->assertCount(3, $this->store->rows);
		$this->assertSame(gmdate('Y-m-d\TH:i:s\Z', self::NOW), $this->store->rows[$first]['mirroredAt']);
	}//end testARepeatedDeliveryWritesNothing()

	/**
	 * A store that ignores the filters does not make a new event look delivered.
	 *
	 * @return void
	 */
	public function testAStoreThatIgnoresFiltersDoesNotHideANewEvent(): void {
		$mirror = $this->mirror();
		$first = $mirror->mirror(call: $this->call(), now: self::NOW);
		$this->store->ignoresFilters = true;

		$second = $mirror->mirror(call: $this->call(['callId' => '43']), now: self::NOW);

		$this->assertNotSame($first, $second);
		$this->assertSame('43', $this->store->rows[$second]['callId']);
	}//end testAStoreThatIgnoresFiltersDoesNotHideANewEvent()

	/**
	 * An event without a call id, a source or a known kind is refused.
	 *
	 * @return void
	 */
	public function testAnIncompleteEventIsRefused(): void {
		$mirror = $this->mirror();

		$this->assertNull($mirror->mirror(call: $this->call(['callId' => ' '])));
		$this->assertNull($mirror->mirror(call: $this->call(['sourceId' => ''])));
		$this->assertNull($mirror->mirror(call: $this->call(['kind' => 'voicemail'])));
		$this->assertSame([], $this->store->rows);
	}//end testAnIncompleteEventIsRefused()

	/**
	 * Without a configured schema nothing is written and nothing is purged.
	 *
	 * @return void
	 */
	public function testWithoutAConfiguredSchemaNothingHappens(): void {
		unset($this->config['mirroredCall_schema']);
		$mirror = $this->mirror();

		$this->assertNull($mirror->mirror(call: $this->call()));
		$this->assertSame(0, $mirror->purgeExpired(now: self::NOW));
		$this->assertSame([], $this->store->rows);
	}//end testWithoutAConfiguredSchemaNothingHappens()

	/**
	 * A store that throws costs that one row and is not passed on.
	 *
	 * @return void
	 */
	public function testAFailingStoreIsNotPassedOn(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('OpenRegister is not installed'));
		$logger = new NullLogger();
		$mirror = new CallMirror($appConfig, $container, new CallerResolver($appConfig, $container, new PhoneNormaliser($appConfig, $logger), $logger), $logger);

		$this->assertNull($mirror->mirror(call: $this->call()));
		$this->assertSame(0, $mirror->purgeExpired(now: self::NOW));
	}//end testAFailingStoreIsNotPassedOn()

	/**
	 * Rows older than thirty days go for good; newer and undated rows stay.
	 *
	 * @return void
	 */
	public function testRetentionRemovesOnlyWhatIsPastThirtyDays(): void {
		$day = 86400;
		$stamp = static fn (int $time): string => gmdate('Y-m-d\TH:i:s\Z', $time);
		$this->store->rows = [
			'old' => ['id' => 'old', '_schema' => 'sch-call', 'mirroredAt' => $stamp(self::NOW - (31 * $day))],
			'edge' => ['id' => 'edge', '_schema' => 'sch-call', 'mirroredAt' => $stamp(self::NOW - (30 * $day))],
			'new' => ['id' => 'new', '_schema' => 'sch-call', 'mirroredAt' => $stamp(self::NOW - $day)],
			'undated' => ['id' => 'undated', '_schema' => 'sch-call', 'mirroredAt' => ''],
			'ticket' => ['id' => 'ticket', '_schema' => 'sch-ticket', 'mirroredAt' => $stamp(self::NOW - (90 * $day))],
		];

		$removed = $this->mirror()->purgeExpired(now: self::NOW);

		$this->assertSame(1, $removed);
		$this->assertSame(['old' => true], $this->store->deleted, 'Only the old call goes, and permanently.');
		$this->assertSame(['edge', 'new', 'undated', 'ticket'], array_keys($this->store->rows));
		$this->assertSame([], $this->store->userContextCalls);
	}//end testRetentionRemovesOnlyWhatIsPastThirtyDays()

	/**
	 * A contact matches too, and a withheld number matches nobody.
	 *
	 * @return void
	 */
	public function testAContactMatchesAndAWithheldNumberMatchesNobody(): void {
		$this->seedParties();
		$mirror = $this->mirror();

		$henk = $mirror->payload(call: $this->call(['callerNumber' => '+31786123341']), now: self::NOW);
		$withheld = $mirror->payload(call: $this->call(['callerNumber' => '']), now: self::NOW);

		$this->assertSame([['type' => 'contact', 'id' => 'contact-henk', 'name' => 'Henk de Vries', 'openTickets' => []]], $henk['inPipelinq']);
		$this->assertSame([], $withheld['inPipelinq']);
	}//end testAContactMatchesAndAWithheldNumberMatchesNobody()

	/**
	 * A store that ignores the ticket filter does not put another client's ticket beside the caller.
	 *
	 * @return void
	 */
	public function testAnotherClientsTicketIsNeverListed(): void {
		$this->seedParties();
		$this->store->ignoresFilters = true;

		$payload = $this->mirror()->payload(call: $this->call(), now: self::NOW);

		$this->assertSame(['t-open', 't-new'], array_column($payload['inPipelinq'][0]['openTickets'], 'id'));
	}//end testAnotherClientsTicketIsNeverListed()
}//end class
