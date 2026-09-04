<?php

/**
 * Pipelinq SubscriptionQueryService.
 *
 * The read side of mailing-list membership: how many people are in each
 * state, which memberships a list or a contact holds, and which of them a
 * blast may actually reach.
 *
 * It is separate from {@see \OCA\Pipelinq\Service\SubscriptionService}
 * because the two have different callers and different risks. Nothing here
 * writes, so a page, a widget or the blast engine can ask freely; every
 * state change goes through the other service, where the lifecycle and the
 * consent ledger are enforced together.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\SubscriptionService;

/**
 * SubscriptionQueryService — counts, listings and the blast audience.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
 */
class SubscriptionQueryService {
	/**
	 * The channel a mailing list sends on.
	 *
	 * @var string
	 */
	private const CHANNEL = 'email';

	/**
	 * Default Subscription schema slug, matching the register fragment.
	 *
	 * @var string
	 */
	private const DEFAULT_SUBSCRIPTION_SCHEMA_SLUG = 'subscription';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped, session-free object access.
	 * @param ComplianceService $compliance The consent ledger.
	 * @param ListTokenService $tokens Signs the per-recipient unsubscribe link.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
	 */
	public function __construct(
		private ListObjectStore $store,
		private ComplianceService $compliance,
		private ListTokenService $tokens,
	) {
	}//end __construct()

	/**
	 * The audience a blast targeting this list should reach, and what it
	 * should skip.
	 *
	 * `members` is shaped exactly as `SegmentService::getMembersForBlast()`
	 * returns, so `BlastService` never branches on where its audience came
	 * from. `skipped` names every membership that was NOT queued, so the
	 * send summary can report a pending or unsubscribed member the same way
	 * it reports a contact without a ConsentRecord.
	 *
	 * Both answers come from one pass. Counting the skipped rows separately
	 * would let the two disagree the moment a row changed state between the
	 * calls, and the summary would then claim a total that never existed.
	 *
	 * @param string $listId MailingList UUID or slug.
	 *
	 * @return array{
	 *     members: array<int, array{contactId: string, email: string, subscriptionId: string, unsubscribeUrl: string}>,
	 *     skipped: array<int, string>
	 * }
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
	 */
	public function getBlastAudienceForList(string $listId): array {
		if ($listId === '') {
			return ['members' => [], 'skipped' => []];
		}

		$members = [];
		$skipped = [];
		foreach ($this->rowsForList(listId: $listId) as $subscription) {
			$contactId = $this->contactKeyFor(subscription: $subscription);
			if ((string)($subscription['state'] ?? '') !== SubscriptionService::STATE_CONFIRMED) {
				$skipped[] = $contactId;
				continue;
			}

			$permitted = $this->compliance->hasConsentForList(
				contactId: $contactId,
				listId: $listId,
				channel: self::CHANNEL,
			);
			if ($permitted === false) {
				$skipped[] = $contactId;
				continue;
			}

			$subscriptionId = $this->store->idOf(payload: $subscription);
			$members[] = [
				'contactId' => $contactId,
				'email' => (string)($subscription['email'] ?? ''),
				'subscriptionId' => $subscriptionId,
				'unsubscribeUrl' => $this->unsubscribeUrlFor(
					subscriptionId: $subscriptionId,
					contactId: $contactId,
				),
			];
		}

		return ['members' => $members, 'skipped' => $skipped];
	}//end getBlastAudienceForList()

	/**
	 * The first-party unsubscribe link for one membership.
	 *
	 * Minted here rather than at dispatch time because the audience pass
	 * already holds the subscription id and the contact id, and signing is
	 * pure computation: a send to fifty thousand people costs fifty thousand
	 * HMACs and not one extra query.
	 *
	 * Rule 1 of the marketing architecture: the link a recipient follows to
	 * leave is ours, whatever transport carried the mail.
	 *
	 * @param string $subscriptionId Subscription UUID or slug.
	 * @param string $contactId Contact the subscription belongs to, so the
	 *                          page can offer to leave every list at once.
	 *
	 * @return string Absolute URL, or an empty string when either id is
	 *                missing.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	public function unsubscribeUrlFor(string $subscriptionId, string $contactId): string {
		if ($subscriptionId === '' || $contactId === '') {
			return '';
		}

		return $this->tokens->unsubscribeUrl(
			token: $this->tokens->signUnsubscribeToken(
				subscriptionId: $subscriptionId,
				contactId: $contactId,
			),
		);
	}//end unsubscribeUrlFor()

	/**
	 * Per-state counts for one list.
	 *
	 * @param string $listId MailingList UUID or slug.
	 *
	 * @return array{pending: int, confirmed: int, unsubscribed: int, bounced: int, total: int}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function countsForList(string $listId): array {
		$counts = [
			SubscriptionService::STATE_PENDING => 0,
			SubscriptionService::STATE_CONFIRMED => 0,
			SubscriptionService::STATE_UNSUBSCRIBED => 0,
			SubscriptionService::STATE_BOUNCED => 0,
		];

		$total = 0;
		foreach ($this->rowsForList(listId: $listId) as $subscription) {
			$total++;
			$state = (string)($subscription['state'] ?? '');
			if (isset($counts[$state]) === true) {
				$counts[$state]++;
			}
		}

		$counts['total'] = $total;
		return $counts;
	}//end countsForList()

	/**
	 * The memberships of one list, with a pagination envelope.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param int $page 1-based page number.
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
	 */
	public function listSubscriptionsForList(string $listId, int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->rowsForList(listId: $listId);
		$total = count($all);

		$pages = 0;
		if ($total > 0) {
			$pages = (int)ceil($total / $limit);
		}

		return [
			'data' => array_slice($all, (($page - 1) * $limit), $limit),
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $pages,
			],
		];
	}//end listSubscriptionsForList()

	/**
	 * Every membership one contact holds.
	 *
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> Subscription payloads.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function listSubscriptionsForContact(string $contactId): array {
		if ($contactId === '') {
			return [];
		}

		return $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['contactId' => $contactId],
		);
	}//end listSubscriptionsForContact()

	/**
	 * Every membership of one list.
	 *
	 * @param string $listId MailingList UUID or slug.
	 *
	 * @return array<int, array<string, mixed>> Subscription payloads.
	 */
	private function rowsForList(string $listId): array {
		if ($listId === '') {
			return [];
		}

		return $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['listId' => $listId],
		);
	}//end rowsForList()

	/**
	 * The key the consent ledger is written under for a membership.
	 *
	 * A public signup not yet matched to a contact still needs a ledger
	 * entry, so the address stands in for the contact id. Mirrors
	 * `SubscriptionService::contactKeyFor()`, which writes what this reads.
	 *
	 * @param array<string, mixed> $subscription The membership.
	 *
	 * @return string The consent key.
	 */
	private function contactKeyFor(array $subscription): string {
		$contactId = (string)($subscription['contactId'] ?? '');
		if ($contactId !== '') {
			return $contactId;
		}

		return (string)($subscription['email'] ?? '');
	}//end contactKeyFor()

	/**
	 * Resolve the Subscription schema slug.
	 *
	 * @return string Schema slug.
	 */
	private function schemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'subscription_schema',
			default: self::DEFAULT_SUBSCRIPTION_SCHEMA_SLUG,
		);
	}//end schemaSlug()
}//end class
