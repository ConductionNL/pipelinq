<?php

/**
 * Pipelinq SocialPostService.
 *
 * The composer's object and the publishing rule: one post, shaped per network,
 * approved by a person, sent to each named account on its own.
 *
 * Four things live here that nothing else can express.
 *
 * VARIANTS MERGE, THEY DO NOT REPLACE. A variant carrying only a body still
 * gets the post's link and media. The alternative, a full copy per network,
 * drifts the moment somebody fixes a typo in one of five places.
 * `src/services/socialComposer.js` mirrors this rule so the composer's preview
 * is produced by the same merge that will do the sending, and both are tested
 * against the same table.
 *
 * THE APPROVAL IS THE GATE. Rule 4 of the marketing architecture says agents
 * propose and people dispose, so a post reaches `scheduled` only through
 * {@see approve()}, the approval is stamped from the session, and a request
 * body claiming somebody else approved it changes nothing.
 *
 * ONE ACCOUNT'S FAILURE IS ITS OWN. A publication row exists for every named
 * account before anything is sent, so a refusal has somewhere to be recorded.
 * A post to five accounts that reaches three is three publications and two
 * failures, each with the reason that produced it, not one post that "failed".
 *
 * X IS METERED BEFORE IT IS CALLED. Every X post and every X read is charged,
 * so the tenant's spend budget is asked BEFORE the call rather than reconciled
 * after it. An exhausted hard stop refuses without spending anything, which is
 * the only version of a spend cap that is worth having.
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
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Social\SocialAdapterRegistry;
use OCA\Pipelinq\Service\Social\SocialGatewayResult;
use OCA\Pipelinq\Service\Social\SocialNetworkAdapter;
use OCA\Pipelinq\Service\Social\SocialPublicationStore;
use OCA\Pipelinq\Service\Social\SocialPublishOutcome;
use OCA\Pipelinq\Service\Social\SocialPublishRequest;
use OCP\IAppConfig;

/**
 * The social post lifecycle, its variants and its publishing.
 *
 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The composer surface, the
 *  approval gate and the publishing loop are one cohesive object lifecycle;
 *  splitting them would put the approval check somewhere the publish path has
 *  to reach across for, which is how a gate gets skipped.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The publish path is a
 *  join across accounts, adapters, publications, the campaign decorator, the
 *  spend budget and the share path; every one of those is a collaborator this
 *  service must consult once per account and none of them is incidental.
 * @SuppressWarnings(PHPMD.StaticAccess) `SocialPublishOutcome` is a value
 *  object with named constructors, and `CampaignLinkDecorator::slugify()` is a
 *  pure string function the mailings already call the same way. Injecting a
 *  factory for either would add a collaborator that constructs a struct.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The aggregate is the sum
 *  of several independently-simple concerns: the composer's CRUD, the approval
 *  gate, the per-account publish loop and the settle rule. Each method stays
 *  short; splitting the class would separate the gate from the path it gates,
 *  which is the arrangement that lets a gate be skipped.
 */
class SocialPostService {
	/**
	 * The post schema slug, matching the register fragment.
	 *
	 * @var string
	 */
	public const SCHEMA = 'socialPost';

	/**
	 * App-config key that may override the schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'social_post_schema';

	/**
	 * Being written.
	 *
	 * @var string
	 */
	public const STATUS_DRAFT = 'draft';

	/**
	 * Waiting for a person to decide.
	 *
	 * @var string
	 */
	public const STATUS_APPROVAL = 'approval';

	/**
	 * Approved, waiting for its moment.
	 *
	 * @var string
	 */
	public const STATUS_SCHEDULED = 'scheduled';

	/**
	 * Every named account is done.
	 *
	 * @var string
	 */
	public const STATUS_PUBLISHED = 'published';

