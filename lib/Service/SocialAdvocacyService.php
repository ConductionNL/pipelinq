<?php

/**
 * Pipelinq SocialAdvocacyService.
 *
 * The path for accounts no application may post to.
 *
 * This is not a workaround for a missing permission. Meta's platform policy is
 * that a personal Facebook profile and a personal Instagram account cannot be
 * written to by any application, at any tier of review, ever. No filing
 * changes it. The honest design is to stop trying: Pipelinq prepares the text
 * and the media, notifies the person whose account it is, gives them a copy
 * action and a deep link into the network's own composer, and records the
 * share when they say they posted it.
 *
 * NOTHING LEAVES THE INSTANCE ON THIS PATH. No credential is resolved, no
 * broker call is made, and no consent record is involved: asking a colleague
 * to post something is a Nextcloud notification to a colleague, not an
 * outbound message to a contact, so the marketing consent ledger has no part
 * in it.
 *
 * Only the owner confirms. A colleague marking somebody else's share as done
 * would put a number in a report that traces back to a post that does not
 * exist.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;

/**
 * The prepared-share path for accounts that cannot be posted to.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
 */
class SocialAdvocacyService {
	/**
	 * The notification subject sent to an account's owner.
	 *
	 * @var string
	 */
	public const SUBJECT_SHARE_REQUESTED = 'social_share_requested';

	/**
	 * Constructor.
	 *
	 * @param SocialPublicationStore $publications The per-account result rows.
	 * @param SocialAccountService $accounts The account lifecycle and its ownership guard.
	 * @param SocialAdapterRegistry $registry The network adapters, for the composer deep link.
	 * @param NotificationService $notifications Nextcloud notifications.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SocialPublicationStore $publications,
		private readonly SocialAccountService $accounts,
		private readonly SocialAdapterRegistry $registry,
		private readonly NotificationService $notifications,
	) {
	}//end __construct()

	/**
	 * Ask an account's owner to post it themselves.
	 *
	 * @param array<string, mixed> $publication The publication row.
	 * @param array<string, mixed> $account The account it belongs to.
	 * @param array<string, mixed> $post The post being shared.
	 * @param SocialPublishRequest $request The resolved text and link.
	 *
	 * @return array<string, mixed>|null The updated publication, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function requestShare(
		array $publication,
		array $account,
		array $post,
		SocialPublishRequest $request,
	): ?array {
		$publication['status'] = SocialPublicationStore::AWAITING_SHARE;
		$publication['sharePromptedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		// The words are FROZEN here rather than rebuilt when the person opens
		// the notification. Editing the post afterwards must not change what
		// somebody was asked to post.
		$publication['preparedBody'] = $request->bodyWithLink();
		$publication['preparedMedia'] = $request->media;
		$publication['failureCode'] = '';
		$publication['failureReason'] = '';
		$saved = $this->publications->save(publication: $publication);

		$owner = trim((string)($account['ownerUserId'] ?? ''));
		if ($owner === '') {
			return $saved;
		}

		$this->notifications->sendNotification(
			userId: $owner,
			subject: self::SUBJECT_SHARE_REQUESTED,
			parameters: [
				'handle' => (string)($account['handle'] ?? ''),
				'network' => (string)($account['network'] ?? ''),
				'title' => (string)($post['title'] ?? ''),
				'body' => $request->bodyWithLink(),
			],
			objectType: 'socialPublication',
			objectId: $this->publications->idOf(publication: $saved ?? $publication),
		);

		return $saved;
	}//end requestShare()

	/**
	 * Everything the owner needs to post it: the prepared text, the media and
	 * a deep link into the network's own composer.
	 *
	 * @param string $publicationId The publication.
	 * @param string $uid The caller.
	 *
	 * @return array{error?: string, share?: array<string, mixed>} The bundle, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function shareBundle(string $publicationId, string $uid): array {
		$context = $this->authorised(publicationId: $publicationId, uid: $uid);
		if (isset($context['error']) === true) {
			return ['error' => $context['error']];
		}

		$publication = $context['publication'];
		$account = $context['account'];
		$adapter = $this->registry->forNetwork(network: (string)($account['network'] ?? ''));

		$request = new SocialPublishRequest(
			network: (string)($account['network'] ?? ''),
			body: (string)($publication['preparedBody'] ?? ''),
			handle: (string)($account['handle'] ?? ''),
		);

		$media = ($publication['preparedMedia'] ?? []);
		if (is_array($media) === false) {
			$media = [];
		}

		return [
			'share' => [
				'publicationId' => $publicationId,
				'network' => (string)($account['network'] ?? ''),
				'handle' => (string)($account['handle'] ?? ''),
				'status' => (string)($publication['status'] ?? ''),
				'body' => $request->body,
				'media' => $media,
				'composerUrl' => ($adapter?->composerUrl(request: $request) ?? ''),
			],
		];
	}//end shareBundle()

	/**
	 * Record that the owner posted it.
	 *
	 * @param string $publicationId The publication.
	 * @param string $uid The caller, who must be the account's owner or an administrator.
	 * @param string $url Where it landed, when they know.
	 *
	 * @return array{error?: string, publication?: array<string, mixed>} The updated row, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-an-account-no-application-may-post-to-asks-its-owner-to-share
	 */
	public function confirmShare(string $publicationId, string $uid, string $url = ''): array {
		$context = $this->authorised(publicationId: $publicationId, uid: $uid);
		if (isset($context['error']) === true) {
			return ['error' => $context['error']];
		}

		$publication = $context['publication'];
		if ((string)($publication['status'] ?? '') !== SocialPublicationStore::AWAITING_SHARE) {
			return ['error' => 'This publication is not waiting for a share.'];
		}

		$publication['status'] = SocialPublicationStore::SHARED;
		$publication['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		if (trim($url) !== '') {
			$publication['url'] = trim($url);
		}

		$saved = $this->publications->save(publication: $publication);
		if ($saved === null) {
			return ['error' => 'The share could not be recorded.'];
		}

		return ['publication' => $saved];
	}//end confirmShare()

	/**
	 * Load the publication and its account, and refuse a caller who is neither
	 * the owner nor an administrator.
	 *
	 * @param string $publicationId The publication.
	 * @param string $uid The caller.
	 *
	 * @return array<string, mixed> Either an `error`, or `publication` and `account`.
	 */
	private function authorised(string $publicationId, string $uid): array {
		$publication = $this->publications->find(publicationId: $publicationId);
		if ($publication === null) {
			return ['error' => 'That publication does not exist.'];
		}

		$account = $this->accounts->getAccount(accountId: (string)($publication['accountId'] ?? ''));
		if ($account === null) {
			return ['error' => 'That publication has no account.'];
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'This share belongs to somebody else.'];
		}

		return ['publication' => $publication, 'account' => $account];
	}//end authorised()
}//end class
