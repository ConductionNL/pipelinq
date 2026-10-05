<?php

/**
 * Pipelinq ConsentService.
 *
 * Per-contact, per-channel messaging consent (WhatsApp / SMS) — the
 * compliance gate that ConsentService.canSend() places in front of
 * every outbound send. Records are append-only: each opt-in /
 * opt-out event is a new messagingConsentRecord row so the audit
 * trail survives erasure-of-state and the latest record wins.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ConsentService — opt-in / opt-out compliance for WhatsApp + SMS.
 *
 * Public entry points:
 * - canSend(contactId, channel) — gate evaluated before every
 *   provider call.
 * - recordOptIn(contactId, channel, source, evidence) — append an
 *   opted-in row.
 * - recordOptOut(contactId, channel, source, evidence) — append an
 *   opted-out row.
 * - deleteForContact(contactId) — GDPR erasure hook called by
 *   ClientManagementIntegration when a Contact is deleted.
 * - isOptOutKeyword(body) — pure, case-insensitive STOP / STOPALL /
 *   UITSCHRIJVEN detection.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Consent lifecycle (opt-in/opt-out/erasure/keyword detection) is one cohesive responsibility.
 */
class ConsentService {
	/**
	 * Default messagingConsentRecord schema slug.
	 */
	private const DEFAULT_SCHEMA_SLUG = 'messagingConsentRecord';

	/**
	 * Default pipelinq register slug.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Opt-out keywords (case-insensitive exact match on a trimmed body).
	 *
	 * @var array<int, string>
	 */
	private const OPT_OUT_KEYWORDS = ['stop', 'stopall', 'uitschrijven'];

	/**
	 * Opt-in keywords (case-insensitive exact match on a trimmed body).
	 *
	 * @var array<int, string>
	 */
	private const OPT_IN_KEYWORDS = ['ja', 'yes', 'start'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 * @param IntegriqConsentClient $integriq Asks and records through integriq after the cutover.
	 * @param ContactAddressLookup $addresses The contact's phone or email for integriq.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private IntegriqConsentClient $integriq,
		private ContactAddressLookup $addresses,
	) {
	}//end __construct()

	/**
	 * Whether the contact may receive a message on the given channel.
	 *
	 * Picks the most recent messagingConsentRecord for the pair and
	 * returns true unless its state is `opted-out`. An absent record
	 * is treated as `unknown` → allowed (matches GDPR
	 * legitimate-interest defaults; explicit opt-out is still
	 * required to block).
	 *
	 * After the cutover integriq decides, as `service`, or as `reply` when the
	 * send answers an inbound message (an opt-out does not stop a reply).
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 * @param string $address The number the message goes to; looked up when empty.
	 * @param string|null $inReplyTo The inbound message this send answers, verified by the caller.
	 *
	 * @return bool True if sending is allowed.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.1
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function canSend(string $contactId, string $channel, string $address = '', ?string $inReplyTo = null): bool {
		if ($contactId === '' || $channel === '') {
			return false;
		}

		if ($this->integriq->isCutover() === true) {
			$category = IntegriqConsentClient::CATEGORY_SERVICE;
			if ($inReplyTo !== null && $inReplyTo !== '') {
				$category = IntegriqConsentClient::CATEGORY_REPLY;
			}

			return $this->ask(contactId: $contactId, channel: $channel, category: $category, requiresConsent: false, address: $address, inReplyTo: $inReplyTo)['send'];
		}

		$latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
		if ($latest === null) {
			// No record on file → allowed; agents may opt the contact
			// in explicitly via the UI when GDPR consent is required.
			return true;
		}

		$state = (string)($latest['state'] ?? 'unknown');
		return ($state !== 'opted-out');
	}//end canSend()

	/**
	 * Whether a business-initiated message may be sent on the channel.
	 *
	 * Business-initiated messages (WhatsApp template sends outside the 24h
	 * session window, including SLA escalations) require an explicit
	 * `opted-in` record — Meta business-messaging policy. Unlike
	 * {@see canSend()}, an absent or `unknown` record does NOT pass: only a
	 * latest `opted-in` record allows the send.
	 *
	 * After the cutover integriq decides with `requiresConsent`.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 * @param string $address The number the message goes to; looked up when empty.
	 *
	 * @return bool True only when the latest record is `opted-in`.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
	 */
	public function canSendBusinessInitiated(string $contactId, string $channel, string $address = ''): bool {
		if ($contactId === '' || $channel === '') {
			return false;
		}

		if ($this->integriq->isCutover() === true) {
			return $this->ask(contactId: $contactId, channel: $channel, category: IntegriqConsentClient::CATEGORY_SERVICE, requiresConsent: true, address: $address)['send'];
		}

		$latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
		if ($latest === null) {
			return false;
		}

		return ((string)($latest['state'] ?? 'unknown')) === 'opted-in';
	}//end canSendBusinessInitiated()

