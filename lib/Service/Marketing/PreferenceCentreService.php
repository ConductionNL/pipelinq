<?php

/**
 * Pipelinq PreferenceCentreService.
 *
 * The page a subscriber reaches from any message: every list they may hold,
 * each showing whether they are on it, and one save that confirms what was
 * ticked and closes what was not.
 *
 * The preference centre is itself the confirmation step. The person already
 * proved they hold the signed link, which arrived at the address the
 * membership is stored under, so ticking a list confirms it outright rather
 * than starting a second double opt-in the same person would have to answer
 * from the same inbox.
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
 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCA\Pipelinq\Service\ListTokenService;
use OCA\Pipelinq\Service\MailingListService;
use OCA\Pipelinq\Service\SubscriptionService;

/**
 * PreferenceCentreService — read and save a subscriber's list choices.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
 */
class PreferenceCentreService {
	/**
	 * Constructor.
	 *
	 * @param ListTokenService $tokens Signed-link minting and verification.
	 * @param MailingListService $lists The lists a contact may hold.
	 * @param SubscriptionQueryService $queries Membership reads.
	 * @param SubscriptionService $subscriptions Membership state changes.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function __construct(
		private ListTokenService $tokens,
		private MailingListService $lists,
		private SubscriptionQueryService $queries,
		private SubscriptionService $subscriptions,
	) {
	}//end __construct()

	/**
	 * Mint a preference-centre link for one contact.
	 *
	 * Minted rather than stored, so there is no link to leak from a row and
	 * none to invalidate when the contact changes.
	 *
	 * @param string $contactId Contact UUID or slug.
	 *
	 * @return string Absolute URL.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function preferencesUrlFor(string $contactId): string {
		return $this->tokens->preferencesUrl(
			token: $this->tokens->signPreferencesToken(contactId: $contactId),
		);
	}//end preferencesUrlFor()

	/**
	 * What the preference centre shows for a signed link.
	 *
	 * Returns every list the contact may hold, each with its current state,
	 * and nothing belonging to anyone else. An archived list is left out:
	 * offering it would let someone opt in to something that sends nothing.
	 *
	 * @param string $token The signed preferences token.
	 *
	 * @return array<int, array{id: string, name: string, description: string, subscribed: bool}>|null
	 *         Null when the token is unusable.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function preferencesForToken(string $token): ?array {
		$contactId = $this->contactIdFor(token: $token);
		if ($contactId === null) {
			return null;
		}

		$states = [];
		foreach ($this->queries->listSubscriptionsForContact(contactId: $contactId) as $subscription) {
			$states[(string)($subscription['listId'] ?? '')] = (string)($subscription['state'] ?? '');
		}

		$out = [];
		foreach ($this->lists->listMailingLists(page: 1, limit: 100)['data'] as $list) {
			if ((string)($list['status'] ?? 'active') === 'archived') {
				continue;
			}

			$projection = $this->lists->publicProjection(list: $list);
			$out[] = [
				'id' => $projection['id'],
				'name' => $projection['name'],
				'description' => $projection['description'],
				'subscribed' => (($states[$projection['id']] ?? '') === SubscriptionService::STATE_CONFIRMED),
			];
		}

		return $out;
	}//end preferencesForToken()

	/**
	 * Save a preference choice.
	 *
	 * A ticked list the contact is not confirmed on is confirmed, and a list
	 * they hold and did not tick is closed. Both halves happen in one call,
	 * so a save can never leave the person half-moved.
	 *
	 * @param string $token The signed preferences token.
	 * @param array<int, string> $selectedListIds The lists that were ticked.
	 *
	 * @return array{status: string, confirmed: int, unsubscribed: int}
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function savePreferences(string $token, array $selectedListIds): array {
		$contactId = $this->contactIdFor(token: $token);
		if ($contactId === null) {
			return ['status' => 'invalid', 'confirmed' => 0, 'unsubscribed' => 0];
		}

		$existing = $this->queries->listSubscriptionsForContact(contactId: $contactId);
		$selected = array_flip(array_map('strval', $selectedListIds));

		// Every membership belongs to the same person, so any address on one
		// of them is the address a newly ticked list is stored under. Without
		// it a new membership cannot be written at all: `email` is required.
		$email = '';
		foreach ($existing as $subscription) {
			$candidate = (string)($subscription['email'] ?? '');
			if ($candidate !== '') {
				$email = $candidate;
				break;
			}
		}

		$unsubscribed = 0;
		foreach ($existing as $subscription) {
			$listId = (string)($subscription['listId'] ?? '');
			if (isset($selected[$listId]) === true) {
				continue;
			}

			$closed = $this->subscriptions->unsubscribeFromList(
				listId: $listId,
				contactId: $contactId,
				reason: 'preference-centre',
			);
			if ($closed === true) {
				$unsubscribed++;
			}
		}

		$confirmed = 0;
		foreach (array_keys($selected) as $listId) {
			$done = $this->subscriptions->confirmForContact(
				listId: (string)$listId,
				contactId: $contactId,
				email: $email,
			);
			if ($done === true) {
				$confirmed++;
			}
		}

		return ['status' => 'saved', 'confirmed' => $confirmed, 'unsubscribed' => $unsubscribed];
	}//end savePreferences()

	/**
	 * Resolve the contact a preferences token points at.
	 *
	 * @param string $token The signed preferences token.
	 *
	 * @return string|null The contact id, or null when unusable.
	 */
	private function contactIdFor(string $token): ?string {
		$payload = $this->tokens->verify(token: $token, purpose: ListTokenService::PURPOSE_PREFERENCES);
		if ($payload === null) {
			return null;
		}

		$contactId = (string)($payload['c'] ?? '');
		if ($contactId === '') {
			return null;
		}

		return $contactId;
	}//end contactIdFor()
}//end class