	/**
	 * At least one named account did not go out.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The publication statuses that count as done for the post's own status.
	 * `awaiting_share` is one of them: the post has left Pipelinq's hands even
	 * though the person has not posted it yet, and holding the post open until
	 * they do would leave every advocacy post permanently unfinished.
	 *
	 * @var array<int, string>
	 */
	public const SETTLED_STATUSES = [
		SocialPublicationStore::PUBLISHED,
		SocialPublicationStore::SHARED,
		SocialPublicationStore::AWAITING_SHARE,
	];

	/**
	 * Fields a client may set. `status`, `approvals`, `agentAuthored`,
	 * `agentAuthoredBy`, `createdBy`, `publishedAt` and `failureReason` are
	 * written by this service and are ignored wherever a client sends them.
	 *
	 * @var array<int, string>
	 */
	public const CLIENT_WRITABLE = [
		'title',
		'articleId',
		'campaignId',
		'body',
		'media',
		'link',
		'accountIds',
		'variants',
		'scheduledFor',
	];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store The register-scoped object plumbing.
	 * @param SocialAccountService $accounts The accounts and their ownership guard.
	 * @param SocialAdapterRegistry $registry The network adapters.
	 * @param SocialPublicationStore $publications The per-account result rows.
	 * @param SocialAdvocacyService $advocacy The prepared-share path.
	 * @param CampaignLinkDecorator $links The campaign UTM decorator the mailings already use.
	 * @param CampaignService $campaigns The campaigns, for the frozen UTM value a campaign owns.
	 * @param BudgetService $budget The per-tenant spend budget.
	 * @param IAppConfig $appConfig App config, for the tenant id.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ListObjectStore $store,
		private readonly SocialAccountService $accounts,
		private readonly SocialAdapterRegistry $registry,
		private readonly SocialPublicationStore $publications,
		private readonly SocialAdvocacyService $advocacy,
		private readonly CampaignLinkDecorator $links,
		private readonly CampaignService $campaigns,
		private readonly BudgetService $budget,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The schema slug, honouring an app-config override.
	 *
	 * @return string The schema slug.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function schema(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA);
	}//end schema()

	/**
	 * Every post, optionally filtered by status.
	 *
	 * @param string $status A status to filter on, or an empty string for all.
	 *
	 * @return array{data: array<int, array<string, mixed>>} The posts.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function listPosts(string $status = ''): array {
		$filters = [];
		if ($status !== '') {
			$filters['status'] = $status;
		}

		return ['data' => $this->store->findAll(schemaSlug: $this->schema(), filters: $filters)];
	}//end listPosts()

	/**
	 * One post, or null.
	 *
	 * @param string $postId The post.
	 *
	 * @return array<string, mixed>|null The post, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function getPost(string $postId): ?array {
		return $this->store->find(schemaSlug: $this->schema(), id: $postId);
	}//end getPost()

	/**
	 * Create a post as a draft.
	 *
	 * @param array<string, mixed> $payload The client's fields.
	 * @param string $uid The author, stamped from the session.
	 * @param string $agent The agent that drafted it, when an agent did (ADR-088).
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function createPost(array $payload, string $uid, string $agent = ''): array {
		if (trim((string)($payload['body'] ?? '')) === '') {
			return ['error' => 'A post needs something to say.'];
		}

		$post = $this->clientFields(payload: $payload);
		$post['status'] = self::STATUS_DRAFT;
		$post['approvals'] = [];
		$post['createdBy'] = $uid;
		// The mark comes from the write path, never from the request. A client
		// claiming a person wrote what an agent wrote is the exact case ADR-088
		// exists to prevent.
		$post['agentAuthored'] = ($agent !== '');
		$post['agentAuthoredBy'] = $agent;

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post);
		if ($saved === null) {
			return ['error' => 'The post could not be saved.'];
		}

		return ['post' => $saved];
	}//end createPost()

	/**
	 * Update a draft. A post that is not a draft cannot be edited: it has
	 * either been approved or already gone out.
	 *
	 * @param string $postId The post.
	 * @param array<string, mixed> $payload The client's fields.
	 * @param string $uid The editor, who takes authorship from an agent.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function updatePost(string $postId, array $payload, string $uid): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_DRAFT) {
			return ['error' => 'Only a draft can be edited. Return this post to draft first.'];
		}

		$update = array_merge($post, $this->clientFields(payload: $payload));
		if ((bool)($post['agentAuthored'] ?? false) === true) {
			// A person editing an agent's draft takes authorship of it.
			$update['agentAuthored'] = false;
			$update['agentAuthoredBy'] = '';
			$update['createdBy'] = $uid;
		}

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $update, id: $postId);
		if ($saved === null) {
			return ['error' => 'The post could not be saved.'];
		}

		return ['post' => $saved];
	}//end updatePost()

	/**
	 * The body, link and media one network gets: the post's own values with
	 * that network's variant merged on top of them.
	 *
	 * @param array<string, mixed> $post The post.
	 * @param string $network The network.
	 *
	 * @return array{body: string, link: string, media: array<int, array<string, mixed>>} The resolved values.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function resolveVariant(array $post, string $network): array {
		$media = ($post['media'] ?? []);
		if (is_array($media) === false) {
			$media = [];
		}

		$resolved = [
			'body' => (string)($post['body'] ?? ''),
			'link' => (string)($post['link'] ?? ''),
			'media' => $media,
		];

		$variants = ($post['variants'] ?? []);
		$variant = null;
		if (is_array($variants) === true) {
			$variant = ($variants[$network] ?? null);
		}

		if (is_array($variant) === false) {
			return $resolved;
		}

		foreach (['body', 'link'] as $field) {
			$value = trim((string)($variant[$field] ?? ''));
			if ($value !== '') {
				$resolved[$field] = $value;
			}
		}

		$variantMedia = ($variant['media'] ?? null);
		if (is_array($variantMedia) === true && $variantMedia !== []) {
			$resolved['media'] = $variantMedia;
		}

		return $resolved;
	}//end resolveVariant()

	/**
	 * Every network whose resolved body is longer than that network accepts.
	 *
	 * Checked at approval rather than at publish, because a refusal three
	 * hours after the marketer went home is a refusal nobody sees.
	 *
	 * @param array<string, mixed> $post The post.
	 *
	 * @return array<int, string> One readable line per network that does not fit.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-one-post-carries-per-network-variants
	 */
	public function overlongVariants(array $post): array {
		$problems = [];
		foreach ($this->networksOf(post: $post) as $network) {
			$adapter = $this->registry->forNetwork(network: $network);
			if ($adapter === null) {
				continue;
			}

			$length = mb_strlen($this->resolveVariant(post: $post, network: $network)['body']);
			if ($length <= $adapter->bodyLimit()) {
				continue;
			}

			$problems[] = sprintf(
				'The text for %s is %d characters and %s accepts %d.',
				$network,
				$length,
				$network,
				$adapter->bodyLimit(),
			);
		}

		return $problems;
	}//end overlongVariants()

	/**
	 * Put a draft up for approval, refusing when a variant does not fit.
	 *
	 * @param string $postId The post.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function submitForApproval(string $postId): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_DRAFT) {
			return ['error' => 'Only a draft can be submitted for approval.'];
		}

		if ($this->networksOf(post: $post) === []) {
			return ['error' => 'Pick at least one account for this post to go to.'];
		}

		$problems = $this->overlongVariants(post: $post);
		if ($problems !== []) {
			return ['error' => implode(' ', $problems)];
		}

		$post['status'] = self::STATUS_APPROVAL;
		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post, id: $postId);
		if ($saved === null) {
			return ['error' => 'The post could not be saved.'];
		}

		return ['post' => $saved];
	}//end submitForApproval()

	/**
	 * Approve a post so it may go out at its moment.
	 *
	 * @param string $postId The post.
	 * @param string $uid The approver, taken from the session.
	 * @param string $note Their words, when they left any.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function approve(string $postId, string $uid, string $note = ''): array {
		return $this->decide(
			postId: $postId,
			uid: $uid,
			decision: 'approved',
			note: $note,
			nextStatus: self::STATUS_SCHEDULED,
		);
	}//end approve()

	/**
	 * Reject a post, sending it back to the marketer.
	 *
	 * @param string $postId The post.
	 * @param string $uid The reviewer, taken from the session.
	 * @param string $note Why.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-nothing-leaves-the-instance-without-a-human-approval
	 */
	public function reject(string $postId, string $uid, string $note = ''): array {
		return $this->decide(
			postId: $postId,
			uid: $uid,
			decision: 'rejected',
			note: $note,
			nextStatus: self::STATUS_DRAFT,
		);
	}//end reject()

	/**
	 * Publish every approved post whose moment has arrived.
	 *
	 * @param string|null $now The moment to compare against, for tests. Defaults to now.
	 *
	 * @return int How many posts were attempted.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function publishDuePosts(?string $now = null): int {
		$moment = ($now ?? gmdate('Y-m-d\TH:i:s\Z'));
		$attempted = 0;

		foreach ($this->store->findAll(schemaSlug: $this->schema(), filters: ['status' => self::STATUS_SCHEDULED]) as $post) {
			$due = trim((string)($post['scheduledFor'] ?? ''));
			if ($due !== '' && strcmp($due, $moment) > 0) {
				continue;
			}

			$this->publishPost(postId: $this->store->idOf(payload: $post));
			$attempted++;
		}

		return $attempted;
	}//end publishDuePosts()

	/**
	 * Publish one post to every account it names, each on its own.
	 *
	 * @param string $postId The post.
	 *
	 * @return array{error?: string, post?: array<string, mixed>, publications?: array<int, array<string, mixed>>}
	 *         The settled post and its publications, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function publishPost(string $postId): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_SCHEDULED) {
			// An unapproved post is never published, whatever its schedule says.
			return ['error' => 'Only an approved post is published.'];
		}

		$accountIds = ($post['accountIds'] ?? []);
		if (is_array($accountIds) === false) {
			$accountIds = [];
		}

		$rows = [];
		foreach ($accountIds as $accountId) {
			$rows[] = $this->publishToAccount(post: $post, accountId: (string)$accountId);
		}

		return ['post' => $this->settle(postId: $postId, post: $post), 'publications' => array_filter($rows)];
	}//end publishPost()

	/**
	 * Try one failed publication again.
	 *
	 * A failure a retry cannot fix is refused with the reason it already
	 * carries, rather than making the same call a second time to fail the same
	 * way.
	 *
	 * @param string $publicationId The publication.
	 * @param string $uid The caller.
	 *
	 * @return array{error?: string, publication?: array<string, mixed>} The row, or an error.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	public function retryPublication(string $publicationId, string $uid): array {
		$publication = $this->publications->find(publicationId: $publicationId);
		if ($publication === null) {
			return ['error' => 'That publication does not exist.'];
		}

		$account = $this->accounts->getAccount(accountId: (string)($publication['accountId'] ?? ''));
		if ($account === null) {
			return ['error' => 'That publication has no account.'];
		}

		if ($this->accounts->mayActOn(uid: $uid, account: $account) === false) {
			return ['error' => 'You may not publish to this account.'];
		}

		$code = (string)($publication['failureCode'] ?? '');
		if (in_array($code, SocialGatewayResult::RETRYABLE, true) === false) {
			return ['error' => (string)($publication['failureReason'] ?? 'This failure cannot be fixed by trying again.')];
		}

		$postId = (string)($publication['postId'] ?? '');
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That publication has no post.'];
		}

		$row = $this->publishToAccount(post: $post, accountId: (string)($publication['accountId'] ?? ''));
		$this->settle(postId: $postId, post: $post);

		return ['publication' => ($row ?? $publication)];
	}//end retryPublication()

	/**
	 * Publish one post to one account, recording whatever happened.
	 *
	 * @param array<string, mixed> $post The post.
	 * @param string $accountId The account.
	 *
	 * @return array<string, mixed>|null The publication row, or null when it could not be written.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	private function publishToAccount(array $post, string $accountId): ?array {
		$account = $this->accounts->getAccount(accountId: $accountId);
		$network = '';
		if ($account !== null) {
			$network = (string)($account['network'] ?? '');
		}

		$publication = $this->publications->open(
			postId: $this->store->idOf(payload: $post),
			accountId: $accountId,
			network: $network,
		);

		if ($publication === null) {
			return null;
		}

		if ($account === null) {
			return $this->publications->record(
				publication: $publication,
				outcome: SocialPublishOutcome::refused(
					code: SocialGatewayResult::NOT_CONFIGURED,
					reason: 'This post names an account that no longer exists.',
				),
			);
		}

		$request = $this->requestFor(post: $post, account: $account);

		// The accounts no application may post to never reach an adapter.
		if ((string)($account['publishMode'] ?? 'api') === 'share') {
			return $this->advocacy->requestShare(
				publication: $publication,
				account: $account,
				post: $post,
				request: $request,
			);
		}

		$adapter = $this->registry->forNetwork(network: $network);
		if ($adapter === null) {
			return $this->publications->record(
				publication: $publication,
				outcome: SocialPublishOutcome::refused(
					code: SocialGatewayResult::NOT_CONFIGURED,
					reason: 'Pipelinq cannot publish to ' . $network . ' yet.',
				),
			);
		}

		$stop = $this->budgetRefusal(adapter: $adapter);
		if ($stop !== null) {
			return $this->publications->record(publication: $publication, outcome: $stop);
		}

		$outcome = $adapter->publish(request: $request);
		$recorded = $this->publications->record(publication: $publication, outcome: $outcome);
		$this->afterAttempt(accountId: $accountId, adapter: $adapter, outcome: $outcome);

		return $recorded;
	}//end publishToAccount()

	/**
	 * Build the resolved request one account gets, with the campaign link
	 * already decorated and the account's owner already asserted.
	 *
	 * @param array<string, mixed> $post The post.
	 * @param array<string, mixed> $account The account.
	 *
	 * @return SocialPublishRequest The request.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-a-posts-link-carries-its-campaign
	 */
	private function requestFor(array $post, array $account): SocialPublishRequest {
		$network = (string)($account['network'] ?? '');
		$resolved = $this->resolveVariant(post: $post, network: $network);

		// ADR-099: the identity a run executes as is a property of its subject.
		// The account's owner is asserted to the broker, never the person who
		// created or approved the post.
		$owner = trim((string)($account['ownerUserId'] ?? ''));
		$acting = null;
		if ($owner !== '') {
			$acting = $owner;
		}

		return new SocialPublishRequest(
			network: $network,
			body: $resolved['body'],
			link: $this->decoratedLink(post: $post, network: $network, link: $resolved['link']),
			media: $resolved['media'],
			credentialRef: (string)($account['credentialRef'] ?? ''),
			externalAccountId: (string)($account['externalAccountId'] ?? ''),
			accountKind: (string)($account['kind'] ?? 'organisation'),
			handle: (string)($account['handle'] ?? ''),
			actingUserId: $acting,
		);
	}//end requestFor()

	/**
	 * The link with this post's campaign parameters, or the link unchanged.
	 *
	 * The STORED link is never touched, so moving a post to another campaign
	 * does not mean unpicking a query string.
	 *
	 * @param array<string, mixed> $post The post.
	 * @param string $network The network, which becomes the medium.
	 * @param string $link The resolved link.
	 *
	 * @return string The link to publish.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-a-posts-link-carries-its-campaign
	 */
	private function decoratedLink(array $post, string $network, string $link): string {
		$campaignId = trim((string)($post['campaignId'] ?? ''));
		if ($link === '' || $campaignId === '' || $this->links->isEnabled() === false) {
			return $link;
		}

		// The campaign OWNS its utm value: CampaignService mints it once and
		// freezes it across a rename, so two mailings and three posts of one
		// campaign roll up together. Slugifying the id here instead would put a
		// UUID in the query string and split the campaign into as many rows as
		// it has channels. A campaignId naming no campaign row still works,
		// because a marketer can fill the field in before a campaign exists.
		$campaign = CampaignLinkDecorator::slugify(
			value: (string)($this->campaigns->find(id: $campaignId)['utmCampaign'] ?? $campaignId),
		);
		if ($campaign === '') {
			return $link;
		}

		$decorated = $this->links->decorateUrl(
			url: $link,
			utm: [
				'utm_source' => 'social',
				'utm_medium' => $network,
				'utm_campaign' => $campaign,
				'utm_content' => $this->store->idOf(payload: $post),
			],
		);

		return ($decorated ?? $link);
	}//end decoratedLink()

	/**
	 * The spend refusal for a network that charges, or null when it may go.
	 *
	 * @param SocialNetworkAdapter $adapter The adapter, for its cost and network.
	 *
	 * @return SocialPublishOutcome|null The refusal, or null.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-to-x-stops-at-the-tenants-spend-budget
	 */
	private function budgetRefusal(SocialNetworkAdapter $adapter): ?SocialPublishOutcome {
		$cost = $adapter->costPerPost();
		if ($cost <= 0.0) {
			return null;
		}

		$allowed = $this->budget->canSend(
			tenantId: $this->tenantId(),
			providerId: $adapter->network(),
			estimatedCostEur: $cost,
		);
		if ($allowed === true) {
			return null;
		}

		return SocialPublishOutcome::refused(
			code: SocialGatewayResult::BUDGET_EXHAUSTED,
			reason: 'The spend budget for ' . $adapter->network() . ' is reached, so nothing was sent.',
		);
	}//end budgetRefusal()

	/**
	 * What follows an attempt: charge the budget when it went, and mark the
	 * account for a reconnect when the grant is gone.
	 *
	 * @param string $accountId The account.
	 * @param SocialNetworkAdapter $adapter The adapter.
	 * @param SocialPublishOutcome $outcome What happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	private function afterAttempt(string $accountId, SocialNetworkAdapter $adapter, SocialPublishOutcome $outcome): void {
		if ($outcome->accepted === true && $outcome->cost > 0.0) {
			$this->budget->recordSend(
				tenantId: $this->tenantId(),
				providerId: $adapter->network(),
				costEur: $outcome->cost,
			);
		}

		if ($outcome->failureCode === SocialGatewayResult::RELINK_NEEDED) {
			$this->accounts->markRelinkNeeded(accountId: $accountId, reason: $outcome->failureReason);
		}
	}//end afterAttempt()

	/**
	 * Derive the post's status from its publications and store it.
	 *
	 * The schema grammar cannot say "published to three accounts of five",
	 * which is why this rule is here rather than in an
	 * `x-openregister-lifecycle`.
	 *
	 * @param string $postId The post.
	 * @param array<string, mixed> $post The post as read.
	 *
	 * @return array<string, mixed> The settled post.
	 *
	 * @spec openspec/changes/social-publishing/specs/social-posts/spec.md#requirement-publishing-runs-on-a-timed-job-one-account-at-a-time
	 */
	private function settle(string $postId, array $post): array {
		$rows = $this->publications->forPost(postId: $postId);
		$failures = [];
		$done = 0;

		foreach ($rows as $row) {
			$status = (string)($row['status'] ?? '');
			if ($status === SocialPublicationStore::FAILED) {
				$failures[] = (string)($row['network'] ?? '') . ': ' . (string)($row['failureReason'] ?? '');
				continue;
			}

			if (in_array($status, self::SETTLED_STATUSES, true) === true) {
				$done++;
			}
		}

		$post['status'] = self::STATUS_SCHEDULED;
		$post['failureReason'] = '';
		if ($failures !== []) {
			$post['status'] = self::STATUS_FAILED;
			$post['failureReason'] = implode(' ', $failures);
		} elseif ($rows !== [] && $done === count($rows)) {
			$post['status'] = self::STATUS_PUBLISHED;
			if (trim((string)($post['publishedAt'] ?? '')) === '') {
				$post['publishedAt'] = gmdate('Y-m-d\TH:i:s\Z');
			}
		}

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post, id: $postId);

		return ($saved ?? $post);
	}//end settle()

	/**
	 * Record one human decision and move the post.
	 *
	 * @param string $postId The post.
	 * @param string $uid The person deciding.
	 * @param string $decision `approved` or `rejected`.
	 * @param string $note Their words.
	 * @param string $nextStatus Where the post goes.
	 *
	 * @return array{error?: string, post?: array<string, mixed>} The post, or an error.
	 */
	private function decide(string $postId, string $uid, string $decision, string $note, string $nextStatus): array {
		$post = $this->getPost(postId: $postId);
		if ($post === null) {
			return ['error' => 'That post does not exist.'];
		}

		if ((string)($post['status'] ?? '') !== self::STATUS_APPROVAL) {
			return ['error' => 'This post is not waiting for approval.'];
		}

		$approvals = ($post['approvals'] ?? []);
		if (is_array($approvals) === false) {
			$approvals = [];
		}

		// The approver is the session, always. A body naming somebody else is
		// ignored rather than refused, because refusing would tell a caller
		// which field it guessed right.
		$approvals[] = [
			'userId' => $uid,
			'decision' => $decision,
			'decidedAt' => gmdate('Y-m-d\TH:i:s\Z'),
			'note' => $note,
		];

		$post['approvals'] = $approvals;
		$post['status'] = $nextStatus;

		$saved = $this->store->save(schemaSlug: $this->schema(), payload: $post, id: $postId);
		if ($saved === null) {
			return ['error' => 'The decision could not be saved.'];
		}

		return ['post' => $saved];
	}//end decide()

	/**
	 * The networks a post goes to, derived from the accounts it names.
	 *
	 * @param array<string, mixed> $post The post.
	 *
	 * @return array<int, string> The distinct networks.
	 */
	private function networksOf(array $post): array {
		$accountIds = ($post['accountIds'] ?? []);
		if (is_array($accountIds) === false) {
			return [];
		}

		$networks = [];
		foreach ($accountIds as $accountId) {
			$account = $this->accounts->getAccount(accountId: (string)$accountId);
			$network = trim((string)($account['network'] ?? ''));
			if ($network !== '' && in_array($network, $networks, true) === false) {
				$networks[] = $network;
			}
		}

		return $networks;
	}//end networksOf()

	/**
	 * Take only the fields a client may set.
	 *
	 * @param array<string, mixed> $payload The client's payload.
	 *
	 * @return array<string, mixed> The accepted subset.
	 */
	private function clientFields(array $payload): array {
		$out = [];
		foreach (self::CLIENT_WRITABLE as $field) {
			if (array_key_exists($field, $payload) === true) {
				$out[$field] = $payload[$field];
			}
		}

		return $out;
	}//end clientFields()

	/**
	 * The tenant the spend budget is kept for, matching the SMS and WhatsApp
	 * adapters so one tenant is one tenant across every metered channel.
	 *
	 * @return string The tenant id.
	 */
	private function tenantId(): string {
		$tenantId = $this->appConfig->getValueString(Application::APP_ID, 'tenant_id', '');
		if ($tenantId !== '') {
			return $tenantId;
		}

		return 'default';
	}//end tenantId()
}//end class