	/**
	 * The latest consent state for one (contactId, channel) pair.
	 *
	 * Returns `opted-in` / `opted-out` when a record exists, otherwise
	 * `unknown`. Used by the send surface to display consent state (REQ-OM-005)
	 * without deciding whether a send is allowed — that stays with
	 * {@see canSend()} / {@see canSendBusinessInitiated()}.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 *
	 * After the cutover the state is derived from integriq's decision (Ruben,
	 * 2026-10-05): refused as `opted-out` reads `opted-out`, allowed while
	 * consent was required means a consent matched and reads `opted-in`,
	 * anything else reads `unknown`.
	 *
	 * @return string `opted-in` / `opted-out` / `unknown`.
	 *
	 * @spec openspec/specs/outbound-messaging/spec.md#requirement-req-om-005-consent-gating-and-recording
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-latest-state-is-derived-from-integriq-s-decision-req-cii-006
	 */
	public function latestState(string $contactId, string $channel): string {
		if ($contactId === '' || $channel === '') {
			return 'unknown';
		}

		if ($this->integriq->isCutover() === true) {
			$decision = $this->ask(contactId: $contactId, channel: $channel, category: IntegriqConsentClient::CATEGORY_SERVICE, requiresConsent: true);
			if ($decision['code'] === IntegriqConsentClient::CODE_OPTED_OUT) {
				return 'opted-out';
			}

			if ($decision['send'] === true && $decision['code'] === IntegriqConsentClient::CODE_ALLOWED) {
				return 'opted-in';
			}

			return 'unknown';
		}

		$latest = $this->loadLatestRecord(contactId: $contactId, channel: $channel);
		if ($latest === null) {
			return 'unknown';
		}

		$state = (string)($latest['state'] ?? 'unknown');
		if ($state === '') {
			return 'unknown';
		}

		return $state;
	}//end latestState()

