<?php

/**
 * Contract of SubscriptionService
 *
 * The public surface of SubscriptionService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of SubscriptionService.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubscriptionServiceInterface {

	/**
	 * Confirm a membership from its signed link.
	 *
	 * Both checks must pass: the signature proves the link came from this
	 * instance unedited, and the nonce digest proves it is the link minted
	 * for this subscription and has not been spent. On success the digest
	 * is cleared, so the link cannot be replayed.
	 *
	 * @param string $token The signed confirmation token.
	 * @param string $ipAddress Caller address, hashed as opt-in evidence.
	 *
	 * @return array{status: string, list?: array<string, mixed>} `confirmed`
	 *         with the public list projection, or `invalid`.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function confirm(string $token, string $ipAddress = ''): array;

	/**
	 * Close every membership one contact holds.
	 *
	 * @param string $contactId Contact UUID or slug.
	 * @param string $reason What the subscriber gave as the reason.
	 *
	 * @return int The number of memberships closed.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	public function globalUnsubscribe(string $contactId, string $reason = ''): int;

	/**
	 * Add an existing customer to a soft opt-in list.
	 *
	 * Refused unless the list declares `soft` and the evidence records that
	 * an objection was offered. The refusal is the point: a soft opt-in
	 * whose ground cannot be shown is not a lawful basis, and letting the
	 * row exist would make it look like one.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param string $contactId Contact UUID or slug.
	 * @param string $email The customer's address.
	 * @param array<string, mixed> $evidence Must carry `objectionOffered`
	 *                                       true, and may carry the wording
	 *                                       and the date it was offered.
	 *
	 * @return array{status: string, error?: string} `imported`, or `refused`
	 *         with the reason.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
	 */
	public function importSoftOptIn(string $listId, string $contactId, string $email, array $evidence): array;

	/**
	 * Read what an unsubscribe link points at, without changing anything.
	 *
	 * The GET page renders this. It returns the list name and nothing that
	 * identifies the person beyond what the holder of the link already has.
	 *
	 * @param string $token The signed unsubscribe token.
	 *
	 * @return array{list: array<string, mixed>, state: string}|null Null when
	 *         the token or the membership is unusable.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	public function peekUnsubscribeToken(string $token): ?array;

	/**
	 * Handle a public subscribe.
	 *
	 * Answers `accepted` whether the address was new, already pending or
	 * already confirmed, so the endpoint cannot be used to test whether an
	 * address is on a list. A filled honeypot is discarded and answered the
	 * same way. Only an unusable list or a malformed address answers
	 * differently, and neither of those reveals anything about a person.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param string $email The address that wants to subscribe.
	 * @param string $honeypot The decoy field; a non-empty value is a bot.
	 * @param string|null $contactId Known contact, when the caller has one.
	 *
	 * @return array{status: string} `accepted`, `not-found` or `invalid`.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	public function subscribe(
		string $listId,
		string $email,
		string $honeypot = '',
		?string $contactId = null,
	): array;

	/**
	 * Act on an unsubscribe link.
	 *
	 * With `$global` set, every membership the contact holds is closed, not
	 * only the one the link names. A link whose subscription carries no
	 * contact id can only ever close its own membership, because there is
	 * nothing else to join the other lists on.
	 *
	 * @param string $token The signed unsubscribe token.
	 * @param bool $global Whether to leave every list at once.
	 * @param string $reason What the subscriber gave as the reason.
	 *
	 * @return array{status: string, count: int} `unsubscribed` with the number
	 *         of memberships closed, or `invalid`.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $global selects the documented
	 *  one-click-everything mode the RFC 8058 header and the footer link both
	 *  offer; it is the method's contract, not a toggle to split.
	 */
	public function unsubscribeByToken(string $token, bool $global = false, string $reason = ''): array;

	/**
	 * Close one contact's membership of one list.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param string $contactId Contact UUID or slug.
	 * @param string $reason What the subscriber or the operator gave.
	 *
	 * @return bool True when a membership was closed.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
	 */
	public function unsubscribeFromList(string $listId, string $contactId, string $reason = ''): bool;
}//end interface
