<?php

/**
 * Unit tests for SocialPostService.
 *
 * The store is a real in-memory subclass rather than a mock returning canned
 * rows, because almost every rule here reads back what the step before it
 * wrote: the settle rule reads the publications the publish recorded, a retry
 * reads the failure the first attempt stored, and a second approval reads the
 * first. A mock would agree with the caller whatever was stored.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\BudgetService;
use OCA\Pipelinq\Service\CampaignLinkDecorator;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\Social\SocialPublishOutcome;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use OCA\Pipelinq\Service\SocialAccountService;
use OCA\Pipelinq\Service\SocialAdvocacyService;
use OCA\Pipelinq\Service\SocialPostService;
use OCA\Pipelinq\Tests\Unit\Service\Social\InMemoryObjectStore;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The composer, the approval gate, the publish loop and the spend stop.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) One test per rule.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service under test joins
 *  six collaborators, so its test constructs six doubles.
 */
class SocialPostServiceTest extends TestCase {
	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * The accounts service, backed by the same store.
	 *
	 * @var SocialAccountService
	 */
	private SocialAccountService $accounts;

	/**
	 * The publication rows.
	 *
	 * @var SocialPublicationStore
	 */
	private SocialPublicationStore $publications;

	/**
	 * The adapter registry double.
	 *
	 * @var SocialAdapterRegistry
	 */
	private SocialAdapterRegistry $registry;

	/**
	 * The advocacy service double.
	 *
	 * @var SocialAdvocacyService
	 */
	private SocialAdvocacyService $advocacy;

	/**
	 * The spend budget double.
	 *
	 * @var BudgetService
	 */
	private BudgetService $budget;

	/**
	 * The requests each fake adapter was handed, keyed by network.
	 *
	 * @var array<string, SocialPublishRequest>
	 */
	private array $requests = [];

	/**
	 * The outcome each fake adapter answers with, keyed by network.
	 *
	 * @var array<string, SocialPublishOutcome>
	 */
	private array $outcomes = [];

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryObjectStore(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->accounts = $this->accountsService();
		$this->publications = new SocialPublicationStore($this->store);
		$this->registry = $this->createMock(SocialAdapterRegistry::class);
		$this->registry->method('forNetwork')->willReturnCallback(
			fn (string $network): ?SocialNetworkAdapter => $this->adapterFor(network: $network)
		);

		$this->advocacy = $this->createMock(SocialAdvocacyService::class);
		$this->budget = $this->createMock(BudgetService::class);
		$this->budget->method('canSend')->willReturn(true);
	}

	/**
	 * The service under test, built over the current doubles.
	 *
	 * @param bool $utmEnabled Whether campaign link decoration is switched on.
	 *
	 * @return SocialPostService The service.
	 */
	private function service(bool $utmEnabled = true): SocialPostService {
		$links = $this->createMock(CampaignLinkDecorator::class);
		$links->method('isEnabled')->willReturn($utmEnabled);
		$links->method('decorateUrl')->willReturnCallback(
			static function (string $url, array $utm): ?string {
				$parts = [];
				foreach ($utm as $key => $value) {
					if ((string)$value !== '') {
						$parts[] = $key . '=' . rawurlencode((string)$value);
					}
				}

				if ($parts === []) {
					return null;
				}

				return $url . '?' . implode('&', $parts);
			}
		);

		return new SocialPostService(
			$this->store,
			$this->accounts,
			$this->registry,
			$this->publications,
			$this->advocacy,
			$links,
			$this->budget,
			$this->createMock(IAppConfig::class),
		);
	}

