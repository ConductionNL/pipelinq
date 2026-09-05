<?php

/**
 * Unit tests for SocialAdvocacyService.
 *
 * The path for accounts no application may post to. Three things are asserted
 * and one of them is a negative: NOTHING outbound happens here. No credential
 * is resolved, no broker is called and no consent record is touched, because
 * asking a colleague to post something is a Nextcloud notification to a
 * colleague and not an outbound message to a contact.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\Social\BrokerCredentialReader;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use OCA\Pipelinq\Service\SocialAccountService;
use OCA\Pipelinq\Service\SocialAdvocacyService;
use OCA\Pipelinq\Tests\Unit\Service\Social\InMemoryObjectStore;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The prepared-share path, its notification and its ownership boundary.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */
class SocialAdvocacyServiceTest extends TestCase {
	/**
	 * The in-memory store.
	 *
	 * @var InMemoryObjectStore
	 */
	private InMemoryObjectStore $store;

	/**
	 * The publication rows.
	 *
	 * @var SocialPublicationStore
	 */
	private SocialPublicationStore $publications;

	/**
	 * The group manager double.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groups;

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
		$this->publications = new SocialPublicationStore($this->store);
		$this->groups = $this->createMock(IGroupManager::class);

		$this->store->seed(
			schemaSlug: SocialAccountService::SCHEMA,
			id: 'acc-i',
			payload: [
				'network' => 'instagram',
				'kind' => 'person',
				'handle' => '@rubenvdlinde',
				'ownerUserId' => 'ruben',
				'publishMode' => 'share',
				'active' => true,
			],
		);
	}

	/**
	 * The service under test.
	 *
	 * @param NotificationService|null $notifications The notifications double.
	 *
	 * @return SocialAdvocacyService The service.
	 */
	private function service(?NotificationService $notifications = null): SocialAdvocacyService {
		$policy = $this->createMock(ObjectOwnerAccessPolicy::class);
		$policy->method('isPrivileged')->willReturn(false);

		$registry = $this->createMock(SocialAdapterRegistry::class);
		$registry->method('readiness')->willReturn([]);
		$registry->method('forNetwork')->willReturn(null);

		$accounts = new SocialAccountService(
			$this->store,
			$registry,
			$this->createMock(BrokerCredentialReader::class),
			$policy,
			$this->groups,
			$this->createMock(NotificationService::class),
		);

		return new SocialAdvocacyService(
			$this->publications,
			$accounts,
			$registry,
			($notifications ?? $this->createMock(NotificationService::class)),
		);
	}

	/**
	 * Open a pending publication for the seeded account.
	 *
	 * @return array<string, mixed> The publication row.
	 */
	private function pending(): array {
		return $this->publications->open(postId: 'post-1', accountId: 'acc-i', network: 'instagram');
	}

	/**
	 * A share-mode account notifies its owner with the prepared text, and
	 * nothing at all is sent to the network.
	 *
	 * @return void
	 */
	public function testAShareModeAccountNotifiesItsOwnerAndCallsNothing(): void {
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('sendNotification')
			->with(
				'ruben',
				SocialAdvocacyService::SUBJECT_SHARE_REQUESTED,
				$this->callback(
					static fn (array $parameters): bool => str_contains(
						(string)($parameters['body'] ?? ''),
						'OpenRegister 3.0 is uit.',
					)
				),
				'socialPublication',
				$this->anything(),
			);

		$saved = $this->service(notifications: $notifications)->requestShare(
			publication: $this->pending(),
			account: $this->store->find(SocialAccountService::SCHEMA, 'acc-i'),
			post: ['title' => 'Aankondiging'],
			request: new SocialPublishRequest(
				network: 'instagram',
				body: 'OpenRegister 3.0 is uit.',
				link: 'https://conduction.nl/nieuws/or3',
				handle: '@rubenvdlinde',
			),
		);

		$this->assertSame(SocialPublicationStore::AWAITING_SHARE, $saved['status']);
		$this->assertNotSame('', $saved['sharePromptedAt']);
		$this->assertStringContainsString('OpenRegister 3.0 is uit.', $saved['preparedBody']);
	}

	/**
	 * The prepared text is FROZEN when the person is asked, not rebuilt when
	 * they open it, so editing the post afterwards cannot change what somebody
	 * was asked to post.
	 *
	 * @return void
	 */
	public function testThePreparedTextIsFrozenAtTheMomentOfAsking(): void {
		$service = $this->service();
		$service->requestShare(
			publication: $this->pending(),
			account: $this->store->find(SocialAccountService::SCHEMA, 'acc-i'),
			post: ['title' => 'Aankondiging'],
			request: new SocialPublishRequest(network: 'instagram', body: 'De eerste tekst.'),
		);

		$publicationId = $this->publications->idOf(publication: $this->publications->forPost(postId: 'post-1')[0]);
		$bundle = $service->shareBundle(publicationId: $publicationId, uid: 'ruben');

		$this->assertSame('De eerste tekst.', $bundle['share']['body']);
	}

	/**
	 * Confirming records the share against the owner, with the moment they
	 * confirmed.
	 *
	 * @return void
	 */
	public function testConfirmingAShareRecordsItAgainstTheOwner(): void {
		$service = $this->service();
		$service->requestShare(
			publication: $this->pending(),
			account: $this->store->find(SocialAccountService::SCHEMA, 'acc-i'),
			post: [],
			request: new SocialPublishRequest(network: 'instagram', body: 'Tekst'),
		);

		$publicationId = $this->publications->idOf(publication: $this->publications->forPost(postId: 'post-1')[0]);
		$result = $service->confirmShare(
			publicationId: $publicationId,
			uid: 'ruben',
			url: 'https://www.instagram.com/p/abc/',
		);

		$this->assertArrayNotHasKey('error', $result);
		$this->assertSame(SocialPublicationStore::SHARED, $result['publication']['status']);
		$this->assertSame('https://www.instagram.com/p/abc/', $result['publication']['url']);
		$this->assertNotSame('', $result['publication']['publishedAt']);
	}

	/**
	 * Only the owner may confirm. A colleague marking somebody else's share as
	 * done would put a number in a report nobody can trace.
	 *
	 * @return void
	 */
	public function testOnlyTheOwnerMayConfirmAShare(): void {
		$this->groups->method('isAdmin')->willReturn(false);
		$service = $this->service();
		$service->requestShare(
			publication: $this->pending(),
			account: $this->store->find(SocialAccountService::SCHEMA, 'acc-i'),
			post: [],
			request: new SocialPublishRequest(network: 'instagram', body: 'Tekst'),
		);

		$publicationId = $this->publications->idOf(publication: $this->publications->forPost(postId: 'post-1')[0]);
		$result = $service->confirmShare(publicationId: $publicationId, uid: 'marieke');

		$this->assertArrayHasKey('error', $result);
		$this->assertSame(
			SocialPublicationStore::AWAITING_SHARE,
			$this->publications->find(publicationId: $publicationId)['status'],
		);
	}

	/**
	 * A publication that is not waiting for a share cannot be confirmed.
	 *
	 * @return void
	 */
	public function testAPublicationThatIsNotWaitingCannotBeConfirmed(): void {
		$row = $this->pending();
		$publicationId = $this->publications->idOf(publication: $row);

		$result = $this->service()->confirmShare(publicationId: $publicationId, uid: 'ruben');

		$this->assertArrayHasKey('error', $result);
	}
}//end class
