<?php

/**
 * SocialInboxService tests: a visitor message reaches the intake on its
 * conversation, a page message does not, the cursor keeps a message from
 * being taken in twice, and a refusal lands in messagingStatus while the
 * posting status stays as it was.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-facebook-and-instagram-messages-reach-the-queue-req-itq-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Social;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\InboundTicketService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCA\Pipelinq\Service\Social\FacebookPageAdapter;
use OCA\Pipelinq\Service\Social\InstagramBusinessAdapter;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialBrokerGateway;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialInboxService;
use OCA\Pipelinq\Service\SocialAccountService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see SocialInboxService}.
 *
 * @covers \OCA\Pipelinq\Service\Social\SocialInboxService
 * @covers \OCA\Pipelinq\Service\Social\ReadsMetaConversations
 * @uses   \OCA\Pipelinq\Service\SocialAccountService
 * @uses   \OCA\Pipelinq\Service\Social\FacebookPageAdapter
 * @uses   \OCA\Pipelinq\Service\Social\InstagramBusinessAdapter
 * @uses   \OCA\Pipelinq\Service\Social\AbstractSocialAdapter
 * @uses   \OCA\Pipelinq\Service\Social\SocialAdapterCall
 * @uses   \OCA\Pipelinq\Service\Social\SocialGatewayResult
 * @uses   \OCA\Pipelinq\Service\ListObjectStore
 */
final class SocialInboxServiceTest extends TestCase {

	/** @var InMemoryObjectStore */
	private InMemoryObjectStore $store;

	/** @var SocialBrokerGateway&MockObject */
	private SocialBrokerGateway $gateway;

	/** @var InboundTicketService&MockObject */
	private InboundTicketService $intake;

	/** @var IAppConfig&MockObject */
	private IAppConfig $config;

	/** @var array<string, string> */
	private array $cursors = [];

	/** @var list<array<string, mixed>> */
	private array $received = [];

	/** @var list<array{method: string, path: string, actingUserId: ?string}> */
	private array $calls = [];

	/**
	 * The service.
	 *
	 * @var SocialInboxService
	 */
	private SocialInboxService $service;

	/**
	 * Build the subject and its collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectStore(
			$this->createMock(originalClassName: ContainerInterface::class),
			$this->createMock(originalClassName: IAppConfig::class),
			$this->createMock(originalClassName: LoggerInterface::class),
		);
		$accounts = new SocialAccountService(
			store: $this->store,
			registry: $this->createMock(originalClassName: SocialAdapterRegistry::class),
			credentials: $this->createMock(originalClassName: BrokerCredentialReader::class),
			policy: $this->createMock(originalClassName: ObjectOwnerAccessPolicy::class),
			groupManager: $this->createMock(originalClassName: IGroupManager::class),
			notifications: $this->createMock(originalClassName: NotificationService::class),
		);

		$this->gateway = $this->createMock(originalClassName: SocialBrokerGateway::class);
		$this->intake = $this->createMock(originalClassName: InboundTicketService::class);
		$this->intake->method('tryReceive')->willReturnCallback(function (array $message): string {
			$this->received[] = $message;
			return 'ticket-1';
		});
		$this->config = $this->createMock(originalClassName: IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($this->cursors[$key] ?? $default)
		);
		$this->config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->cursors[$key] = $value;
			return true;
		});

		$this->service = new SocialInboxService(
			accounts: $accounts,
			facebook: new FacebookPageAdapter(gateway: $this->gateway),
			instagram: new InstagramBusinessAdapter(gateway: $this->gateway),
			gateway: $this->gateway,
			intake: $this->intake,
			appConfig: $this->config,
		);
	}

	/**
	 * Seed one connected account.
	 *
	 * @param string               $id        The account id.
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return void
	 */
	private function account(string $id, array $overrides = []): void {
		$this->store->seed('socialAccount', $id, array_merge([
			'network' => 'facebook',
			'handle' => 'Gemeente Voorbeeld',
			'externalAccountId' => 'page-1',
			'credentialRef' => 'cred-1',
			'ownerUserId' => 'marieke',
			'status' => 'active',
			'statusReason' => '',
			'active' => true,
		], $overrides));
	}

	/**
	 * The gateway answers every read with this result.
	 *
	 * @param SocialGatewayResult $result The answer.
	 *
	 * @return void
	 */
	private function answer(SocialGatewayResult $result): void {
		$this->gateway->method('request')->willReturnCallback(
			function (
				string $credentialRef,
				string $method,
				string $path,
				array $headers = [],
				?string $body = null,
				?string $actingUserId = null
			) use ($result): SocialGatewayResult {
				$this->calls[] = ['method' => $method, 'path' => $path, 'actingUserId' => $actingUserId];
				return $result;
			}
		);
	}

	/**
	 * A conversations answer with these messages on conversation `t_1`.
	 *
	 * @param list<array<string, mixed>> $messages The Graph messages.
	 *
	 * @return SocialGatewayResult
	 */
	private static function conversations(array $messages): SocialGatewayResult {
		return SocialGatewayResult::succeeded(200, [
			'data' => [
				['id' => 't_1', 'updated_time' => '2026-11-17T10:05:00+0000', 'messages' => ['data' => $messages]],
			],
		]);
	}

