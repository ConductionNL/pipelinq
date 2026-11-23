<?php

/**
 * The one way a mirrored call reaches a signed-in user.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-incoming-call-card-also-shows-a-mirrored-call
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Calls;

use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCA\Pipelinq\Service\Calls\MirroredCallReader;
use OCA\Pipelinq\Tests\Unit\Support\FakeBackgroundAccount;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * An agent gets their own open call, its number and its times, and nothing else.
 *
 * @covers \OCA\Pipelinq\Service\Calls\MirroredCallReader
 * @uses   \OCA\Pipelinq\Service\BackgroundServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccountUnavailableException
 */
class MirroredCallReaderTest extends TestCase {
	private const NOW = 1791813600;

	/**
	 * The store.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The Nextcloud around the background service account.
	 *
	 * @var FakeBackgroundAccount
	 */
	private FakeBackgroundAccount $world;

	/**
	 * App config values.
	 *
	 * @var array<string, string>
	 */
	private array $config = ['register' => 'reg-1', 'mirroredCall_schema' => 'sch-call'];

	/**
	 * Set up the store and the account.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->world = (new FakeBackgroundAccount($this))->usable();
		$this->store = new FakeObjectStore();
		$this->store->acting = fn (): ?string => $this->world->actingUid();
		$this->store->onlyFor = FakeBackgroundAccount::UID;
	}//end setUp()

	/**
	 * Build the reader over the fake store.
	 *
	 * @return MirroredCallReader The reader.
	 */
	private function reader(): MirroredCallReader {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->store);

