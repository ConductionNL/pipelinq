<?php

/**
 * Pipelinq SubscriptionRecords.
 *
 * The membership records and consent evidence the subscription flows store.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Builds the membership record each subscription flow saves and the consent
 * evidence it records, stamped with the current UTC time. Holds no state.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */
final class SubscriptionRecords {
	/**
	 * A pending membership with a fresh confirmation digest.
	 *
	 * A membership that is `unsubscribed` goes back to `pending` rather than
	 * straight to `confirmed`: a past opt-in is not revived, it is asked for again.
	 *
	 * @param ListSignupRun $run    The membership being started.
	 * @param string        $digest The digest of the confirmation nonce.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	public function pending(ListSignupRun $run, string $digest): array {
		$now = $this->now();
		$record = ($run->existing ?? []);
		$record['listId'] = $run->listId;
		$record['email'] = $run->email;
		$record['state'] = SubscriptionService::STATE_PENDING;
		$record['source'] = $run->source;
		$record['lawfulBasis'] = 'consent';
		$record['confirmTokenHash'] = $digest;
		if ($run->contactId !== null && $run->contactId !== '') {
			$record['contactId'] = $run->contactId;
		}

		return $this->withCreatedAt(record: $record, now: $now);
	}//end pending()

	/**
	 * A membership confirmed from its link: the digest is cleared so the link cannot be replayed.
	 *
	 * @param array<string, mixed> $subscription The pending membership.
	 * @param string               $ipHash       The hashed caller address, as opt-in evidence.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function confirmed(array $subscription, string $ipHash): array {
		$subscription['state'] = SubscriptionService::STATE_CONFIRMED;
		$subscription['confirmedAt'] = $this->now();
		$subscription['confirmIpHash'] = $ipHash;
		$subscription['confirmTokenHash'] = '';
		$subscription['lawfulBasis'] = 'consent';
		return $subscription;
	}//end confirmed()

	/**
	 * The double opt-in evidence of a membership confirmed from its link.
	 *
	 * @param array<string, mixed> $confirmed The confirmed record.
	 * @param string               $reference The subscription id the link named.
	 *
	 * @return array<string, mixed> The evidence.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function doubleOptInEvidence(array $confirmed, string $reference): array {
		return [
			'mechanism' => 'double-opt-in',
			'reference' => $reference,
			'objectionOffered' => true,
			'objectionOfferedAt' => (string)($confirmed['createdAt'] ?? $confirmed['confirmedAt']),
			'ipHash' => $confirmed['confirmIpHash'],
			'recordedAt' => $confirmed['confirmedAt'],
		];
	}//end doubleOptInEvidence()

	/**
	 * A membership confirmed on the soft opt-in ground.
	 *
	 * @param ListSignupRun $run The membership being imported.
	 *
	 * @return array<string, mixed> The record to save.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
	 */
	public function softOptIn(ListSignupRun $run): array {
		$now = $this->now();
		$record = ($run->existing ?? []);
		$record['listId'] = $run->listId;
		$record['contactId'] = $run->contactId;
		$record['email'] = $run->email;
		$record['state'] = SubscriptionService::STATE_CONFIRMED;
		$record['source'] = 'soft-opt-in-import';
		$record['lawfulBasis'] = 'soft-opt-in';
		$record['confirmedAt'] = $now;
		$record['confirmTokenHash'] = '';
		return $this->withCreatedAt(record: $record, now: $now);
	}//end softOptIn()

	/**
	 * The record that confirms a contact's membership, or null when there is nothing to confirm.
	 *
	 * Without a membership and without an address there is nothing to store the
	 * membership under. Refusing is the only honest answer: a row with no address
	 * would show the person as subscribed to a list that can never mail them. An
	 * already confirmed membership is left as it is.
	 *
	 * @param ListSignupRun $run The list, contact, source and existing membership.
	 *
	 * @return array<string, mixed>|null The record to save.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function forContact(ListSignupRun $run): ?array {
		if ($run->existing === null && $run->email === '') {
			return null;
		}

		$record = ($run->existing ?? ['createdAt' => $this->now(), 'email' => $run->email]);
		if ((string)($record['state'] ?? '') === SubscriptionService::STATE_CONFIRMED) {
			return null;
		}

		return $this->confirmedForContact(record: $record, run: $run);
	}//end forContact()

	/**
	 * The consent evidence of a membership confirmed for a contact.
	 *
	 * @param array<string, mixed> $confirmed The confirmed record.
	 *
	 * @return array<string, mixed> The evidence.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function contactEvidence(array $confirmed): array {
		return [
			'mechanism' => $confirmed['source'],
			'objectionOffered' => true,
			'objectionOfferedAt' => $confirmed['confirmedAt'],
			'recordedAt' => $confirmed['confirmedAt'],
		];
	}//end contactEvidence()

	/**
	 * A membership confirmed for a contact whose identity was proved another way.
	 *
	 * @param array<string, mixed> $record The membership, or the new one's createdAt and address.
	 * @param ListSignupRun        $run    The list, contact and source.
	 *
	 * @return array<string, mixed> The record to save.
	 */
	private function confirmedForContact(array $record, ListSignupRun $run): array {
		$record['listId'] = $run->listId;
		$record['contactId'] = $run->contactId;
		$record['state'] = SubscriptionService::STATE_CONFIRMED;
		$record['source'] = $run->source;
		$record['lawfulBasis'] = 'consent';
		$record['confirmedAt'] = $this->now();
		$record['confirmTokenHash'] = '';
		return $record;
	}//end confirmedForContact()

	/**
	 * Current UTC timestamp in the format the schemas declare.
	 *
	 * @return string The ISO 8601 timestamp.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	public function now(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * The record with its createdAt set, when it has none.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $now    The current timestamp.
	 *
	 * @return array<string, mixed> The record.
	 */
	private function withCreatedAt(array $record, string $now): array {
		if (isset($record['createdAt']) === false) {
			$record['createdAt'] = $now;
		}

		return $record;
	}//end withCreatedAt()
}//end class