	/**
	 * Append an `opted-in` consent record.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 * @param string $source Enum value (webform / chat-reply / ...).
	 * @param string $evidence Free-text audit-trail evidence.
	 * @param string $legalBasis GDPR legal basis (consent / legitimate-interest / ...).
	 * @param string $address The number the wish is for; looked up when empty.
	 *
	 * @return array<string, mixed>|null Saved row or null on failure.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.3
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function recordOptIn(
		string $contactId,
		string $channel,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
		string $address = '',
	): ?array {
		if ($this->integriq->isCutover() === true) {
			$recorded = $this->recordInIntegriq(
				contactId: $contactId,
				channel: $channel,
				state: 'opted-in',
				source: $source,
				evidence: $evidence,
				legalBasis: $legalBasis,
				address: $address,
			);
			if ($recorded !== null) {
				return $recorded;
			}
		}

		return $this->appendRecord(
			contactId: $contactId,
			channel: $channel,
			state: 'opted-in',
			source: $source,
			evidence: $evidence,
			legalBasis: $legalBasis,
		);
	}//end recordOptIn()

	/**
	 * Append an `opted-out` consent record.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 * @param string $source Enum value (keyword-stop / admin-override / ...).
	 * @param string $evidence Free-text audit-trail evidence.
	 * @param string $legalBasis GDPR legal basis (consent / legitimate-interest / ...).
	 * @param string $address The number the wish is for; looked up when empty.
	 *
	 * @return array<string, mixed>|null Saved row or null on failure.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.2
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function recordOptOut(
		string $contactId,
		string $channel,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
		string $address = '',
	): ?array {
		if ($this->integriq->isCutover() === true) {
			$recorded = $this->recordInIntegriq(
				contactId: $contactId,
				channel: $channel,
				state: 'opted-out',
				source: $source,
				evidence: $evidence,
				legalBasis: $legalBasis,
				address: $address,
			);
			if ($recorded !== null) {
				return $recorded;
			}
		}

		return $this->appendRecord(
			contactId: $contactId,
			channel: $channel,
			state: 'opted-out',
			source: $source,
			evidence: $evidence,
			legalBasis: $legalBasis,
		);
	}//end recordOptOut()

	/**
	 * Whether a message body is an exact opt-out keyword.
	 *
	 * @param string $body Inbound message body.
	 *
	 * @return bool True for STOP / STOPALL / UITSCHRIJVEN (case-insensitive).
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.2
	 */
	public function isOptOutKeyword(string $body): bool {
		$normalised = strtolower(trim($body));
		return in_array($normalised, self::OPT_OUT_KEYWORDS, true);
	}//end isOptOutKeyword()

	/**
	 * Whether a message body is an exact opt-in keyword.
	 *
	 * @param string $body Inbound message body.
	 *
	 * @return bool True for JA / YES / START (case-insensitive).
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.3
	 */
	public function isOptInKeyword(string $body): bool {
		$normalised = strtolower(trim($body));
		return in_array($normalised, self::OPT_IN_KEYWORDS, true);
	}//end isOptInKeyword()