		return new MirroredCallReader($appConfig, $container, $this->world->account, new NullLogger());
	}//end reader()

	/**
	 * Add one call event to the mirror, as CallMirror stores it.
	 *
	 * @param string $id The row id.
	 * @param string $callId The call.
	 * @param string $kind The kind of event.
	 * @param string $agentId The agent it is for.
	 * @param int $ago Seconds before now it was mirrored.
	 * @param string $number The caller's number.
	 *
	 * @return void
	 */
	private function event(string $id, string $callId, string $kind, string $agentId, int $ago, string $number = '+31612345678'): void {
		$this->store->rows[$id] = [
			'id' => $id,
			'_schema' => 'sch-call',
			'callId' => $callId,
			'kind' => $kind,
			'sourceId' => 'source-1',
			'callerNumber' => $number,
			'callerName' => 'Sanne de Vries',
			'agentId' => $agentId,
			'openCases' => [['reference' => 'ZAAK-2026-0001', 'source' => 'dossiq']],
			'inPipelinq' => [['type' => 'client', 'id' => 'client-sanne', 'name' => 'Sanne de Vries', 'openTickets' => [['id' => 't-1', 'title' => 'Afvalpas werkt niet', 'status' => 'new']]]],
			'mirroredAt' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - $ago)),
		];
	}//end event()

	/**
	 * A ringing call of the agent comes back with its number and times only, read as the service account.
	 *
	 * @return void
	 */
	public function testAnAgentGetsTheirRingingCallAndNothingTheMirrorResolved(): void {
		$this->event(id: 'r1', callId: '42', kind: 'ringing', agentId: 'noor', ago: 9);
		$this->event(id: 'a1', callId: '42', kind: 'answered', agentId: 'noor', ago: 4);

		$call = $this->reader()->openCallFor(agentId: 'noor', now: self::NOW);

		$this->assertSame(
			[
				'id' => 'r1',
				'fromNumber' => '+31612345678',
				'startedAt' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 9)),
				'answeredAt' => gmdate('Y-m-d\TH:i:s\Z', (self::NOW - 4)),
			],
			$call,
			'No name, no case, no client and no ticket the mirror read without access checks.'
		);
		$this->assertSame(
			[['op' => 'findAll', 'schema' => 'sch-call', 'rbac' => true, 'multitenancy' => false, 'as' => FakeBackgroundAccount::UID]],
			$this->store->calls
		);
		$this->assertNull($this->world->actingUid());
	}//end testAnAgentGetsTheirRingingCallAndNothingTheMirrorResolved()

	/**
	 * A colleague's call is never handed over, also when the store ignores the filter.
	 *
	 * @return void
	 */
	public function testAColleaguesCallIsNeverHandedOver(): void {
		$this->event(id: 'r1', callId: '42', kind: 'ringing', agentId: 'henk', ago: 9);
		$reader = $this->reader();

		$this->assertNull($reader->openCallFor(agentId: 'noor', now: self::NOW));

		$this->store->ignoresFilters = true;
		$this->assertNull($reader->openCallFor(agentId: 'noor', now: self::NOW));
		$this->assertSame('r1', $reader->openCallFor(agentId: 'henk', now: self::NOW)['id'], 'Control: the row is there for its own agent.');
	}//end testAColleaguesCallIsNeverHandedOver()

	/**
	 * An ended or transferred call, a call older than two hours and a call that never rang are not incoming.
	 *
	 * @return void
	 */
	public function testClosedOldAndNeverRungCallsAreNotIncoming(): void {
		$this->event(id: 'r1', callId: 'ended', kind: 'ringing', agentId: 'noor', ago: 60);
		$this->event(id: 'e1', callId: 'ended', kind: 'ended', agentId: 'noor', ago: 30);
		$this->event(id: 'r2', callId: 'moved', kind: 'ringing', agentId: 'noor', ago: 50);
		$this->event(id: 't2', callId: 'moved', kind: 'transferred', agentId: 'noor', ago: 20);
		$this->event(id: 'r3', callId: 'old', kind: 'ringing', agentId: 'noor', ago: (MirroredCallReader::MAX_AGE_SECONDS + 1));
		$this->event(id: 'a4', callId: 'no-ring', kind: 'answered', agentId: 'noor', ago: 5);

		$this->assertNull($this->reader()->openCallFor(agentId: 'noor', now: self::NOW));

		// Control: one open call beside them is found, and the newest of two wins.
		$this->event(id: 'r5', callId: 'open-1', kind: 'ringing', agentId: 'noor', ago: 40, number: '+31201234567');
		$this->event(id: 'r6', callId: 'open-2', kind: 'ringing', agentId: 'noor', ago: 10, number: '+31301234567');
		$this->assertSame('+31301234567', $this->reader()->openCallFor(agentId: 'noor', now: self::NOW)['fromNumber']);
	}//end testClosedOldAndNeverRungCallsAreNotIncoming()

	/**
	 * Without a usable account, a schema or an agent the mirror is not read and nothing is logged.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountASchemaOrAnAgentNothingIsRead(): void {
		$this->event(id: 'r1', callId: '42', kind: 'ringing', agentId: 'noor', ago: 9);
		$this->assertTrue($this->reader()->isActive());
		$this->assertNull($this->reader()->openCallFor(agentId: '', now: self::NOW));

		unset($this->world->config[BackgroundServiceAccount::CONFIG_KEY]);
		$this->assertFalse($this->reader()->isActive());
		$this->assertNull($this->reader()->openCallFor(agentId: 'noor', now: self::NOW));
		$this->assertSame([], $this->world->logged, 'The card asks every few seconds: a missing account is not logged here.');

		$this->world->usable();
		foreach (['mirroredCall_schema', 'register'] as $key) {
			$kept = $this->config[$key];
			unset($this->config[$key]);
			$this->assertFalse($this->reader()->isActive(), $key);
			$this->assertNull($this->reader()->openCallFor(agentId: 'noor', now: self::NOW));
			$this->config[$key] = $kept;
		}

		$this->assertSame([], $this->store->calls);
	}//end testWithoutAnAccountASchemaOrAnAgentNothingIsRead()

	/**
	 * A read OpenRegister refuses gives no call and is not passed on.
	 *
	 * @return void
	 */
	public function testARefusedReadGivesNoCall(): void {
		$this->event(id: 'r1', callId: '42', kind: 'ringing', agentId: 'noor', ago: 9);
		$this->store->onlyFor = 'somebody-else';

		$this->assertNull($this->reader()->openCallFor(agentId: 'noor', now: self::NOW));
		$this->assertNull($this->world->actingUid());
	}//end testARefusedReadGivesNoCall()
}//end class
