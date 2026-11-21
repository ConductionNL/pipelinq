<?php

/**
 * Tests for DialLineReader.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Cti;

use OCA\Pipelinq\Service\Cti\DialLineReader;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Tests\Unit\Service\Calls\FakeObjectStore;
use OCA\Pipelinq\Tests\Unit\Support\FakeBackgroundAccount;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * An agent's dial line: off without a platform, a switch or an extension;
 * on with the agent's own extension, busy during a call.
 *
 * @covers \OCA\Pipelinq\Service\Cti\DialLineReader
 * @uses   \OCA\Pipelinq\Service\BackgroundServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccountUnavailableException
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
 */
class DialLineReaderTest extends TestCase {
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
	private array $appValues = ['register' => 'reg-1', 'ctiAgentPresence_schema' => 'sch-presence'];

	/**
	 * The stored telephony configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $cti = ['platform' => 'asterisk', 'click_to_dial_enabled' => true];

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
	 * @return DialLineReader The reader.
	 */
	private function reader(): DialLineReader {
		$cti = $this->createMock(CtiService::class);
		$cti->method('loadConfig')->willReturnCallback(fn (): array => $this->cti);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->appValues[$key] ?? $default)
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($this->store);

		return new DialLineReader($cti, $appConfig, $container, $this->world->account, new NullLogger());
	}//end reader()

	/**
	 * Add one agent presence row, as CtiService::syncPresence() stores it.
	 *
	 * @param string $userId The agent.
	 * @param string $extension The extension.
	 * @param string $state The presence state.
	 *
	 * @return void
	 */
	private function presence(string $userId, string $extension, string $state = 'available'): void {
		$this->store->rows['p-' . $userId] = [
			'id' => 'p-' . $userId,
			'_schema' => 'sch-presence',
			'user_id' => $userId,
			'extension' => $extension,
			'presence_state' => $state,
			'last_updated_at' => '2026-11-21T10:00:00Z',
			'platform' => 'asterisk',
		];
	}//end presence()

	/**
	 * An available agent with an extension gets that extension, read as the service account.
	 *
	 * @return void
	 */
	public function testAnAvailableAgentGetsTheirExtension(): void {
		$this->presence(userId: 'noor', extension: '101');

		$this->assertSame(['enabled' => true, 'extension' => '101', 'busy' => false], $this->reader()->forAgent(userId: 'noor'));
		$this->assertSame(
			[['op' => 'findAll', 'schema' => 'sch-presence', 'rbac' => true, 'multitenancy' => false, 'as' => FakeBackgroundAccount::UID]],
			$this->store->calls
		);
		$this->assertNull($this->world->actingUid(), 'The session is back to nobody after the read.');
	}//end testAnAvailableAgentGetsTheirExtension()

	/**
	 * During a call and its wrap-up the line is busy.
	 *
	 * @return void
	 */
	public function testOnACallOrInWrapUpTheLineIsBusy(): void {
		$this->presence(userId: 'noor', extension: '101', state: 'on-call');
		$this->assertSame(['enabled' => true, 'extension' => '101', 'busy' => true], $this->reader()->forAgent(userId: 'noor'));

		$this->presence(userId: 'noor', extension: '101', state: 'wrap-up');
		$this->assertTrue($this->reader()->forAgent(userId: 'noor')['busy']);

		$this->presence(userId: 'noor', extension: '101', state: 'away');
		$this->assertFalse($this->reader()->forAgent(userId: 'noor')['busy']);
	}//end testOnACallOrInWrapUpTheLineIsBusy()

	/**
	 * Without a configured platform the line is off and no presence is read.
	 *
	 * @return void
	 */
	public function testWithoutAPlatformTheLineIsOff(): void {
		$this->presence(userId: 'noor', extension: '101');
		$this->cti = [];

		$this->assertSame(['enabled' => false, 'extension' => '', 'busy' => false], $this->reader()->forAgent(userId: 'noor'));
		$this->assertSame([], $this->store->calls);
	}//end testWithoutAPlatformTheLineIsOff()

	/**
	 * An administrator who switched click-to-dial off switches the line off.
	 *
	 * @return void
	 */
	public function testClickToDialSwitchedOffTurnsTheLineOff(): void {
		$this->presence(userId: 'noor', extension: '101');
		$this->cti['click_to_dial_enabled'] = false;

		$this->assertFalse($this->reader()->forAgent(userId: 'noor')['enabled']);
		$this->assertSame([], $this->store->calls);
	}//end testClickToDialSwitchedOffTurnsTheLineOff()

	/**
	 * An agent the platform never reported, or reported without an extension, has no line.
	 *
	 * @return void
	 */
	public function testAnAgentWithoutAnExtensionHasNoLine(): void {
		$this->assertFalse($this->reader()->forAgent(userId: 'noor')['enabled']);

		$this->presence(userId: 'noor', extension: '  ');
		$this->assertFalse($this->reader()->forAgent(userId: 'noor')['enabled']);
	}//end testAnAgentWithoutAnExtensionHasNoLine()

	/**
	 * A store that ignores the filter does not hand an agent a colleague's extension.
	 *
	 * @return void
	 */
	public function testAColleaguesExtensionIsNeverHandedOn(): void {
		$this->presence(userId: 'sem', extension: '202');
		$this->store->ignoresFilters = true;

		$this->assertSame(['enabled' => false, 'extension' => '', 'busy' => false], $this->reader()->forAgent(userId: 'noor'));
	}//end testAColleaguesExtensionIsNeverHandedOn()

	/**
	 * No usable service account, no presence schema, an anonymous caller or a
	 * failing store: the line is off and nothing throws.
	 *
	 * @return void
	 */
	public function testAPresenceThatCannotBeReadLeavesTheLineOff(): void {
		$this->presence(userId: 'noor', extension: '101');

		$this->assertFalse($this->reader()->forAgent(userId: '')['enabled']);

		$this->appValues['ctiAgentPresence_schema'] = '';
		$this->assertFalse($this->reader()->forAgent(userId: 'noor')['enabled']);
		$this->appValues['ctiAgentPresence_schema'] = 'sch-presence';

		$this->store->acting = static fn (): ?string => 'someone-else';
		$this->assertFalse($this->reader()->forAgent(userId: 'noor')['enabled'], 'A refused read is logged, not thrown.');

		$this->world->members = [];
		$this->assertFalse($this->reader()->forAgent(userId: 'noor')['enabled']);
	}//end testAPresenceThatCannotBeReadLeavesTheLineOff()
}//end class
