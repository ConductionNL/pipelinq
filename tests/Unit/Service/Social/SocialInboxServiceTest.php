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

	private SocialInboxService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		);
		$accounts = new SocialAccountService(
			$this->store,
			$this->createMock(SocialAdapterRegistry::class),
			$this->createMock(BrokerCredentialReader::class),
			$this->createMock(ObjectOwnerAccessPolicy::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(NotificationService::class),
		);

		$this->gateway = $this->createMock(SocialBrokerGateway::class);
		$this->intake = $this->createMock(InboundTicketService::class);
		$this->intake->method('tryReceive')->willReturnCallback(function (array $message): string {
			$this->received[] = $message;
			return 'ticket-1';
		});
		$this->config = $this->createMock(IAppConfig::class);
		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default): string => ($this->cursors[$key] ?? $default)
		);
		$this->config->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->cursors[$key] = $value;
			return true;
		});

		$this->service = new SocialInboxService(
			$accounts,
			new FacebookPageAdapter($this->gateway),
			new InstagramBusinessAdapter($this->gateway),
			$this->gateway,
			$this->intake,
			$this->config,
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
			function (string $credentialRef, string $method, string $path, array $headers = [], ?string $body = null, ?string $actingUserId = null) use ($result): SocialGatewayResult {
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

	public function testANewVisitorMessageReachesTheIntakeOnItsConversation(): void {
		$this->account('acc-1');
		$this->answer(self::conversations([
			['id' => 'm_1', 'message' => 'Are you open on Saturday?', 'created_time' => '2026-11-17T10:00:00+0000', 'from' => ['id' => 'visitor-9', 'name' => 'Fatima de Vries']],
		]));

		$totals = $this->service->run();

		$this->assertSame(['accounts' => 1, 'messages' => 1, 'tickets' => 1, 'refused' => 0], $totals);
		$this->assertSame([[
			'channel' => 'facebook',
			'threadKey' => 'meta:t_1',
			'text' => 'Are you open on Saturday?',
			'fromName' => 'Fatima de Vries',
			'mailbox' => 'Gemeente Voorbeeld',
		]], $this->received);
		$this->assertSame('GET', $this->calls[0]['method']);
		$this->assertStringStartsWith('/v21.0/page-1/conversations?', $this->calls[0]['path']);
		$this->assertStringContainsString('platform=messenger', $this->calls[0]['path']);
		$this->assertSame('marieke', $this->calls[0]['actingUserId']);
		$this->assertSame((string) strtotime('2026-11-17T10:00:00+0000'), $this->cursors['socialInbox.cursor.acc-1']);
		$this->assertSame('receiving', $this->store->find('socialAccount', 'acc-1')['messagingStatus']);
	}

	public function testAPageMessageIsIgnored(): void {
		$this->account('acc-1');
		$this->answer(self::conversations([
			['id' => 'm_2', 'message' => 'Yes, from nine to five.', 'created_time' => '2026-11-17T10:01:00+0000', 'from' => ['id' => 'page-1', 'name' => 'Gemeente Voorbeeld']],
		]));

		$totals = $this->service->run();

		$this->assertSame(0, $totals['messages']);
		$this->assertSame([], $this->received);
		$this->assertArrayNotHasKey('socialInbox.cursor.acc-1', $this->cursors);
	}

	public function testAMessageAtOrBeforeTheCursorIsNotTakenInAgain(): void {
		$this->account('acc-1');
		$this->cursors['socialInbox.cursor.acc-1'] = (string) strtotime('2026-11-17T10:00:00+0000');
		$this->answer(self::conversations([
			['id' => 'm_1', 'message' => 'Are you open on Saturday?', 'created_time' => '2026-11-17T10:00:00+0000', 'from' => ['id' => 'visitor-9', 'name' => 'Fatima']],
			['id' => 'm_3', 'message' => 'And on Sunday?', 'created_time' => '2026-11-17T10:02:00+0000', 'from' => ['id' => 'visitor-9', 'name' => 'Fatima']],
		]));

		$this->service->run();

		$this->assertSame(['And on Sunday?'], array_column($this->received, 'text'));
		$this->assertSame((string) strtotime('2026-11-17T10:02:00+0000'), $this->cursors['socialInbox.cursor.acc-1']);
	}

	public function testARefusalLandsInMessagingStatusAndLeavesStatusAlone(): void {
		$this->account('acc-1');
		$this->answer(SocialGatewayResult::failed(SocialGatewayResult::REJECTED_BY_NETWORK, 'Meta has not approved pages_messaging for this app.', 403));

		$totals = $this->service->run();

		$stored = $this->store->find('socialAccount', 'acc-1');
		$this->assertSame(1, $totals['refused']);
		$this->assertSame('rejected_by_network', $stored['messagingStatus']);
		$this->assertSame('Meta has not approved pages_messaging for this app.', $stored['messagingStatusReason']);
		$this->assertSame('active', $stored['status']);
		$this->assertSame('', $stored['statusReason']);
		$this->assertSame([], $this->received);
		$this->assertSame([], $this->cursors);
	}

	public function testAnInstagramAccountIsReadOnItsOwnPlatform(): void {
		$this->account('acc-2', ['network' => 'instagram', 'externalAccountId' => 'ig-7']);
		$this->answer(self::conversations([
			['id' => 'm_4', 'message' => 'Hoi!', 'created_time' => '2026-11-17T11:00:00+0000', 'from' => ['id' => 'igsid-1', 'username' => 'fatima.dv']],
		]));

		$this->service->run();

		$this->assertStringContainsString('platform=instagram', $this->calls[0]['path']);
		$this->assertSame('instagram', $this->received[0]['channel']);
		$this->assertSame('fatima.dv', $this->received[0]['fromName']);
	}

	public function testOtherNetworksAndAccountsWithoutACredentialAreNotRead(): void {
		$this->account('acc-3', ['network' => 'mastodon']);
		$this->account('acc-4', ['credentialRef' => '']);
		$this->account('acc-5', ['active' => false]);
		$this->gateway->expects($this->never())->method('request');

		$this->assertSame(['accounts' => 0, 'messages' => 0, 'tickets' => 0, 'refused' => 0], $this->service->run());
	}
}
