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
use OCA\Pipelinq\Service\Calls\MirroredCallRow;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Tests\Unit\Support\FakeBackgroundAccount;
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
 * @covers \OCA\Pipelinq\Service\Calls\MirroredCallRow
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 * @uses   \OCA\Pipelinq\Service\BackgroundServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccountUnavailableException
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
	/**
	 * The Nextcloud around the background service account.
	 *
	 * @var FakeBackgroundAccount
	 */
	private FakeBackgroundAccount $world;

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
		$this->world = (new FakeBackgroundAccount($this))->usable();
		$this->store = new FakeObjectStore();
		$this->store->acting = fn (): ?string => $this->world->actingUid();
		// As OpenRegister: with access checks on, only the service account gets through.
		$this->store->onlyFor = FakeBackgroundAccount::UID;
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
			new MirroredCallRow(),
			$this->world->account,
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
		$this->assertSame([['reference' => 'ZAAK-2026-0001', 'source' => 'dossiq'], ['reference' => 'ZAAK-2026-0002', 'source' => '']], $row['openCases']);
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

		// The write and the repeat check ran with access checks ON, as the service account.
		$this->assertSame(
			[['op' => 'saveObject', 'schema' => 'sch-call', 'rbac' => true, 'multitenancy' => false, 'as' => FakeBackgroundAccount::UID]],
			$this->store->callsOf(op: 'saveObject')
		);
		$this->assertSame(
			[['op' => 'findAll', 'schema' => 'sch-call', 'rbac' => true, 'multitenancy' => false, 'as' => FakeBackgroundAccount::UID]],
			$this->store->callsOf(op: 'findAll', schema: 'sch-call')
		);
		// Only reads go without access checks: the lookup of who has this number.
		foreach ($this->store->calls as $call) {
			if ($call['rbac'] === false) {
				$this->assertSame('findAll', $call['op']);
				$this->assertContains($call['schema'], ['sch-client', 'sch-contact', 'sch-ticket']);
			}
		}

		// The caller of mirror() gets an id and nothing else, and nobody is left impersonated.
		$this->assertIsString($id);
		$this->assertNull($this->world->actingUid());
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
		$mirror = new CallMirror($appConfig, $container, new CallerResolver($appConfig, $container, new PhoneNormaliser($appConfig, $logger), $logger), new MirroredCallRow(), $this->world->account, $logger);

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
		$this->assertSame(
			[['op' => 'deleteObject', 'schema' => 'sch-call', 'rbac' => true, 'multitenancy' => false, 'as' => FakeBackgroundAccount::UID]],
			$this->store->callsOf(op: 'deleteObject')
		);
		$this->assertSame(
			[['op' => 'findAll', 'schema' => 'sch-call', 'rbac' => true, 'multitenancy' => false, 'as' => FakeBackgroundAccount::UID]],
			$this->store->callsOf(op: 'findAll')
		);
		$this->assertNull($this->world->actingUid());
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
	/**
	 * Without a usable service account the mirror reads nothing, writes nothing, removes nothing, and the log says why.
	 *
	 * @return void
	 */
	public function testWithoutAServiceAccountTheMirrorDoesNothingAndSaysSo(): void {
		$this->seedParties();
		$old = gmdate('Y-m-d\TH:i:s\Z', (self::NOW - (90 * 86400)));
		$this->store->rows['old'] = ['id' => 'old', '_schema' => 'sch-call', 'mirroredAt' => $old];
		$before = $this->store->rows;
		unset($this->world->config[\OCA\Pipelinq\Service\BackgroundServiceAccount::CONFIG_KEY]);
		$mirror = $this->mirror();

		$this->assertNull($mirror->mirror(call: $this->call(), now: self::NOW));
		$this->assertSame(0, $mirror->purgeExpired(now: self::NOW));

		$this->assertSame([], $this->store->calls, 'Not one call reaches OpenRegister, with or without access checks.');
		$this->assertSame($before, $this->store->rows);
		$this->assertCount(2, $this->world->logged);
		foreach ($this->world->logged as [$level, $message, $context]) {
			$this->assertSame('warning', $level);
			$this->assertStringContainsString('no usable background service account', $message);
			$this->assertSame('unset', $context['reason']);
			$this->assertStringNotContainsString('+31612345678', json_encode([$message, $context]));
		}
	}//end testWithoutAServiceAccountTheMirrorDoesNothingAndSaysSo()

	/**
	 * Control for the test above: OpenRegister refusing the account costs the row, and nothing retries without checks.
	 *
	 * @return void
	 */
	public function testARefusedWriteIsNotRetriedWithoutAccessChecks(): void {
		// The schema grants somebody else: the service account is refused, as an ungranted user is.
		$this->store->onlyFor = 'somebody-else';

		$this->assertNull($this->mirror()->mirror(call: $this->call(), now: self::NOW));

		$this->assertSame([], $this->store->rows);
		$this->assertSame([], $this->store->callsOf(op: 'saveObject'));
		foreach ($this->store->calls as $call) {
			$this->assertTrue($call['rbac'], 'After a refusal nothing runs without access checks.');
		}
	}//end testARefusedWriteIsNotRetriedWithoutAccessChecks()

	/**
	 * The schema grants the background service group create, read and delete, and grants nobody else anything.
	 *
	 * @return void
	 */
	public function testTheSchemaGrantsOnlyTheBackgroundServiceGroup(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$schemas = (new ConfigFileLoaderService($appManager))->loadConfigurationFile()['components']['schemas'];
		$group = \OCA\Pipelinq\Service\BackgroundServiceAccount::GROUP;

		$this->assertSame(
			['create' => [$group], 'read' => [$group], 'update' => [], 'delete' => [$group]],
			$schemas['mirroredCall']['authorization']
		);

		// Rights on the mirror only: no other schema of the composed register names the group.
		foreach ($schemas as $slug => $schema) {
			if ($slug !== 'mirroredCall') {
				$this->assertStringNotContainsString($group, (string)json_encode(($schema['authorization'] ?? [])), $slug);
			}
		}
	}//end testTheSchemaGrantsOnlyTheBackgroundServiceGroup()

	/**
	 * What a hostile event carries: each one is refused or lands inside the real schema's bounds.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function hostileEvents(): array {
		$long = str_repeat('x', 5000);
		return [
			'long text everywhere' => [['callerName' => $long, 'agentId' => $long, 'at' => $long, 'callerNumber' => '+' . str_repeat('3', 40)]],
			'control characters in the name' => [['callerName' => "Sanne\x00\x1b[31m de\r\nVries"]],
			'markup in the name' => [['callerName' => '<script>alert(1)</script>']],
			'arrays where text belongs' => [['callerName' => ['a'], 'agentId' => ['b'], 'at' => ['c'], 'callerNumber' => ['+31612345678'], 'durationSeconds' => ['9']]],
			'a number that is not E.164' => [['callerNumber' => "+31612345678\n+31600000000"]],
			'a moment that is not a moment' => [['at' => '2026-13-45T99:99:99Z']],
			'a negative and a huge duration' => [['durationSeconds' => -5]],
			'a duration past two days' => [['durationSeconds' => PHP_INT_MAX]],
			'a duration as text' => [['durationSeconds' => '1e9']],
			'two hundred cases' => [['openCases' => array_fill(0, 200, ['reference' => 'ZAAK-1', 'source' => 'dossiq'])]],
			'a party inside the cases' => [['openCases' => [['reference' => 'ZAAK-1', 'source' => 'dossiq', 'bsn' => '123456782', 'partij' => ['naam' => 'X']], ['bsn' => '123456782'], 42, null, ['reference' => $long]]]],
			'an agent that is no user id' => [['agentId' => '../../admin<script>']],
		];
	}//end hostileEvents()

	/**
	 * A hostile event writes one row that the real schema accepts, bounded in every field.
	 *
	 * @param array<string, mixed> $overrides The hostile fields.
	 *
	 * @return void
	 *
	 * @dataProvider hostileEvents
	 */
	public function testAHostileEventStaysInsideTheRealSchema(array $overrides): void {
		$this->seedParties();
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$fragment = (new ConfigFileLoaderService($appManager))->loadConfigurationFile()['components']['schemas']['mirroredCall'];
		$schema = json_decode((string)json_encode(array_merge(array_intersect_key($fragment, array_flip(['type', 'required', 'properties'])), ['additionalProperties' => false])));

		$id = $this->mirror()->mirror(call: $this->call($overrides), now: self::NOW);

		$this->assertIsString($id);
		$this->assertCount(1, $this->store->callsOf(op: 'saveObject'), 'One event writes one row.');
		$row = $this->store->rows[$id];
		unset($row['id'], $row['_schema']);
		$result = (new Validator())->validate(json_decode((string)json_encode($row)), $schema);
		$this->assertTrue($result->isValid(), 'The real schema refuses the row: ' . json_encode($row));

		$this->assertLessThan(8000, strlen((string)json_encode($row)), 'A row has a bounded size.');
		$this->assertLessThanOrEqual(MirroredCallRow::MAX_OPEN_CASES, count($row['openCases']));
		$this->assertSame(0, preg_match('/\p{C}/u', $row['callerName']));
		foreach ($row['openCases'] as $case) {
			$this->assertSame(['reference', 'source'], array_keys($case), 'Only the reference and where it lives.');
		}

		$this->assertStringNotContainsString('123456782', (string)json_encode($row), 'No citizen service number is stored.');
	}//end testAHostileEventStaysInsideTheRealSchema()

	/**
	 * The mirror's own bounds are the schema's: a change on one side turns this red.
	 *
	 * @return void
	 */
	public function testTheMirrorsBoundsAreTheSchemas(): void {
		$properties = json_decode((string)json_encode($this->schema()->properties), true);

		$this->assertSame(CallMirror::KINDS, $properties['kind']['enum']);
		$this->assertSame(MirroredCallRow::MAX_TEXT, $properties['callId']['maxLength']);
		$this->assertSame(MirroredCallRow::MAX_TEXT, $properties['sourceId']['maxLength']);
		$this->assertSame(MirroredCallRow::MAX_TEXT, $properties['callerName']['maxLength']);
		$this->assertSame(MirroredCallRow::MAX_TEXT, $properties['openCases']['items']['properties']['reference']['maxLength']);
		$this->assertSame(MirroredCallRow::MAX_OPEN_CASES, $properties['openCases']['maxItems']);
		$this->assertSame(MirroredCallRow::MAX_MATCHES, $properties['inPipelinq']['maxItems']);
		$this->assertSame(MirroredCallRow::MAX_DURATION, $properties['durationSeconds']['maximum']);
		$this->assertSame(64, $properties['agentId']['maxLength']);
		$this->assertSame(64, $properties['at']['maxLength']);
	}//end testTheMirrorsBoundsAreTheSchemas()

	/**
	 * A call id or source that cannot be a key is refused, never cut; the number is stored once.
	 *
	 * @return void
	 */
	public function testAKeyThatCannotBeAKeyIsRefusedAndTheNumberIsStoredOnce(): void {
		$this->seedParties();
		$mirror = $this->mirror();

		$this->assertNull($mirror->mirror(call: $this->call(['callId' => str_repeat('9', 256)])));
		$this->assertNull($mirror->mirror(call: $this->call(['callId' => "4\x1b2"])));
		$this->assertNull($mirror->mirror(call: $this->call(['sourceId' => ['source-1']])));
		$this->assertNull($mirror->mirror(call: $this->call(['kind' => ['ringing']])));
		$this->assertSame([], $this->store->calls, 'A refused event reaches nothing.');

		$id = $mirror->mirror(call: $this->call(), now: self::NOW);
		$row = $this->store->rows[$id];
		$this->assertSame('+31612345678', $row['callerNumber']);
		$this->assertSame(1, substr_count((string)json_encode($row), '31612345678'), 'The number sits in callerNumber and nowhere else.');
		$this->assertStringNotContainsString('06 12 34 56 78', (string)json_encode($row));
	}//end testAKeyThatCannotBeAKeyIsRefusedAndTheNumberIsStoredOnce()
}//end class