	/**
	 * Delete every consent record for one contact (GDPR Art. 17).
	 *
	 * Called by ClientManagementIntegration when a contact deletion
	 * propagates. Returns the number of rows actually deleted.
	 *
	 * @param string $contactId Contact UUID.
	 *
	 * @return int Rows deleted.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#4.4
	 */
	public function deleteForContact(string $contactId): int {
		if ($contactId === '') {
			return 0;
		}

		$rows = $this->loadAllRecords(contactId: $contactId);
		if ($rows === []) {
			return 0;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return 0;
		}

		$register = $this->getRegisterSlug();
		$schema = $this->getSchemaSlug();
		$deleted = 0;

		foreach ($rows as $row) {
			$id = $this->extractId(payload: $row);
			if ($id === '') {
				continue;
			}

			try {
				if (method_exists($objectService, 'deleteObject') === true) {
					$objectService->deleteObject($id, $register, $schema);
					$deleted++;
				}
			} catch (Throwable $e) {
				$this->logger->warning(
					'ConsentService.deleteForContact: delete failed',
					['contactId' => $contactId, 'id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $deleted;
	}//end deleteForContact()

	/**
	 * Every record, for the migration and the replay.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function allRecords(): array {
		return $this->loadRecords(filters: []);
	}//end allRecords()

	/**
	 * The latest record per (contact, channel), as the migration maps them.
	 *
	 * @param array<int, array<string, mixed>> $rows Every record.
	 *
	 * @return array<int, array<string, mixed>> The latest per pair.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function latestPerPair(array $rows): array {
		$pairs = [];
		foreach ($rows as $row) {
			$key = (string)($row['contactId'] ?? '').'|'.(string)($row['channel'] ?? '');
			$pairs[$key][] = $row;
		}

		$latest = [];
		foreach ($pairs as $group) {
			$latest[] = $this->newestFirst(matching: $group)[0];
		}

		return $latest;
	}//end latestPerPair()

	/**
	 * The change request integriq gets for one record.
	 *
	 * @param array<string, mixed> $row     The messagingConsentRecord.
	 * @param string               $address The contact's number.
	 *
	 * @return array<string, mixed> The request.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function changeRequestFor(array $row, string $address): array {
		$state = (string)($row['state'] ?? '');
		$request = [
			'address' => $address,
			'state' => $state,
			'scope' => 'channel',
			'channel' => (string)($row['channel'] ?? ''),
			'contactRef' => (string)($row['contactId'] ?? ''),
			'source' => (string)($row['source'] ?? ''),
			'evidence' => ['text' => (string)($row['evidence'] ?? ''), 'recordedAt' => (string)($row['recordedAt'] ?? '')],
			'legacyRef' => self::legacyRef(row: $row, id: $this->extractId(payload: $row)),
		];
		if ($state === 'opted-in') {
			$request['lawfulBasis'] = (string)($row['legalBasis'] ?? 'consent');
		}

		return $request;
	}//end changeRequestFor()

	/**
	 * The id integriq knows a pipelinq record by, so a second run writes once.
	 *
	 * @param array<string, mixed> $row The record.
	 * @param string               $id  Its UUID.
	 *
	 * @return string The legacy ref.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public static function legacyRef(array $row, string $id): string {
		unset($row);
		return 'pipelinq:messagingConsentRecord:'.$id;
	}//end legacyRef()

	/**
	 * The UUID of a record.
	 *
	 * @param array<string, mixed> $row The record.
	 *
	 * @return string The UUID, or empty.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-s-consent-records-are-migrated-into-integriq-once-req-cii-001
	 */
	public function idOf(array $row): string {
		return $this->extractId(payload: $row);
	}//end idOf()

	/**
	 * Replay to integriq the fallback records written after the cutover.
	 *
	 * Oldest first, each with its legacy ref, so a record integriq already
	 * has writes nothing and a STOP written while integriq was away lands
	 * before a newer wish.
	 *
	 * @param string $contactId Only this contact's records; every contact when empty.
	 *
	 * @return array{replayed: int, failed: int} Counts.
	 *
	 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-writes-every-wish-to-integriq-req-cii-003
	 */
	public function replayPending(string $contactId = ''): array {
		$counts = ['replayed' => 0, 'failed' => 0];
		if ($this->integriq->isCutover() === false) {
			return $counts;
		}

		$filters = [];
		if ($contactId !== '') {
			$filters['contactId'] = $contactId;
		}

		$cutoverAt = (int)$this->appConfig->getValueString(Application::APP_ID, IntegriqConsentClient::CONFIG_CUTOVER_AT, '0');
		$pending = [];
		foreach ($this->loadRecords(filters: $filters) as $row) {
			$at = strtotime((string)($row['recordedAt'] ?? ''));
			if ($at !== false && $at >= $cutoverAt) {
				$pending[] = $row;
			}
		}

		foreach (array_reverse($this->newestFirst(matching: $pending)) as $row) {
			$address = $this->addresses->addressFor(contactId: (string)($row['contactId'] ?? ''), channel: (string)($row['channel'] ?? ''));
			$outcome = $this->integriq->record(request: $this->changeRequestFor(row: $row, address: $address));
			if ($outcome['recorded'] === true) {
				$counts['replayed']++;
				continue;
			}

			$counts['failed']++;
		}

		return $counts;
	}//end replayPending()

	/**
	 * Ask integriq about one contact on one channel.
	 *
	 * @param string      $contactId       Contact UUID.
	 * @param string      $channel         The channel.
	 * @param string      $category        The category.
	 * @param bool        $requiresConsent Whether consent is required.
	 * @param string      $address         The address; looked up when empty.
	 * @param string|null $inReplyTo       The inbound message, for a reply.
	 *
	 * @return array{send:bool,code:string,reason:string,unsubscribe:array<string,mixed>|null} The decision.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) requiresConsent is integriq's contract field.
	 */
	private function ask(
		string $contactId,
		string $channel,
		string $category,
		bool $requiresConsent,
		string $address = '',
		?string $inReplyTo = null,
	): array {
		if ($address === '') {
			$address = $this->addresses->addressFor(contactId: $contactId, channel: $channel);
		}

		return $this->integriq->decideOne(
			channel: $channel,
			category: $category,
			requiresConsent: $requiresConsent,
			address: $address,
			contactRef: $contactId,
			inReplyTo: $inReplyTo,
		);
	}//end ask()

	/**
	 * Hand one wish to integriq, after any fallback rows of the same contact.
	 *
	 * @param string $contactId  Contact UUID.
	 * @param string $channel    The channel.
	 * @param string $state      `opted-in` or `opted-out`.
	 * @param string $source     The trigger.
	 * @param string $evidence   The evidence text.
	 * @param string $legalBasis The basis, for an opt-in.
	 * @param string $address    The address; looked up when empty.
	 *
	 * @return array<string, mixed>|null What integriq stored, or null to keep the wish in pipelinq.
	 */
	private function recordInIntegriq(
		string $contactId,
		string $channel,
		string $state,
		string $source,
		string $evidence,
		string $legalBasis,
		string $address,
	): ?array {
		if ($contactId === '' || $channel === '') {
			return null;
		}

		if ($address === '') {
			$address = $this->addresses->addressFor(contactId: $contactId, channel: $channel);
		}

		$this->replayPending(contactId: $contactId);

		$request = [
			'address' => $address,
			'state' => $state,
			'scope' => 'channel',
			'channel' => $channel,
			'contactRef' => $contactId,
			'source' => $source,
			'evidence' => ['text' => $evidence],
		];
		if ($state === 'opted-in') {
			$request['lawfulBasis'] = $legalBasis;
		}

		$outcome = $this->integriq->record(request: $request);
		if ($outcome['recorded'] === false) {
			return null;
		}

		return [
			'contactId' => $contactId,
			'channel' => $channel,
			'state' => $state,
			'source' => $source,
			'store' => IntegriqConsentClient::STORE_INTEGRIQ,
			'recordId' => $outcome['recordId'],
		];
	}//end recordInIntegriq()

	/**
	 * Append a new consent record (immutable history).
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel `whatsapp` or `sms`.
	 * @param string $state `opted-in` / `opted-out` / `unknown`.
	 * @param string $source Enum value.
	 * @param string $evidence Audit evidence.
	 * @param string $legalBasis GDPR legal basis.
	 *
	 * @return array<string, mixed>|null Saved row.
	 */
	private function appendRecord(
		string $contactId,
		string $channel,
		string $state,
		string $source,
		string $evidence,
		string $legalBasis = 'consent',
	): ?array {
		if ($contactId === '' || $channel === '' || $state === '') {
			return null;
		}

		$basis = $legalBasis;
		if ($basis === '') {
			$basis = 'consent';
		}

		$payload = [
			'contactId' => $contactId,
			'channel' => $channel,
			'state' => $state,
			'source' => $source,
			'evidence' => $evidence,
			'recordedAt' => $this->nowIso(),
			'legalBasis' => $basis,
		];

		return $this->saveObject(payload: $payload);
	}//end appendRecord()

	/**
	 * Most recent consent record for one (contactId, channel) pair.
	 *
	 * @param string $contactId Contact UUID.
	 * @param string $channel Channel.
	 *
	 * @return array<string, mixed>|null Latest record or null.
	 */
	private function loadLatestRecord(string $contactId, string $channel): ?array {
		$rows = $this->loadAllRecords(contactId: $contactId);
		if ($rows === []) {
			return null;
		}

		$matching = [];
		foreach ($rows as $row) {
			if ((string)($row['channel'] ?? '') === $channel) {
				$matching[] = $row;
			}
		}

		if ($matching === []) {
			return null;
		}

		return $this->newestFirst(matching: $matching)[0];
	}//end loadLatestRecord()

	/**
	 * Records ordered newest first by `recordedAt`.
	 *
	 * @param array<int, array<string, mixed>> $matching The rows.
	 *
	 * @return array<int, array<string, mixed>> The rows, newest first.
	 */
	private function newestFirst(array $matching): array {
		// Compare INSTANTS, not strings. A strcmp cannot order a record written
		// at second resolution ('…T12:00:00Z') against one written at
		// microsecond resolution in the same second ('…T12:00:00.123456Z'):
		// 'Z' sorts after '.', so the older record would win. Parsing gives the
		// right answer for either format, and for a mix of the two.
		usort(
			$matching,
			static function (array $a, array $b): int {
				$rawA = (string)($a['recordedAt'] ?? '');
				$rawB = (string)($b['recordedAt'] ?? '');
				$left = null;
				$right = null;
				try {
					$left = new DateTimeImmutable($rawA);
					$right = new DateTimeImmutable($rawB);
				} catch (Throwable $e) {
					unset($e);
				}

				// Strtotime() would truncate to whole seconds and re-create the
				// tie this fix exists to remove, so compare with microseconds.
				if ($left !== null && $right !== null) {
					$cmp = ($right->format('U.u') <=> $left->format('U.u'));
					if ($cmp !== 0) {
						return $cmp;
					}
				}

				// Unparseable, or genuinely simultaneous to the microsecond:
				// the string at least makes the order deterministic.
				return strcmp($rawB, $rawA);
			}
		);

		return $matching;
	}//end newestFirst()

	/**
	 * Every record for one contact (used by erasure + latest-of).
	 *
	 * @param string $contactId Contact UUID.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	private function loadAllRecords(string $contactId): array {
		return $this->loadRecords(filters: ['contactId' => $contactId]);
	}//end loadAllRecords()

	/**
	 * Records matching the filters.
	 *
	 * @param array<string, string> $filters Extra filters.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	private function loadRecords(array $filters): array {
		$contactId = (string)($filters['contactId'] ?? '');
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => array_merge(
						$filters,
						[
							'register' => $this->getRegisterSlug(),
							'schema' => $this->getSchemaSlug(),
						]
					),
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConsentService.loadAllRecords: findAll failed',
				['contactId' => $contactId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end loadRecords()

	/**
	 * Persist a payload via OpenRegister.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return array<string, mixed>|null Saved row or null.
	 */
	private function saveObject(array $payload): ?array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $this->getRegisterSlug(),
				schema: $this->getSchemaSlug(),
				uuid: null,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConsentService.saveObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end saveObject()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object|null Service or null.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ConsentService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OR entity to a plain array.
	 *
	 * @param mixed $value Entity or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Extract a UUID / id / slug from a payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string Id or empty.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential key-lookup fallbacks; extraction adds no clarity.
	 */
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end extractId()

	/**
	 * Register slug (app-config overridable).
	 *
	 * @return string Slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Schema slug (app-config overridable).
	 *
	 * @return string Slug.
	 */
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(
			Application::APP_ID,
			'messagingConsentRecord_schema',
			''
		);

		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}//end getSchemaSlug()

	/**
	 * Current ISO 8601 UTC timestamp, to MICROSECOND resolution.
	 *
	 * 🔴 SECONDS ARE NOT ENOUGH TO ORDER CONSENT.
	 *
	 * This was gmdate('Y-m-d\TH:i:s\Z'), and loadLatestRecord() orders consent
	 * events by this value. Two changes in the SAME SECOND therefore had no
	 * defined order, and "the latest consent" — which is the whole question
	 * this service answers, and a legally meaningful one — came down to which
	 * row the store happened to return first. Opting out and back in inside one
	 * second could leave the opt-out winning.
	 *
	 * ConsentServiceTest carried a sleep() to work around it, with a comment
	 * saying so, and still failed roughly one run in five.
	 *
	 * Microseconds keep the value ISO 8601 and keep it string-sortable; the
	 * comparison in loadLatestRecord() parses rather than strcmp's, so records
	 * written at the old resolution still order correctly against new ones.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
	}//end nowIso()
}//end class
