<?php

/**
 * Pipelinq SubscriptionService.
 *
 * Owns what happens to a `subscription`: a public signup mints a pending
 * membership and mails a signed confirmation link, the link confirms it and
 * writes the consent ledger, and a first-party unsubscribe closes it again.
 * The soft opt-in import and the preference centre are the two other doors
 * into the same state machine.
 *
 * The `state` transitions themselves are declared on the schema as an
 * `x-openregister-lifecycle` and enforced by OpenRegister (ADR-031). What
 * lives here is everything the schema grammar cannot express: verifying an
 * HMAC signature and comparing a nonce against a stored digest, sending a
 * message to someone who has no Nextcloud account, and writing the second
 * object that records why the membership is lawful.
 *
 * Every OpenRegister call passes `_rbac: false` and `_multitenancy: false`,
 * because the endpoints that drive this service run with no session at all.
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

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\IL10N;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * SubscriptionService — subscribe, confirm, unsubscribe and preferences.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     One membership lifecycle end to
 *  end; splitting it would put the token check, the state change and the consent
 *  write in three files that may only ever be called in one order.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Guard-heavy but flat: every
 *  public method fails closed on its own preconditions before touching a row.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the token primitive, the
 *  list a membership belongs to, the consent ledger it writes and the mailer that
 *  carries the link; each is the sole collaborator for one step.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
 */
class SubscriptionService {
	/**
	 * A membership awaiting its confirmation link.
	 *
	 * @var string
	 */
	public const STATE_PENDING = 'pending';

	/**
	 * A membership that may receive a mailing.
	 *
	 * @var string
	 */
	public const STATE_CONFIRMED = 'confirmed';

	/**
	 * A membership the subscriber ended.
	 *
	 * @var string
	 */
	public const STATE_UNSUBSCRIBED = 'unsubscribed';

	/**
	 * A membership whose address stopped accepting mail.
	 *
	 * @var string
	 */
	public const STATE_BOUNCED = 'bounced';

	/**
	 * Default Subscription schema slug, matching the register fragment.
	 *
	 * @var string
	 */
	private const DEFAULT_SUBSCRIPTION_SCHEMA_SLUG = 'subscription';

	/**
	 * The channel a mailing list sends on. Lists are email-only for now;
	 * the SMS and WhatsApp channels have their own consent surface in
	 * `80-whatsapp-sms-channel.json`.
	 *
	 * @var string
	 */
	private const CHANNEL = 'email';