	/**
	 * A new visitor message reaches the intake on its conversation.
	 *
	 * @return void
	 */
	public function testANewVisitorMessageReachesTheIntakeOnItsConversation(): void {
		$this->account(id: 'acc-1');
		$this->answer(result: self::conversations(messages: [
			[
				'id' => 'm_1',
				'message' => 'Are you open on Saturday?',
				'created_time' => '2026-11-17T10:00:00+0000',
				'from' => ['id' => 'visitor-9', 'name' => 'Fatima de Vries'],
			],
		]));

		$totals = $this->service->run();

		$this->assertSame(expected: ['accounts' => 1, 'messages' => 1, 'tickets' => 1, 'refused' => 0], actual: $totals);
		$this->assertSame(expected: [[
			'channel' => 'facebook',
			'threadKey' => 'meta:t_1',
			'text' => 'Are you open on Saturday?',
			'fromName' => 'Fatima de Vries',
			'mailbox' => 'Gemeente Voorbeeld',
		]], actual: $this->received);
		$this->assertSame(expected: 'GET', actual: $this->calls[0]['method']);
		$this->assertStringStartsWith(prefix: '/v21.0/page-1/conversations?', string: $this->calls[0]['path']);
		$this->assertStringContainsString(needle: 'platform=messenger', haystack: $this->calls[0]['path']);
		$this->assertSame(expected: 'marieke', actual: $this->calls[0]['actingUserId']);
		$this->assertSame(expected: (string) strtotime('2026-11-17T10:00:00+0000'), actual: $this->cursors['socialInbox.cursor.acc-1']);
		$this->assertSame(expected: 'receiving', actual: $this->store->find('socialAccount', 'acc-1')['messagingStatus']);
	}

	/**
	 * A page message is ignored.
	 *
	 * @return void
	 */
	public function testAPageMessageIsIgnored(): void {
		$this->account(id: 'acc-1');
		$this->answer(result: self::conversations(messages: [
			[
				'id' => 'm_2',
				'message' => 'Yes, from nine to five.',
				'created_time' => '2026-11-17T10:01:00+0000',
				'from' => ['id' => 'page-1', 'name' => 'Gemeente Voorbeeld'],
			],
		]));

		$totals = $this->service->run();

		$this->assertSame(expected: 0, actual: $totals['messages']);
		$this->assertSame(expected: [], actual: $this->received);
		$this->assertArrayNotHasKey(key: 'socialInbox.cursor.acc-1', array: $this->cursors);
	}

	/**
	 * A message at or before the cursor is not taken in again.
	 *
	 * @return void
	 */
	public function testAMessageAtOrBeforeTheCursorIsNotTakenInAgain(): void {
		$this->account(id: 'acc-1');
		$this->cursors['socialInbox.cursor.acc-1'] = (string) strtotime('2026-11-17T10:00:00+0000');
		$this->answer(result: self::conversations(messages: [
			[
				'id' => 'm_1',
				'message' => 'Are you open on Saturday?',
				'created_time' => '2026-11-17T10:00:00+0000',
				'from' => ['id' => 'visitor-9', 'name' => 'Fatima'],
			],
			['id' => 'm_3', 'message' => 'And on Sunday?', 'created_time' => '2026-11-17T10:02:00+0000', 'from' => ['id' => 'visitor-9', 'name' => 'Fatima']],
		]));

		$this->service->run();

		$this->assertSame(expected: ['And on Sunday?'], actual: array_column($this->received, 'text'));
		$this->assertSame(expected: (string) strtotime('2026-11-17T10:02:00+0000'), actual: $this->cursors['socialInbox.cursor.acc-1']);
	}

	/**
	 * A refusal lands in messaging status and leaves status alone.
	 *
	 * @return void
	 */
	public function testARefusalLandsInMessagingStatusAndLeavesStatusAlone(): void {
		$this->account(id: 'acc-1');
		$this->answer(
			result: SocialGatewayResult::failed(
				code: SocialGatewayResult::REJECTED_BY_NETWORK,
				reason: 'Meta has not approved pages_messaging for this app.',
				status: 403
			)
		);

		$totals = $this->service->run();

		$stored = $this->store->find('socialAccount', 'acc-1');
		$this->assertSame(expected: 1, actual: $totals['refused']);
		$this->assertSame(expected: 'rejected_by_network', actual: $stored['messagingStatus']);
		$this->assertSame(expected: 'Meta has not approved pages_messaging for this app.', actual: $stored['messagingStatusReason']);
		$this->assertSame(expected: 'active', actual: $stored['status']);
		$this->assertSame(expected: '', actual: $stored['statusReason']);
		$this->assertSame(expected: [], actual: $this->received);
		$this->assertSame(expected: [], actual: $this->cursors);
	}

	/**
	 * An instagram account is read on its own platform.
	 *
	 * @return void
	 */
	public function testAnInstagramAccountIsReadOnItsOwnPlatform(): void {
		$this->account(id: 'acc-2', overrides: ['network' => 'instagram', 'externalAccountId' => 'ig-7']);
		$this->answer(result: self::conversations(messages: [
			['id' => 'm_4', 'message' => 'Hoi!', 'created_time' => '2026-11-17T11:00:00+0000', 'from' => ['id' => 'igsid-1', 'username' => 'fatima.dv']],
		]));

		$this->service->run();

		$this->assertStringContainsString(needle: 'platform=instagram', haystack: $this->calls[0]['path']);
		$this->assertSame(expected: 'instagram', actual: $this->received[0]['channel']);
		$this->assertSame(expected: 'fatima.dv', actual: $this->received[0]['fromName']);
	}

	/**
	 * Other networks and accounts without a credential are not read.
	 *
	 * @return void
	 */
	public function testOtherNetworksAndAccountsWithoutACredentialAreNotRead(): void {
		$this->account(id: 'acc-3', overrides: ['network' => 'mastodon']);
		$this->account(id: 'acc-4', overrides: ['credentialRef' => '']);
		$this->account(id: 'acc-5', overrides: ['active' => false]);
		$this->gateway->expects($this->never())->method('request');

		$this->assertSame(expected: ['accounts' => 0, 'messages' => 0, 'tickets' => 0, 'refused' => 0], actual: $this->service->run());
	}
}