	/**
	 * An accounts service over the same in-memory store.
	 *
	 * @return SocialAccountService The service.
	 */
	private function accountsService(): SocialAccountService {
		$registry = $this->createMock(SocialAdapterRegistry::class);
		$registry->method('readiness')->willReturn([]);

		// `ruben` and `marieke` are the marketers in this suite. Leaving the
		// policy unstubbed would answer false for everyone, and a retry test
		// would then pass because the GUARD refused rather than because the
		// failure code did.
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['ruben', 'marieke'], true)
		);

		return new SocialAccountService(
			$this->store,
			$registry,
			$this->createMock(BrokerCredentialReader::class),
			$policy,
			$this->createMock(IGroupManager::class),
			$this->createMock(NotificationService::class),
		);
	}

	/**
	 * A fake adapter for one network that records what it was handed and
	 * answers with whatever the test scripted.
	 *
	 * @param string $network The network.
	 *
	 * @return SocialNetworkAdapter|null The adapter, or null for an unknown network.
	 */
	private function adapterFor(string $network): ?SocialNetworkAdapter {
		if (in_array($network, ['mastodon', 'linkedin', 'x'], true) === false) {
			return null;
		}

		$limits = ['mastodon' => 500, 'linkedin' => 3000, 'x' => 280];
		$costs = ['mastodon' => 0.0, 'linkedin' => 0.0, 'x' => 0.05];
		$requests = &$this->requests;
		$outcomes = &$this->outcomes;

		$adapter = $this->createMock(SocialNetworkAdapter::class);
		$adapter->method('network')->willReturn($network);
		$adapter->method('bodyLimit')->willReturn($limits[$network]);
		$adapter->method('costPerPost')->willReturn($costs[$network]);
		$adapter->method('costPerRead')->willReturn(0.0);
		$adapter->method('publish')->willReturnCallback(
			static function (SocialPublishRequest $request) use ($network, &$requests, &$outcomes): SocialPublishOutcome {
				$requests[$network] = $request;

				return ($outcomes[$network] ?? SocialPublishOutcome::published(
					externalId: $network . '-1',
					url: 'https://example.test/' . $network . '/1',
					cost: 0.0,
				));
			}
		);

		return $adapter;
	}

	/**
	 * Seed one account.
	 *
	 * @param string $id The id.
	 * @param string $network The network.
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return string The id.
	 */
	private function seedAccount(string $id, string $network, array $overrides = []): string {
		$this->store->seed(
			schemaSlug: SocialAccountService::SCHEMA,
			id: $id,
			payload: array_merge(
				[
					'network' => $network,
					'kind' => 'organisation',
					'handle' => '@conduction',
					'ownerUserId' => 'ruben',
					'credentialRef' => 'cred-' . $id,
					'status' => 'active',
					'active' => true,
					'publishMode' => 'api',
				],
				$overrides,
			),
		);

		return $id;
	}

	/**
	 * Seed one post.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return string The post id.
	 */
	private function seedPost(array $overrides = []): string {
		$this->store->seed(
			schemaSlug: SocialPostService::SCHEMA,
			id: 'post-1',
			payload: array_merge(
				[
					'title' => 'Aankondiging',
					'body' => 'OpenRegister 3.0 is uit.',
					'link' => 'https://conduction.nl/nieuws/or3',
					'accountIds' => [],
					'variants' => [],
					'status' => SocialPostService::STATUS_DRAFT,
					'approvals' => [],
				],
				$overrides,
			),
		);

		return 'post-1';
	}

	/**
	 * A variant merges onto the post rather than replacing it: a variant with
	 * only a body still gets the post's link and media.
	 *
	 * @return void
	 */
	public function testAVariantMergesOntoThePostRatherThanReplacingIt(): void {
		$post = [
			'body' => 'Lang verhaal',
			'link' => 'https://conduction.nl/nieuws/or3',
			'media' => [['url' => 'https://conduction.nl/i.jpg']],
			'variants' => ['x' => ['body' => 'Kort']],
		];

		$forX = $this->service()->resolveVariant(post: $post, network: 'x');
		$forMastodon = $this->service()->resolveVariant(post: $post, network: 'mastodon');

		$this->assertSame('Kort', $forX['body']);
		$this->assertSame('https://conduction.nl/nieuws/or3', $forX['link']);
		$this->assertSame([['url' => 'https://conduction.nl/i.jpg']], $forX['media']);
		$this->assertSame('Lang verhaal', $forMastodon['body']);
	}

	/**
	 * A variant longer than its network allows is refused at approval, not
	 * three hours later when the job runs.
	 *
	 * @return void
	 */
	public function testAVariantOverTheNetworkLimitIsRefusedAtApproval(): void {
		$this->seedAccount(id: 'acc-x', network: 'x');
		$postId = $this->seedPost([
			'accountIds' => ['acc-x'],
			'variants' => ['x' => ['body' => str_repeat('a', 400)]],
		]);

		$result = $this->service()->submitForApproval(postId: $postId);

		$this->assertArrayHasKey('error', $result);
		$this->assertStringContainsString('280', $result['error']);
		$this->assertSame(
			SocialPostService::STATUS_DRAFT,
			$this->store->find(SocialPostService::SCHEMA, $postId)['status'],
		);
	}

	/**
	 * A post that names no accounts cannot be submitted.
	 *
	 * @return void
	 */
	public function testAPostWithNoAccountsCannotBeSubmitted(): void {
		$postId = $this->seedPost();

		$this->assertArrayHasKey('error', $this->service()->submitForApproval(postId: $postId));
	}

	/**
	 * The approval names the SESSION user, whatever the body claimed.
	 *
	 * @return void
	 */
	public function testAnApprovalIsStampedFromTheSessionNotTheBody(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_APPROVAL,
		]);

		$result = $this->service()->approve(postId: $postId, uid: 'marieke', note: 'Prima');

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame(SocialPostService::STATUS_SCHEDULED, $result['post']['status']);
		$this->assertSame('marieke', $result['post']['approvals'][0]['userId']);
		$this->assertSame('approved', $result['post']['approvals'][0]['decision']);
	}

	/**
	 * A rejection returns the post to draft with the reason recorded, and
	 * nothing goes out.
	 *
	 * @return void
	 */
	public function testARejectionReturnsThePostToDraft(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_APPROVAL,
		]);

		$result = $this->service()->reject(postId: $postId, uid: 'marieke', note: 'Te lang');

		$this->assertSame(SocialPostService::STATUS_DRAFT, $result['post']['status']);
		$this->assertSame('rejected', $result['post']['approvals'][0]['decision']);
		$this->assertSame([], $this->publications->forPost(postId: $postId));
	}

	/**
	 * An unapproved post is never published, whatever its schedule says.
	 *
	 * @return void
	 */
	public function testAnUnapprovedPostIsNeverPublished(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_DRAFT,
			'scheduledFor' => '2020-01-01T00:00:00Z',
		]);

		$this->service()->publishDuePosts(now: '2026-09-05T12:00:00Z');

		$this->assertSame([], $this->publications->forPost(postId: $postId));
		$this->assertSame(
			SocialPostService::STATUS_DRAFT,
			$this->store->find(SocialPostService::SCHEMA, $postId)['status'],
		);
	}

	/**
	 * A scheduled post publishes to every account it names.
	 *
	 * @return void
	 */
	public function testAScheduledPostPublishesToEveryNamedAccount(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$this->seedAccount(id: 'acc-l', network: 'linkedin');
		$postId = $this->seedPost([
			'accountIds' => ['acc-m', 'acc-l'],
			'status' => SocialPostService::STATUS_SCHEDULED,
			'scheduledFor' => '2026-09-05T09:00:00Z',
		]);

		$attempted = $this->service()->publishDuePosts(now: '2026-09-05T12:00:00Z');

		$this->assertSame(1, $attempted);
		$rows = $this->publications->forPost(postId: $postId);
		$this->assertCount(2, $rows);
		foreach ($rows as $row) {
			$this->assertSame(SocialPublicationStore::PUBLISHED, $row['status']);
			$this->assertNotSame('', $row['externalId']);
		}

		$this->assertSame(
			SocialPostService::STATUS_PUBLISHED,
			$this->store->find(SocialPostService::SCHEMA, $postId)['status'],
		);
	}

	/**
	 * A post scheduled for later is left alone.
	 *
	 * @return void
	 */
	public function testAPostScheduledForLaterIsLeftAlone(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_SCHEDULED,
			'scheduledFor' => '2027-01-01T00:00:00Z',
		]);

		$this->assertSame(0, $this->service()->publishDuePosts(now: '2026-09-05T12:00:00Z'));
		$this->assertSame([], $this->publications->forPost(postId: $postId));
	}

	/**
	 * One failing account does not stop the others, and the post names the one
	 * that failed.
	 *
	 * @return void
	 */
	public function testOneFailingAccountDoesNotStopTheOthers(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$this->seedAccount(id: 'acc-l', network: 'linkedin');
		$this->outcomes['linkedin'] = SocialPublishOutcome::refused(
			code: SocialGatewayResult::REJECTED_BY_NETWORK,
			reason: 'LinkedIn refused the post.',
		);

		$postId = $this->seedPost([
			'accountIds' => ['acc-m', 'acc-l'],
			'status' => SocialPostService::STATUS_SCHEDULED,
		]);

		$this->service()->publishPost(postId: $postId);

		$byNetwork = [];
		foreach ($this->publications->forPost(postId: $postId) as $row) {
			$byNetwork[$row['network']] = $row;
		}

		$this->assertSame(SocialPublicationStore::PUBLISHED, $byNetwork['mastodon']['status']);
		$this->assertSame(SocialPublicationStore::FAILED, $byNetwork['linkedin']['status']);
		$this->assertSame(SocialGatewayResult::REJECTED_BY_NETWORK, $byNetwork['linkedin']['failureCode']);

		$post = $this->store->find(SocialPostService::SCHEMA, $postId);
		$this->assertSame(SocialPostService::STATUS_FAILED, $post['status']);
		$this->assertStringContainsString('linkedin', $post['failureReason']);
	}

	/**
	 * A dead grant marks the account and is NOT retried: a retry cannot mend
	 * it and only a person re-authorising can.
	 *
	 * @return void
	 */
	public function testARelinkNeededPublicationDoesNotRetry(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$this->outcomes['mastodon'] = SocialPublishOutcome::refused(
			code: SocialGatewayResult::RELINK_NEEDED,
			reason: 'The connection to this account has ended.',
		);

		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_SCHEDULED,
		]);

		$this->service()->publishPost(postId: $postId);

		$rows = $this->publications->forPost(postId: $postId);
		$publicationId = $this->publications->idOf(publication: $rows[0]);
		$this->assertSame(SocialGatewayResult::RELINK_NEEDED, $rows[0]['failureCode']);
		$this->assertSame(
			SocialAccountService::STATUS_RELINK_NEEDED,
			$this->store->find(SocialAccountService::SCHEMA, 'acc-m')['status'],
		);

		$retry = $this->service()->retryPublication(publicationId: $publicationId, uid: 'ruben');
		$this->assertArrayHasKey('error', $retry);
		$this->assertSame(1, $this->publications->find(publicationId: $publicationId)['attempts']);
	}

	/**
	 * A failure a retry CAN fix is retried, and the retry reuses the row
	 * rather than adding a second one.
	 *
	 * @return void
	 */
	public function testARetryableFailureIsTriedAgainOnTheSameRow(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$this->outcomes['mastodon'] = SocialPublishOutcome::refused(
			code: SocialGatewayResult::UNAVAILABLE,
			reason: 'The network could not be reached.',
		);

		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_SCHEDULED,
		]);
		$this->service()->publishPost(postId: $postId);

		$publicationId = $this->publications->idOf(publication: $this->publications->forPost(postId: $postId)[0]);
		unset($this->outcomes['mastodon']);

		$retry = $this->service()->retryPublication(publicationId: $publicationId, uid: 'ruben');

		$this->assertArrayNotHasKey('error', $retry);
		$this->assertCount(1, $this->publications->forPost(postId: $postId));
		$this->assertSame(SocialPublicationStore::PUBLISHED, $retry['publication']['status']);
		$this->assertSame(2, $retry['publication']['attempts']);
	}

	/**
	 * A campaign post publishes a decorated link, and the STORED link stays
	 * clean so the post can move to another campaign later.
	 *
	 * @return void
	 */
	public function testACampaignPostPublishesADecoratedLinkAndStoresACleanOne(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_SCHEDULED,
			'campaignId' => 'Najaarscampagne 2026',
		]);

		$this->service()->publishPost(postId: $postId);

		$this->assertStringContainsString(
			'utm_campaign=najaarscampagne-2026',
			$this->requests['mastodon']->link,
		);
		$this->assertStringContainsString('utm_medium=mastodon', $this->requests['mastodon']->link);
		$this->assertSame(
			'https://conduction.nl/nieuws/or3',
			$this->store->find(SocialPostService::SCHEMA, $postId)['link'],
		);
	}

	/**
	 * A post with no campaign publishes its link byte for byte.
	 *
	 * @return void
	 */
	public function testAPostWithoutACampaignPublishesTheLinkUnchanged(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_SCHEDULED,
		]);

		$this->service()->publishPost(postId: $postId);

		$this->assertSame('https://conduction.nl/nieuws/or3', $this->requests['mastodon']->link);
	}

	/**
	 * The broker call acts as the ACCOUNT'S OWNER, never as the person who
	 * created or approved the post (ADR-099).
	 *
	 * @return void
	 */
	public function testThePublishCallActsAsTheAccountOwnerNotTheApprover(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon', overrides: ['ownerUserId' => 'ruben']);
		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_APPROVAL,
			'createdBy' => 'sander',
		]);

		$this->service()->approve(postId: $postId, uid: 'marieke');
		$this->service()->publishPost(postId: $postId);

		$this->assertSame('ruben', $this->requests['mastodon']->actingUserId);
	}

	/**
	 * An exhausted hard-stop budget refuses BEFORE the call is made.
	 *
	 * @return void
	 */
	public function testAnExhaustedSpendBudgetStopsThePostBeforeTheCall(): void {
		$this->seedAccount(id: 'acc-x', network: 'x');
		$this->budget = $this->createMock(BudgetService::class);
		$this->budget->method('canSend')->willReturn(false);
		$this->budget->expects($this->never())->method('recordSend');

		$postId = $this->seedPost([
			'accountIds' => ['acc-x'],
			'status' => SocialPostService::STATUS_SCHEDULED,
			'body' => 'Kort',
		]);

		$this->service()->publishPost(postId: $postId);

		$row = $this->publications->forPost(postId: $postId)[0];
		$this->assertSame(SocialGatewayResult::BUDGET_EXHAUSTED, $row['failureCode']);
		$this->assertArrayNotHasKey('x', $this->requests, 'nothing may be sent to X once the budget is reached');
	}

	/**
	 * A published X post records what it cost, against the same budget.
	 *
	 * @return void
	 */
	public function testAPublishedXPostRecordsItsCostAgainstTheBudget(): void {
		$this->seedAccount(id: 'acc-x', network: 'x');
		$this->outcomes['x'] = SocialPublishOutcome::published(
			externalId: 'x-1',
			url: 'https://x.com/conduction/status/1',
			cost: 0.05,
		);

		$this->budget = $this->createMock(BudgetService::class);
		$this->budget->method('canSend')->willReturn(true);
		$this->budget->expects($this->once())->method('recordSend')
			->with($this->anything(), 'x', 0.05);

		$postId = $this->seedPost([
			'accountIds' => ['acc-x'],
			'status' => SocialPostService::STATUS_SCHEDULED,
			'body' => 'Kort',
		]);

		$this->service()->publishPost(postId: $postId);

		$this->assertSame(0.05, $this->publications->forPost(postId: $postId)[0]['cost']);
	}

	/**
	 * A free network never asks the budget anything.
	 *
	 * @return void
	 */
	public function testAFreeNetworkNeverConsultsTheBudget(): void {
		$this->seedAccount(id: 'acc-m', network: 'mastodon');
		$this->budget = $this->createMock(BudgetService::class);
		$this->budget->expects($this->never())->method('canSend');

		$postId = $this->seedPost([
			'accountIds' => ['acc-m'],
			'status' => SocialPostService::STATUS_SCHEDULED,
		]);

		$this->service()->publishPost(postId: $postId);

		$this->assertSame(
			SocialPublicationStore::PUBLISHED,
			$this->publications->forPost(postId: $postId)[0]['status'],
		);
	}

	/**
	 * A network Pipelinq has no adapter for fails TYPED, with a row to show
	 * for it, rather than going nowhere.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredNetworkFailsTypedWithoutCallingTheBroker(): void {
		$this->seedAccount(id: 'acc-t', network: 'threads');
		$postId = $this->seedPost([
			'accountIds' => ['acc-t'],
			'status' => SocialPostService::STATUS_SCHEDULED,
		]);

		$this->service()->publishPost(postId: $postId);

		$row = $this->publications->forPost(postId: $postId)[0];
		$this->assertSame(SocialPublicationStore::FAILED, $row['status']);
		$this->assertSame(SocialGatewayResult::NOT_CONFIGURED, $row['failureCode']);
		$this->assertSame([], $this->requests);
	}

	/**
	 * A share-mode account never reaches an adapter at all: it goes down the
	 * advocacy path instead.
	 *
	 * @return void
	 */
	public function testAShareModeAccountGoesToTheAdvocacyPathAndNotToAnAdapter(): void {
		$this->seedAccount(id: 'acc-i', network: 'instagram', overrides: ['publishMode' => 'share']);
		$this->advocacy = $this->createMock(SocialAdvocacyService::class);
		$this->advocacy->expects($this->once())->method('requestShare')->willReturn([
			'id' => 'pub-1',
			'status' => SocialPublicationStore::AWAITING_SHARE,
		]);

		$postId = $this->seedPost([
			'accountIds' => ['acc-i'],
			'status' => SocialPostService::STATUS_SCHEDULED,
		]);

		$this->service()->publishPost(postId: $postId);

		$this->assertSame([], $this->requests);
	}

	/**
	 * A client cannot claim a post was written by a person, nor stamp its own
	 * status or approvals.
	 *
	 * @return void
	 */
	public function testAClientCannotStampTheAgentMarkOrTheStatus(): void {
		$result = $this->service()->createPost(
			payload: [
				'body' => 'Hallo',
				'agentAuthored' => true,
				'agentAuthoredBy' => 'not-an-agent',
				'status' => SocialPostService::STATUS_PUBLISHED,
				'approvals' => [['userId' => 'somebody', 'decision' => 'approved']],
			],
			uid: 'ruben',
		);

		$this->assertFalse($result['post']['agentAuthored']);
		$this->assertSame('', $result['post']['agentAuthoredBy']);
		$this->assertSame(SocialPostService::STATUS_DRAFT, $result['post']['status']);
		$this->assertSame([], $result['post']['approvals']);
		$this->assertSame('ruben', $result['post']['createdBy']);
	}

	/**
	 * An agent's draft carries the ADR-088 mark, applied by the write path.
	 *
	 * @return void
	 */
	public function testAnAgentDraftCarriesTheMark(): void {
		$result = $this->service()->createPost(
			payload: ['body' => 'Hallo'],
			uid: 'ruben',
			agent: 'marketing-agent',
		);

		$this->assertTrue($result['post']['agentAuthored']);
		$this->assertSame('marketing-agent', $result['post']['agentAuthoredBy']);
	}
}//end class