	/**
	 * The transitions the `subscription` schema declares, mirrored here so
	 * the service can refuse an illegal state change before it writes a
	 * consent record for one.
	 *
	 * OpenRegister's lifecycle listener is the authority: it validates the
	 * same graph on save and rejects anything this mirror lets through. The
	 * mirror exists because the consent ledger is written alongside the
	 * transition, and discovering the refusal after that write would leave
	 * a record for a membership that never changed.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const TRANSITIONS = [
		'confirm' => [self::STATE_PENDING],
		'unsubscribe' => [self::STATE_PENDING, self::STATE_CONFIRMED, self::STATE_BOUNCED],
		'resubscribe' => [self::STATE_UNSUBSCRIBED],
		'markBounced' => [self::STATE_PENDING, self::STATE_CONFIRMED],
	];

	/**
	 * The state each transition lands in.
	 *
	 * @var array<string, string>
	 */
	private const TRANSITION_TARGETS = [
		'confirm' => self::STATE_CONFIRMED,
		'unsubscribe' => self::STATE_UNSUBSCRIBED,
		'resubscribe' => self::STATE_PENDING,
		'markBounced' => self::STATE_BOUNCED,
	];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped, session-free object access.
	 * @param ListTokenService $tokens Signed-link minting and verification.
	 * @param MailingListService $lists The lists memberships belong to.
	 * @param ComplianceService $compliance The consent ledger.
	 * @param IMailer $mailer Nextcloud mailer.
	 * @param IL10N $l10n Localisation for the subscriber-facing mail.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	public function __construct(
		private ListObjectStore $store,
		private ListTokenService $tokens,
		private MailingListService $lists,
		private ComplianceService $compliance,
		private IMailer $mailer,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}//end __construct()

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
	): array {
		$list = $this->lists->getMailingListById(listId: $listId);
		if ($this->lists->acceptsPublicSignup(list: $list) === false) {
			return ['status' => 'not-found'];
		}

		$email = strtolower(trim($email));
		if ($this->mailer->validateMailAddress($email) === false) {
			return ['status' => 'invalid'];
		}

		if (trim($honeypot) !== '') {
			$this->logger->info(
				'SubscriptionService.subscribe: honeypot filled, discarding',
				['listId' => $listId, 'emailHash' => $this->tokens->hashAddress(address: $email)]
			);
			return ['status' => 'accepted'];
		}

		$existing = $this->findSubscription(listId: $listId, email: $email);
		if ($existing !== null && (string)($existing['state'] ?? '') === self::STATE_CONFIRMED) {
			// Already on the list. Say nothing about it and send nothing:
			// a confirmed subscriber who submits the form again should not
			// receive a link that could be forwarded to someone else.
			return ['status' => 'accepted'];
		}

		$this->startConfirmation(
			existing: $existing,
			list: (array)$list,
			listId: $listId,
			email: $email,
			contactId: $contactId,
			source: 'public-signup',
		);

		return ['status' => 'accepted'];
	}//end subscribe()

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
	public function confirm(string $token, string $ipAddress = ''): array {
		$payload = $this->tokens->verify(token: $token, purpose: ListTokenService::PURPOSE_CONFIRM);
		if ($payload === null) {
			return ['status' => 'invalid'];
		}

		$subscriptionId = (string)($payload['s'] ?? '');
		$nonce = (string)($payload['n'] ?? '');
		$subscription = $this->loadSubscription(id: $subscriptionId);
		if ($subscription === null || $nonce === '') {
			return ['status' => 'invalid'];
		}

		$storedDigest = (string)($subscription['confirmTokenHash'] ?? '');
		if ($storedDigest === '' || hash_equals($storedDigest, $this->tokens->digest(nonce: $nonce)) === false) {
			return ['status' => 'invalid'];
		}

		if ($this->canTransition(from: (string)($subscription['state'] ?? ''), action: 'confirm') === false) {
			return ['status' => 'invalid'];
		}

		$now = $this->nowIso();
		$subscription['state'] = self::STATE_CONFIRMED;
		$subscription['confirmedAt'] = $now;
		$subscription['confirmIpHash'] = $this->tokens->hashAddress(address: $ipAddress);
		$subscription['confirmTokenHash'] = '';
		$subscription['lawfulBasis'] = 'consent';

		$saved = $this->save(payload: $subscription, id: $subscriptionId);
		if ($saved === null) {
			return ['status' => 'invalid'];
		}

		$listId = (string)($subscription['listId'] ?? '');
		$this->compliance->recordListConsent(
			contactId: $this->contactKeyFor(subscription: $subscription),
			listId: $listId,
			channel: self::CHANNEL,
			lawfulBasis: 'consent',
			consentSource: 'double-opt-in',
			evidence: [
				'mechanism' => 'double-opt-in',
				'reference' => $subscriptionId,
				'objectionOffered' => true,
				'objectionOfferedAt' => (string)($subscription['createdAt'] ?? $now),
				'ipHash' => $this->tokens->hashAddress(address: $ipAddress),
				'recordedAt' => $now,
			],
		);

		$list = $this->lists->getMailingListById(listId: $listId);
		if ($list === null) {
			return ['status' => 'confirmed'];
		}

		return ['status' => 'confirmed', 'list' => $this->lists->publicProjection(list: $list)];
	}//end confirm()

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
	public function peekUnsubscribeToken(string $token): ?array {
		$subscription = $this->subscriptionForUnsubscribeToken(token: $token);
		if ($subscription === null) {
			return null;
		}

		$list = $this->lists->getMailingListById(listId: (string)($subscription['listId'] ?? ''));
		if ($list === null) {
			return null;
		}

		return [
			'list' => $this->lists->publicProjection(list: $list),
			'state' => (string)($subscription['state'] ?? ''),
		];
	}//end peekUnsubscribeToken()

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
	public function unsubscribeByToken(string $token, bool $global = false, string $reason = ''): array {
		$subscription = $this->subscriptionForUnsubscribeToken(token: $token);
		if ($subscription === null) {
			return ['status' => 'invalid', 'count' => 0];
		}

		$contactId = (string)($subscription['contactId'] ?? '');
		if ($global === true && $contactId !== '') {
			return ['status' => 'unsubscribed', 'count' => $this->globalUnsubscribe(contactId: $contactId, reason: $reason)];
		}

		$closed = $this->closeSubscription(subscription: $subscription, reason: $reason);
		if ($closed === false) {
			return ['status' => 'invalid', 'count' => 0];
		}

		return ['status' => 'unsubscribed', 'count' => 1];
	}//end unsubscribeByToken()

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
	public function unsubscribeFromList(string $listId, string $contactId, string $reason = ''): bool {
		foreach ($this->loadSubscriptions(filters: ['listId' => $listId, 'contactId' => $contactId]) as $subscription) {
			if ($this->closeSubscription(subscription: $subscription, reason: $reason) === true) {
				return true;
			}
		}

		return false;
	}//end unsubscribeFromList()

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
	public function globalUnsubscribe(string $contactId, string $reason = ''): int {
		if ($contactId === '') {
			return 0;
		}

		$closed = 0;
		foreach ($this->loadSubscriptions(filters: ['contactId' => $contactId]) as $subscription) {
			if ($this->closeSubscription(subscription: $subscription, reason: $reason) === true) {
				$closed++;
			}
		}

		return $closed;
	}//end globalUnsubscribe()

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
	public function importSoftOptIn(string $listId, string $contactId, string $email, array $evidence): array {
		$list = $this->lists->getMailingListById(listId: $listId);
		if ($list === null) {
			return ['status' => 'refused', 'error' => 'Not found'];
		}

		if ((string)($list['optInMode'] ?? '') !== MailingListService::OPT_IN_SOFT) {
			return [
				'status' => 'refused',
				'error' => 'This list uses double opt-in, so a subscriber has to confirm the link themselves',
			];
		}

		$email = strtolower(trim($email));
		if ($this->mailer->validateMailAddress($email) === false) {
			return ['status' => 'refused', 'error' => 'That is not a valid email address'];
		}

		if ((bool)($evidence['objectionOffered'] ?? false) === false) {
			return [
				'status' => 'refused',
				'error' => 'A soft opt-in needs a record that the customer was offered the chance to object',
			];
		}

		$now = $this->nowIso();
		$existing = $this->findSubscription(listId: $listId, email: $email);
		$record = ($existing ?? []);
		$record['listId'] = $listId;
		$record['contactId'] = $contactId;
		$record['email'] = $email;
		$record['state'] = self::STATE_CONFIRMED;
		$record['source'] = 'soft-opt-in-import';
		$record['lawfulBasis'] = 'soft-opt-in';
		$record['confirmedAt'] = $now;
		$record['confirmTokenHash'] = '';
		if (isset($record['createdAt']) === false) {
			$record['createdAt'] = $now;
		}

		$saved = $this->save(payload: $record, id: $this->idOf(payload: $existing));
		if ($saved === null) {
			return ['status' => 'refused', 'error' => 'The subscription could not be saved'];
		}

		$this->compliance->recordListConsent(
			contactId: $contactId,
			listId: $listId,
			channel: self::CHANNEL,
			lawfulBasis: 'soft-opt-in',
			consentSource: 'soft-opt-in-import',
			evidence: array_merge(
				['mechanism' => 'soft-opt-in-import', 'recordedAt' => $now],
				$evidence,
			),
		);

		return ['status' => 'imported'];
	}//end importSoftOptIn()

	/**
	 * Confirm one contact onto one list without a confirmation link.
	 *
	 * The caller has already proved the person's identity by another route
	 * that is at least as strong: today that is the preference centre, whose
	 * signed link only the holder of the mail can have. The membership is
	 * created when the contact did not hold one, which is what makes the
	 * preference centre able to ADD a list rather than only remove one.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param string $contactId Contact UUID or slug.
	 * @param string $email The address every membership of this contact uses.
	 * @param string $source How the confirmation was reached.
	 *
	 * @return bool True when a membership was confirmed or created.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
	 */
	public function confirmForContact(
		string $listId,
		string $contactId,
		string $email,
		string $source = 'preference-centre',
	): bool {
		$list = $this->lists->getMailingListById(listId: $listId);
		if ($list === null || (string)($list['status'] ?? 'active') !== 'active') {
			return false;
		}

		$subscription = null;
		foreach ($this->loadSubscriptions(filters: ['listId' => $listId, 'contactId' => $contactId]) as $row) {
			$subscription = $row;
			break;
		}

		if ($subscription === null && $email === '') {
			// Nothing to store the membership under. Refusing is the only
			// honest answer: a row with no address would show the person as
			// subscribed to a list that can never mail them.
			return false;
		}

		$now = $this->nowIso();
		$record = ($subscription ?? ['createdAt' => $now, 'email' => $email]);
		if ((string)($record['state'] ?? '') === self::STATE_CONFIRMED) {
			return false;
		}

		$record['listId'] = $listId;
		$record['contactId'] = $contactId;
		$record['state'] = self::STATE_CONFIRMED;
		$record['source'] = $source;
		$record['lawfulBasis'] = 'consent';
		$record['confirmedAt'] = $now;
		$record['confirmTokenHash'] = '';

		if ($this->save(payload: $record, id: $this->idOf(payload: $subscription)) === null) {
			return false;
		}

		$this->compliance->recordListConsent(
			contactId: $contactId,
			listId: $listId,
			channel: self::CHANNEL,
			lawfulBasis: 'consent',
			consentSource: $source,
			evidence: [
				'mechanism' => $source,
				'objectionOffered' => true,
				'objectionOfferedAt' => $now,
				'recordedAt' => $now,
			],
		);

		return true;
	}//end confirmForContact()










	/**
	 * Resolve the Subscription schema slug from app config.
	 *
	 * @return string Schema slug.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-self-service-subscribe-creates-a-pending-subscription
	 */
	public function getSubscriptionSchemaSlug(): string {
		return $this->store->schemaSlug(
			configKey: 'subscription_schema',
			default: self::DEFAULT_SUBSCRIPTION_SCHEMA_SLUG,
		);
	}//end getSubscriptionSchemaSlug()

	/**
	 * Whether the declared lifecycle allows an action from a state.
	 *
	 * @param string $from The current state.
	 * @param string $action The transition name.
	 *
	 * @return bool True when the transition is declared.
	 */
	private function canTransition(string $from, string $action): bool {
		$allowed = (self::TRANSITIONS[$action] ?? []);
		return in_array($from, $allowed, true);
	}//end canTransition()

	/**
	 * Create or refresh a pending membership and mail its link.
	 *
	 * A membership that is `unsubscribed` goes back to `pending` rather than
	 * straight to `confirmed`: a past opt-in is not revived, it is asked for
	 * again.
	 *
	 * @param array<string, mixed>|null $existing The membership, when one exists.
	 * @param array<string, mixed> $list The list being joined.
	 * @param string $listId MailingList UUID or slug.
	 * @param string $email The subscriber's address.
	 * @param string|null $contactId Known contact, when the caller has one.
	 * @param string $source How the membership started.
	 *
	 * @return void
	 */
	private function startConfirmation(
		?array $existing,
		array $list,
		string $listId,
		string $email,
		?string $contactId,
		string $source,
	): void {
		$nonce = $this->tokens->mintNonce();
		$now = $this->nowIso();

		$record = ($existing ?? []);
		$record['listId'] = $listId;
		$record['email'] = $email;
		$record['state'] = self::STATE_PENDING;
		$record['source'] = $source;
		$record['lawfulBasis'] = 'consent';
		$record['confirmTokenHash'] = $this->tokens->digest(nonce: $nonce);
		if ($contactId !== null && $contactId !== '') {
			$record['contactId'] = $contactId;
		}

		if (isset($record['createdAt']) === false) {
			$record['createdAt'] = $now;
		}

		$saved = $this->save(payload: $record, id: $this->idOf(payload: $existing));
		if ($saved === null) {
			$this->logger->warning(
				'SubscriptionService.startConfirmation: subscription could not be saved',
				['listId' => $listId, 'emailHash' => $this->tokens->hashAddress(address: $email)]
			);
			return;
		}

		$subscriptionId = $this->idOf(payload: $saved);
		if ($subscriptionId === '') {
			return;
		}

		$this->sendConfirmationMail(
			list: $list,
			email: $email,
			token: $this->tokens->signConfirmToken(subscriptionId: $subscriptionId, nonce: $nonce),
		);
	}//end startConfirmation()

	/**
	 * Send the confirmation mail for a pending membership.
	 *
	 * A failure is logged and swallowed: the subscription stays pending and
	 * the endpoint answers exactly as it would have, because answering
	 * differently would say whether the address exists.
	 *
	 * @param array<string, mixed> $list The list being joined.
	 * @param string $email The recipient.
	 * @param string $token The signed confirmation token.
	 *
	 * @return void
	 */
	private function sendConfirmationMail(array $list, string $email, string $token): void {
		$listName = (string)($list['name'] ?? '');
		$subject = $this->l10n->t('Confirm your subscription to %s', [$listName]);
		$template = "You asked to receive %1\$s.\n\n"
			. "Open this link to confirm. If you did not ask for this, "
			. "ignore this message and nothing happens.\n\n%2\$s\n\n%3\$s";
		$body = $this->l10n->t(
			$template,
			[$listName, $this->tokens->confirmUrl(token: $token), (string)($list['footerAddress'] ?? '')]
		);

		try {
			$message = $this->mailer->createMessage();
			$message->setTo([$email]);
			$message->setSubject($subject);
			$message->setPlainBody($body);

			$sender = trim((string)($list['senderEmail'] ?? ''));
			if ($sender !== '' && $this->mailer->validateMailAddress($sender) === true) {
				$message->setFrom([$sender => (string)($list['senderName'] ?? $sender)]);
			}

			$replyTo = trim((string)($list['replyTo'] ?? ''));
			if ($replyTo !== '' && $this->mailer->validateMailAddress($replyTo) === true) {
				$message->setReplyTo([$replyTo]);
			}

			$this->mailer->send($message);
		} catch (Throwable $e) {
			$this->logger->warning(
				'SubscriptionService.sendConfirmationMail: dispatch failed',
				['listName' => $listName, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end sendConfirmationMail()


	/**
	 * Close one membership and record the withdrawal.
	 *
	 * @param array<string, mixed> $subscription The membership to close.
	 * @param string $reason What the subscriber gave as the reason.
	 *
	 * @return bool True when the membership moved to unsubscribed.
	 */
	private function closeSubscription(array $subscription, string $reason): bool {
		$state = (string)($subscription['state'] ?? '');
		if ($state === self::STATE_UNSUBSCRIBED) {
			// Already closed. Idempotent by design: an unsubscribe link is
			// clicked twice more often than once.
			return true;
		}

		if ($this->canTransition(from: $state, action: 'unsubscribe') === false) {
			return false;
		}

		$subscription['state'] = self::TRANSITION_TARGETS['unsubscribe'];
		$subscription['unsubscribedAt'] = $this->nowIso();
		$subscription['confirmTokenHash'] = '';
		if ($reason !== '') {
			$subscription['reason'] = $reason;
		}

		if ($this->save(payload: $subscription, id: $this->idOf(payload: $subscription)) === null) {
			return false;
		}

		$this->compliance->recordConsentWithdrawal(
			contactId: $this->contactKeyFor(subscription: $subscription),
			channel: self::CHANNEL,
			reason: 'user-unsubscribed',
			sourceBlastId: null,
			listId: (string)($subscription['listId'] ?? ''),
		);

		return true;
	}//end closeSubscription()

	/**
	 * Resolve the subscription an unsubscribe token points at.
	 *
	 * @param string $token The signed unsubscribe token.
	 *
	 * @return array<string, mixed>|null The membership, or null.
	 */
	private function subscriptionForUnsubscribeToken(string $token): ?array {
		$payload = $this->tokens->verify(token: $token, purpose: ListTokenService::PURPOSE_UNSUBSCRIBE);
		if ($payload === null) {
			return null;
		}

		return $this->loadSubscription(id: (string)($payload['s'] ?? ''));
	}//end subscriptionForUnsubscribeToken()


	/**
	 * The key the consent ledger is written under for a membership.
	 *
	 * A public signup that has not been matched to a contact still needs a
	 * ledger entry, so the address stands in for the contact id. It is
	 * stable, it is what the person gave us, and it is what a later match
	 * will join on.
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
	 * Find one membership by list and address.
	 *
	 * @param string $listId MailingList UUID or slug.
	 * @param string $email The address.
	 *
	 * @return array<string, mixed>|null The membership, or null.
	 */
	private function findSubscription(string $listId, string $email): ?array {
		foreach ($this->loadSubscriptions(filters: ['listId' => $listId]) as $subscription) {
			if (strtolower((string)($subscription['email'] ?? '')) === $email) {
				return $subscription;
			}
		}

		return null;
	}//end findSubscription()

	/**
	 * Load one membership by UUID or slug.
	 *
	 * @param string $id Subscription UUID or slug.
	 *
	 * @return array<string, mixed>|null The membership, or null.
	 */
	private function loadSubscription(string $id): ?array {
		return $this->store->find(schemaSlug: $this->getSubscriptionSchemaSlug(), id: $id);
	}//end loadSubscription()

	/**
	 * Load memberships matching a filter set.
	 *
	 * The filters are re-applied in PHP after the query, because OpenRegister's
	 * filter DSL ignores an unknown key silently and an ignored key looks
	 * exactly like an empty result.
	 *
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> Plain payloads.
	 */
	private function loadSubscriptions(array $filters): array {
		return $this->store->findAll(schemaSlug: $this->getSubscriptionSchemaSlug(), filters: $filters);
	}//end loadSubscriptions()

	/**
	 * Persist a membership through ObjectService.
	 *
	 * @param array<string, mixed> $payload The payload to store.
	 * @param string|null $id Existing id when updating.
	 *
	 * @return array<string, mixed>|null Saved row, or null on failure.
	 */
	private function save(array $payload, ?string $id = null): ?array {
		return $this->store->save(
			schemaSlug: $this->getSubscriptionSchemaSlug(),
			payload: $payload,
			id: $id,
		);
	}//end save()



	/**
	 * Current UTC timestamp in the format the schemas declare.
	 *
	 * @return string ISO-8601 timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()


	/**
	 * Extract the canonical id from an entity payload.
	 *
	 * @param array<string, mixed>|null $payload Entity payload.
	 *
	 * @return string Identifier, or an empty string.
	 */
	private function idOf(?array $payload): string {
		return $this->store->idOf(payload: $payload);
	}//end idOf()
}//end class
