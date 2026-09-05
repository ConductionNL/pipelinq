<?php

/**
 * Pipelinq ComplianceService.
 *
 * GDPR/CAN-SPAM gate that blocks marketing sends without lawful basis,
 * enforces unsubscribe + physical-address tokens on email templates, and
 * withdraws consent on unsubscribe / bounce events. Reads ConsentRecord
 * and CampaignTemplate (chain root 01) through OpenRegister's
 * ObjectService and works alongside SegmentService (member 02) which
 * supplies the recipient list. The downstream BlastService (member 04)
 * calls into this service to filter a segment to its compliant subset
 * before dispatching.
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
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ComplianceService — GDPR/CAN-SPAM gate for marketing blasts.
 *
 * Public surface (filled in by subsequent tasks):
 *
 * - `checkSegmentCompliance($segmentId, $channel)` — list compliant and
 *   missing-consent members for a Segment on the requested channel.
 * - `hasConsentForChannel($contactId, $channel)` — per-contact gate.
 * - `recordConsentWithdrawal($contactId, $channel, $reason, ?$sourceBlastId)` —
 *   transition a ConsentRecord to withdrawn and skip queued deliveries.
 * - `validateTemplate(array $templateData, $channel)` — enforce
 *   `{{unsubscribe_link}}` + physical-address token on email templates;
 *   SMS templates pass through.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Cohesive consent + template + queued-delivery gate; splitting fragments one policy.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Guard-heavy but flat GDPR/CAN-SPAM checks; each operation is independently unit-tested.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
 */
class ComplianceService {
	/**
	 * Default register slug — matches SegmentService and the chain-root
	 * register fragment.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default ConsentRecord schema slug — matches the register fragment.
	 */
	private const DEFAULT_CONSENT_RECORD_SCHEMA_SLUG = 'consentRecord';

	/**
	 * Default BlastDelivery schema slug — used by withdrawal to skip
	 * queued rows. Wired through to BlastService in member 04.
	 */
	private const DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG = 'blastDelivery';

	/**
	 * Delivery statuses that should be transitioned to
	 * "unsubscribed-before-send" on a withdrawal — the queue is still
	 * upstream of the provider, so the message has not left the system.
	 *
	 * @var array<int, string>
	 */
	private const QUEUED_DELIVERY_STATUSES = ['queued'];

	/**
	 * The withdrawal-side delivery status applied by
	 * `recordConsentWithdrawal()` to queued rows.
	 */
	private const STATUS_UNSUBSCRIBED_BEFORE_SEND = 'unsubscribed-before-send';

	/**
	 * The token every compliant email body must embed (the renderer
	 * substitutes the unsubscribe URL on send).
	 */
	private const UNSUBSCRIBE_TOKEN = '{{unsubscribe_link}}';

	/**
	 * Token alternatives the validator will accept for the CAN-SPAM
	 * physical-address requirement (any one of these in the body OR a
	 * non-empty footerOverride satisfies the rule).
	 *
	 * @var array<int, string>
	 */
	private const PHYSICAL_ADDRESS_TOKENS = [
		'{{physical_address}}',
		'{{sender_address}}',
		'{{company_address}}',
		'{{address_block}}',
	];

	/**
	 * Lawful-basis values that DO satisfy marketing consent gating.
	 *
	 * "imported" is intentionally NOT on this list — ADR-005 fail-safe
	 * rule: bulk-imported rows cannot stand in for documented consent.
	 * The withdrawal ledger may still carry the row, but a send is
	 * never permitted on the bare "imported" basis.
	 *
	 * @var array<int, string>
	 */
	private const LAWFUL_BASIS_ALLOWED = ['consent', 'legitimate-interest', 'contract', self::LAWFUL_BASIS_SOFT_OPT_IN];

	/**
	 * The soft opt-in basis. It permits a send, but only on a record whose
	 * evidence states that an objection was offered: a soft opt-in whose
	 * ground cannot be shown is not a lawful basis, and a record that claims
	 * it without the evidence would otherwise look like one.
	 *
	 * @var string
	 */
	public const LAWFUL_BASIS_SOFT_OPT_IN = 'soft-opt-in';

	/**
	 * A send that promotes something. Suppression applies.
	 *
	 * @var string
	 */
	public const INTENT_PROMOTIONAL = 'promotional';

	/**
	 * A send the contact needs whatever their invoices say.
	 *
	 * @var string
	 */
	public const INTENT_SERVICE = 'service';

	/**
	 * The gate refused because no lawful basis permits the send.
	 *
	 * @var string
	 */
	public const REASON_NO_CONSENT = 'no_consent';

	/**
	 * The gate refused because the customer is in dunning.
	 *
	 * @var string
	 */
	public const REASON_SUPPRESSED = 'suppressed_dunning';

	/**
	 * Lawful-basis values that are recorded on a ConsentRecord but do
	 * NOT permit a marketing send. The list is consulted explicitly so
	 * an audit-log line surfaces every blocked "imported" send.
	 *
	 * @var array<int, string>
	 */
	private const LAWFUL_BASIS_UNSATISFYING = ['imported'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container (lazy OR resolve).
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param SegmentService $segmentService Segment member projection.
	 * @param LoggerInterface $logger Logger.
	 * @param SegmentSignalService $signals Derived signals, for the dunning state.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private SegmentService $segmentService,
		private LoggerInterface $logger,
		private SegmentSignalService $signals,
	) {
	}//end __construct()

	/**
	 * Whether this send may go out at all, and why not when it may not.
	 *
	 * One gate, two reasons. Consent answers whether the tenant is allowed
	 * to mail this contact; suppression answers whether it should right
	 * now. They are asked here together rather than in two engines, because
	 * a second rule engine beside the consent gate is a second place to
	 * forget a rule, and forgetting one of these is a mailing to somebody
	 * who is being chased for money.
	 *
	 * @param string $contactId Contact UUID or slug.
	 * @param string $channel "email" or "sms".
	 * @param string $intent `promotional` or `service`.
	 * @param string|null $listId The list, when the send is list-scoped.
	 *
	 * @return array{allowed: bool, reason: string} `reason` is empty when allowed,
	 *         otherwise `no_consent` or `suppressed_dunning`.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-promotional-send-skips-a-customer-in-dunning
	 */
	public function permitsSend(string $contactId, string $channel, string $intent = self::INTENT_PROMOTIONAL, ?string $listId = null): array {
		$hasConsent = $this->hasConsentForChannel(contactId: $contactId, channel: $channel);
		if ($listId !== null && $listId !== '') {
			$hasConsent = $this->hasConsentForList(contactId: $contactId, listId: $listId, channel: $channel);
		}

		if ($hasConsent === false) {
			return ['allowed' => false, 'reason' => self::REASON_NO_CONSENT];
		}

		if ($this->isSuppressed(contactId: $contactId, intent: $intent) === true) {
			return ['allowed' => false, 'reason' => self::REASON_SUPPRESSED];
		}

		return ['allowed' => true, 'reason' => ''];
	}//end permitsSend()

	/**
	 * Whether a promotional send to this contact is suppressed.
	 *
	 * A service message is never suppressed. An invoice reminder, a
	 * delivery notice and a password reset all have to reach a late payer,
	 * and the whole point of suppression is that the promotion does not.
	 *
	 * @param string $contactId Contact UUID or slug.
	 * @param string $intent `promotional` or `service`.
	 *
	 * @return bool True when the send is skipped.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-promotional-send-skips-a-customer-in-dunning
	 */
	public function isSuppressed(string $contactId, string $intent = self::INTENT_PROMOTIONAL): bool {
		if ($intent !== self::INTENT_PROMOTIONAL) {
			return false;
		}

		if ($this->appConfig->getValueBool(Application::APP_ID, 'marketing.suppress_late_payers', true) === false) {
			return false;
		}

		$state = $this->signals->dunningStateForContact(contactId: $contactId);
		if ($state === null) {
			return false;
		}

		return in_array($state, $this->suppressingStates(), true);
	}//end isSuppressed()

	/**
	 * The dunning states a promotional send is suppressed on.
	 *
	 * @return array<int, string> The configured states, or the default pair.
	 */
	private function suppressingStates(): array {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, 'marketing.suppression_states', ''));
		if ($raw === '') {
			return SegmentSignalService::SUPPRESSING_DUNNING_STATES;
		}

		$states = [];
		foreach (explode(',', $raw) as $state) {
			$state = strtolower(trim($state));
			if ($state !== '') {
				$states[] = $state;
			}
		}

		if ($states === []) {
			return SegmentSignalService::SUPPRESSING_DUNNING_STATES;
		}

		return $states;
	}//end suppressingStates()

	/**
	 * Check every member of a Segment for a usable ConsentRecord on the
	 * requested channel.
	 *
	 * Returns a triple:
	 *
	 * - `compliant` (bool) — true when no member is missing consent.
	 * - `missingConsent` (string[]) — contactIds without a usable record.
	 * - `missingCount` (int) — convenience count of the above.
	 *
	 * Members without a contactId are conservatively treated as
	 * missing-consent (they cannot be matched to a ConsentRecord row, so
	 * the fail-safe rule applies). When SegmentService returns an empty
	 * recipient set (segment not found, OR unreachable, etc.) the result
	 * is `compliant: true, missingCount: 0` — there's nothing to send.
	 *
	 * @param string $segmentId Segment UUID or slug.
	 * @param string $channel "email" or "sms".
	 * @param string $intent `promotional` or `service`; a service message is never suppressed.
	 *
	 * @return array{compliant: bool, missingConsent: array<int, string>, missingCount: int, suppressed: array<int, string>, suppressedCount: int}
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-promotional-send-skips-a-customer-in-dunning
	 */
	public function checkSegmentCompliance(string $segmentId, string $channel, string $intent = self::INTENT_PROMOTIONAL): array {
		$channel = strtolower(trim($channel));
		$members = $this->segmentService->getMembersForBlast(segmentId: $segmentId);
		if ($members === []) {
			return [
				'compliant' => true,
				'missingConsent' => [],
				'missingCount' => 0,
				'suppressed' => [],
				'suppressedCount' => 0,
			];
		}

		$missing = [];
		$suppressed = [];
		foreach ($members as $member) {
			$contactId = (string)($member['contactId'] ?? '');
			if ($contactId === '') {
				// Members without a stable contactId cannot be matched to
				// a ConsentRecord — fail safe, treat as missing.
				$missing[] = '';
				continue;
			}

			if ($this->hasConsentForChannel(contactId: $contactId, channel: $channel) === false) {
				$missing[] = $contactId;
				continue;
			}

			if ($this->isSuppressed(contactId: $contactId, intent: $intent) === true) {
				$suppressed[] = $contactId;
			}
		}

		$missing = array_values(array_unique($missing));
		$suppressed = array_values(array_unique($suppressed));

		// `compliant` still means "every member has a lawful basis".
		// A suppressed contact is one the tenant MAY mail and chose not to,
		// which is a different thing from one it may not, and collapsing the
		// two would report a lawful campaign as non-compliant.
		return [
			'compliant' => ($missing === []),
			'missingConsent' => $missing,
			'missingCount' => count($missing),
			'suppressed' => $suppressed,
			'suppressedCount' => count($suppressed),
		];
	}//end checkSegmentCompliance()

	/**
	 * Run every pre-send compliance check for one Blast in a single call.
	 *
	 * Convenience entry point for the blast-send path used by
	 * BlastService (member 04) and the blast controller (member 06) —
	 * it runs `validateTemplate()` over the supplied template payload
	 * and `checkSegmentCompliance()` over the supplied segment + channel,
	 * returning a structured `{ valid, templateError, segmentCompliance }`
	 * triple. Centralising the template-then-segment sequence here keeps
	 * every caller honest about the order: never queue a Blast whose
	 * template did not pass `validateTemplate()` first.
	 *
	 * Empty segment + empty template short-circuit to `valid: true` so
	 * downstream "what would block this blast?" preview endpoints can
	 * call the method without first proving they have data.
	 *
	 * @param string $segmentId Segment UUID / slug.
	 * @param array<string, mixed> $template CampaignTemplate payload.
	 * @param string $channel "email" or "sms".
	 * @param string $intent `promotional` or `service`; a service message is never suppressed.
	 *
	 * @return array<string, mixed> Preflight triple — see
	 *                              `validateTemplate()` / `checkSegmentCompliance()`
	 *                              for the field shapes.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 */
	public function preflightBlast(string $segmentId, array $template, string $channel, string $intent = self::INTENT_PROMOTIONAL): array {
		$templateError = $this->validateTemplate(templateData: $template, channel: $channel);
		$segmentCheck = $this->checkSegmentCompliance(segmentId: $segmentId, channel: $channel, intent: $intent);

		$valid = ($templateError === null && $segmentCheck['compliant'] === true);

		return [
			'valid' => $valid,
			'templateError' => $templateError,
			'segmentCompliance' => $segmentCheck,
		];
	}//end preflightBlast()

	/**
	 * Return whether a Contact has a usable ConsentRecord on the channel.
	 *
	 * True iff a ConsentRecord exists for `(contactId, channel)` whose
	 * `lawfulBasis` is one of `consent`, `legitimate-interest`,
	 * `contract` AND whose `withdrawnAt` is empty. Any failure
	 * resolving the record returns false — the caller blocks the send.
	 *
	 * @param string $contactId Contact UUID / slug.
	 * @param string $channel "email" or "sms".
	 *
	 * @return bool True when the channel is gated open for this contact.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-blast-cannot-send-without-lawful-basis
	 */
	public function hasConsentForChannel(string $contactId, string $channel): bool {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $channel === '') {
			return false;
		}

		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: null);
		return $this->recordPermitsSend(record: $record, contactId: $contactId, channel: $channel);
	}//end hasConsentForChannel()

	/**
	 * Whether one mailing list may be sent to this contact.
	 *
	 * Reads the list-scoped ConsentRecord, the one a confirmed subscription
	 * or a soft opt-in import wrote. A channel-wide record does NOT open a
	 * list and a list record does NOT open the channel: the two scopes are
	 * separate gates in one ledger.
	 *
	 * @param string $contactId Contact UUID / slug, or the address a public
	 *                          signup was recorded under.
	 * @param string $listId MailingList UUID / slug.
	 * @param string $channel "email" or "sms".
	 *
	 * @return bool True when the list is gated open for this contact.
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
	 */
	public function hasConsentForList(string $contactId, string $listId, string $channel = 'email'): bool {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $listId === '' || $channel === '') {
			return false;
		}

		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: $listId);
		return $this->recordPermitsSend(record: $record, contactId: $contactId, channel: $channel);
	}//end hasConsentForList()

	/**
	 * Whether a ConsentRecord, as stored, permits a marketing send.
	 *
	 * Shared by the channel-wide and the list-scoped gate so the two can
	 * never drift: a basis that stops permitting a send in one of them stops
	 * in both, which is the property a consent ledger has to have.
	 *
	 * @param array<string, mixed>|null $record The record, or null.
	 * @param string $contactId Contact id, for the audit line.
	 * @param string $channel Channel, for the audit line.
	 *
	 * @return bool True when the record permits a send.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses over one record; extraction adds no clarity.
	 */
	private function recordPermitsSend(?array $record, string $contactId, string $channel): bool {
		if ($record === null) {
			return false;
		}

		$lawfulBasis = strtolower(trim((string)($record['lawfulBasis'] ?? '')));
		if ($lawfulBasis === '') {
			return false;
		}

		if (in_array($lawfulBasis, self::LAWFUL_BASIS_UNSATISFYING, true) === true) {
			// ADR-005 fail-safe: "imported" rows are recorded for
			// GDPR audit purposes but cannot themselves authorise a
			// marketing dispatch. Log the block so the operator sees
			// why the contact was excluded.
			$this->logger->info(
				'ComplianceService.recordPermitsSend: blocked — lawfulBasis "imported" does not permit marketing sends',
				['contactId' => $contactId, 'channel' => $channel]
			);
			return false;
		}

		if (in_array($lawfulBasis, self::LAWFUL_BASIS_ALLOWED, true) === false) {
			return false;
		}

		if ($lawfulBasis === self::LAWFUL_BASIS_SOFT_OPT_IN && $this->objectionWasOffered(record: $record) === false) {
			// A soft opt-in stands on the objection having been offered.
			// Without that recorded there is nothing to show a regulator,
			// so the basis fails its own check rather than being trusted.
			$this->logger->info(
				'ComplianceService.recordPermitsSend: blocked — soft opt-in needs the objection recorded in evidence',
				['contactId' => $contactId, 'channel' => $channel]
			);
			return false;
		}

		$withdrawnAt = ($record['withdrawnAt'] ?? null);
		if (is_string($withdrawnAt) === true && trim($withdrawnAt) !== '') {
			return false;
		}

		return true;
	}//end recordPermitsSend()

	/**
	 * Whether a record's evidence states that an objection was offered.
	 *
	 * @param array<string, mixed> $record The ConsentRecord payload.
	 *
	 * @return bool True when the evidence records the offer.
	 */
	private function objectionWasOffered(array $record): bool {
		$evidence = ($record['evidence'] ?? null);
		if (is_array($evidence) === false) {
			return false;
		}

		return (bool)($evidence['objectionOffered'] ?? false);
	}//end objectionWasOffered()

	/**
	 * Record consent for one contact on one mailing list.
	 *
	 * Written by SubscriptionService when a confirmation link verifies, when
	 * a soft opt-in import runs, and when the preference centre is saved.
	 * An existing record for the same (contact, channel, list) is reopened
	 * rather than duplicated, so a person who leaves and comes back has one
	 * row with a readable history instead of two that disagree.
	 *
	 * @param string $contactId Contact UUID / slug, or the address a public
	 *                          signup was recorded under.
	 * @param string $listId MailingList UUID / slug.
	 * @param string $channel "email" or "sms".
	 * @param string $lawfulBasis GDPR Art. 6 basis.
	 * @param string $consentSource How the ground was established.
	 * @param array<string, mixed> $evidence What was shown and when.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-confirmation-token-is-verified-before-a-subscription-is-confirmed
	 */
	public function recordListConsent(
		string $contactId,
		string $listId,
		string $channel,
		string $lawfulBasis,
		string $consentSource,
		array $evidence,
	): void {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $listId === '' || $channel === '') {
			return;
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');
		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: $listId);
		$payload = ($record ?? ['softBounceCount' => 0]);
		$payload['contactId'] = $contactId;
		$payload['channel'] = $channel;
		$payload['listId'] = $listId;
		$payload['lawfulBasis'] = $lawfulBasis;
		$payload['consentSource'] = $consentSource;
		$payload['consentedAt'] = $now;
		$payload['evidence'] = $evidence;

		// Reopening: the keys are REMOVED, not blanked. `withdrawnAt` is a
		// date-time and `withdrawnReason` an enum, and an empty string is a
		// valid value for neither, so blanking them fails schema validation
		// and the write is lost.
		unset($payload['withdrawnAt'], $payload['withdrawnReason']);

		if ($record === null) {
			$this->persistConsentObject(payload: $payload, id: null);
			return;
		}

		$this->persistConsentObject(payload: $payload, id: $this->extractObjectId(payload: $record));
	}//end recordListConsent()

	/**
	 * Validate a CampaignTemplate payload against the channel's rules.
	 *
	 * For email templates the body MUST embed `{{unsubscribe_link}}`
	 * (token literally present in `bodyHtml` or `bodyText`) AND a
	 * physical-address indicator — either one of the recognised
	 * placeholder tokens (see `PHYSICAL_ADDRESS_TOKENS`) or a non-empty
	 * `footerOverride` (the operator is supplying a literal address
	 * block in place of the templated one).
	 *
	 * Returns `null` on success or a human-readable error string on
	 * failure. Callers (controller / save path) surface the error as a
	 * field-level validation error and refuse to persist.
	 *
	 * SMS templates have no footer requirement (carriers strip
	 * footers; the CAN-SPAM rule is email-specific) — this method
	 * returns null for any non-email channel.
	 *
	 * @param array<string, mixed> $templateData CampaignTemplate payload.
	 * @param string $channel "email" or "sms".
	 *
	 * @return string|null Error message or null when valid.
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-unsubscribe-footer-enforced-on-email-templates
	 */
	public function validateTemplate(array $templateData, string $channel): ?string {
		$channel = strtolower(trim($channel));
		if ($channel !== 'email') {
			return null;
		}

		$bodyHtml = (string)($templateData['bodyHtml'] ?? '');
		$bodyText = (string)($templateData['bodyText'] ?? '');
		$footerOverride = (string)($templateData['footerOverride'] ?? '');

		$haystack = $bodyHtml . "\n" . $bodyText . "\n" . $footerOverride;

		if (str_contains($haystack, self::UNSUBSCRIBE_TOKEN) === false) {
			return sprintf(
				'Email templates must embed the %s token (GDPR Art. 7(3) withdrawal).',
				self::UNSUBSCRIBE_TOKEN
			);
		}

		$hasAddress = (trim($footerOverride) !== '');
		if ($hasAddress === false) {
			foreach (self::PHYSICAL_ADDRESS_TOKENS as $token) {
				if (str_contains($haystack, $token) === true) {
					$hasAddress = true;
					break;
				}
			}
		}

		if ($hasAddress === false) {
			return 'Email templates must include a physical-address block '
				. '(footerOverride or one of {{physical_address}} / '
				. '{{sender_address}} / {{company_address}} / '
				. '{{address_block}}) per CAN-SPAM § 7704(a)(5).';
		}

		return null;
	}//end validateTemplate()

	/**
	 * List CampaignTemplates with pagination envelope.
	 *
	 * @param int $page 1-based page number (clamped to >= 1).
	 * @param int $limit Page size (clamped 1..100).
	 *
	 * @return array{data: array<int, array<string, mixed>>, pagination: array{page: int, limit: int, total: int, pages: int}}
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 */
	public function listTemplates(int $page, int $limit): array {
		$page = max(1, $page);
		$limit = min(100, max(1, $limit));
		$all = $this->loadTemplatesRaw();
		$total = count($all);
		$slice = array_slice($all, (($page - 1) * $limit), $limit);
		return [
			'data' => $slice,
			'pagination' => [
				'page' => $page,
				'limit' => $limit,
				'total' => $total,
				'pages' => $this->computePages(total: $total, limit: $limit),
			],
		];
	}//end listTemplates()

	/**
	 * Compute the page-count from a total + page-size pair.
	 *
	 * Centralised so the inline ternary stays out of the envelope
	 * builders (matches the team's "no inline IF" coding style).
	 *
	 * @param int $total Total row count.
	 * @param int $limit Page size.
	 *
	 * @return int Page count (0 when total is 0).
	 */
	private function computePages(int $total, int $limit): int {
		if ($total <= 0 || $limit <= 0) {
			return 0;
		}

		return (int)ceil($total / $limit);
	}//end computePages()

	/**
	 * Fetch one CampaignTemplate by id.
	 *
	 * @param string $templateId Template UUID or slug.
	 *
	 * @return array<string, mixed>|null Template payload or null.
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 */
	public function getTemplateById(string $templateId): ?array {
		if ($templateId === '') {
			return null;
		}

		$register = $this->getRegisterSlug();
		$schema = $this->getCampaignTemplateSchemaSlug();
		$objectService = $this->getObjectService();
		if ($register === '' || $schema === '' || $objectService === null) {
			return null;
		}

		try {
			$entity = $objectService->find(id: $templateId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'ComplianceService.getTemplateById: not found',
				['templateId' => $templateId, 'exception' => $e->getMessage()]
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		return $this->toArray(value: $entity);
	}//end getTemplateById()

	/**
	 * Create a CampaignTemplate after compliance validation.
	 *
	 * Calls `validateTemplate()` for the requested channel before
	 * persisting. An invalid template is rejected with a human-readable
	 * error string; the row is never written. `createdBy` is stamped
	 * from the authenticated user id (ADR-005).
	 *
	 * @param array<string, mixed> $payload Inbound template payload.
	 * @param string $createdByUid Authenticated user id.
	 *
	 * @return array{template?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 */
	public function createTemplate(array $payload, string $createdByUid): array {
		$name = (string)($payload['name'] ?? '');
		if (trim($name) === '') {
			return ['error' => 'Invalid name'];
		}

		$channel = strtolower((string)($payload['channel'] ?? ''));
		if (in_array($channel, ['email', 'sms'], true) === false) {
			return ['error' => 'Invalid channel'];
		}

		$validationError = $this->validateTemplate(templateData: $payload, channel: $channel);
		if ($validationError !== null) {
			return ['error' => $validationError];
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');
		$object = [
			'name' => $name,
			'channel' => $channel,
			'subject' => (string)($payload['subject'] ?? ''),
			'bodyHtml' => (string)($payload['bodyHtml'] ?? ''),
			'bodyText' => (string)($payload['bodyText'] ?? ''),
			'senderName' => (string)($payload['senderName'] ?? ''),
			'senderEmail' => (string)($payload['senderEmail'] ?? ''),
			'footerOverride' => (string)($payload['footerOverride'] ?? ''),
			'articleIds' => $this->normaliseArticleIds(value: ($payload['articleIds'] ?? [])),
			'createdBy' => $createdByUid,
			'createdAt' => $now,
			'updatedAt' => $now,
		];

		$saved = $this->saveTemplateObject(payload: $object);
		if ($saved === null) {
			return ['error' => 'Could not create template'];
		}

		return ['template' => $saved];
	}//end createTemplate()

	/**
	 * Patch an existing CampaignTemplate with compliance re-validation.
	 *
	 * Any change to body / channel / footer triggers a fresh
	 * `validateTemplate()` call so an operator cannot edit a template
	 * into a non-compliant state. `createdBy` / `createdAt` are
	 * preserved from the existing row; `updatedAt` is bumped.
	 *
	 * @param string $templateId Template UUID or slug.
	 * @param array<string, mixed> $patch Patch payload.
	 *
	 * @return array{template?: array<string, mixed>, error?: string}
	 *
	 * @spec openspec/changes/marketing-segmentation-and-blast-06-rest-controllers/tasks.md#templatecontroller-task-2.8-of-giant
	 */
	public function patchTemplate(string $templateId, array $patch): array {
		$existing = $this->getTemplateById(templateId: $templateId);
		if ($existing === null) {
			return ['error' => 'Template not found'];
		}

		$editable = ['name', 'subject', 'bodyHtml', 'bodyText', 'senderName', 'senderEmail', 'footerOverride'];
		$payload = $existing;
		foreach ($editable as $field) {
			if (array_key_exists($field, $patch) === true && is_string($patch[$field]) === true) {
				$payload[$field] = $patch[$field];
			}
		}

		if (array_key_exists('articleIds', $patch) === true) {
			$payload['articleIds'] = $this->normaliseArticleIds(value: $patch['articleIds']);
		}

		$channel = strtolower((string)($existing['channel'] ?? 'email'));
		$error = $this->validateTemplate(templateData: $payload, channel: $channel);
		if ($error !== null) {
			return ['error' => $error];
		}

		$payload['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
		$saved = $this->saveTemplateObject(payload: $payload, id: $this->extractTemplateId(payload: $existing));
		if ($saved === null) {
			return ['error' => 'Could not update template'];
		}

		return ['template' => $saved];
	}//end patchTemplate()

	/**
	 * Normalise the embedded-article list to plain, non-empty strings.
	 *
	 * The order is the marketer's and is kept: it is the order the
	 * `{{articles}}` block renders in. Duplicates are dropped, because the
	 * same article twice in one newsletter is a mistake nobody meant.
	 *
	 * @param mixed $value Whatever arrived in the request body.
	 *
	 * @return array<int, string> Article ids in the order they were given.
	 *
	 * @spec openspec/changes/marketing-article-hub/specs/marketing-blast/spec.md#requirement-a-campaign-template-may-embed-articles
	 */
	private function normaliseArticleIds(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $id) {
			if (is_scalar($id) === false) {
				continue;
			}

			$id = trim((string)$id);
			if ($id !== '' && in_array($id, $out, true) === false) {
				$out[] = $id;
			}
		}

		return $out;
	}//end normaliseArticleIds()

	/**
	 * Load every CampaignTemplate via ObjectService.
	 *
	 * @return array<int, array<string, mixed>> Plain payloads.
	 */
	private function loadTemplatesRaw(): array {
		$register = $this->getRegisterSlug();
		$schema = $this->getCampaignTemplateSchemaSlug();
		$objectService = $this->getObjectService();
		if ($register === '' || $schema === '' || $objectService === null) {
			return [];
		}

		try {
			// OpenRegister's ObjectService::findAll() takes a single $config array;
			// register/schema travel INSIDE $config['filters'] (see prepareFindAllConfig()).
			// The old findAll(register:, schema:, filters:) named-argument form no longer
			// exists and threw "Unknown named parameter $register" at runtime.
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.loadTemplatesRaw: findAll failed',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$out = [];
		foreach (($rows ?? []) as $row) {
			$out[] = $this->toArray(value: $row);
		}

		return $out;
	}//end loadTemplatesRaw()

	/**
	 * Persist a CampaignTemplate via ObjectService.
	 *
	 * @param array<string, mixed> $payload Template payload.
	 * @param string|null $id Existing id when patching.
	 *
	 * @return array<string, mixed>|null Saved row or null on failure.
	 */
	private function saveTemplateObject(array $payload, ?string $id = null): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getCampaignTemplateSchemaSlug();
		$objectService = $this->getObjectService();
		if ($register === '' || $schema === '' || $objectService === null) {
			return null;
		}

		try {
			$saved = $objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.saveTemplateObject: save failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		return $this->toArray(value: $saved);
	}//end saveTemplateObject()

	/**
	 * Extract a template's id from a payload (uuid > id > slug).
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string Id or empty string.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered fallback lookup (uuid > id > slug); extraction adds no clarity.
	 */
	private function extractTemplateId(array $payload): string {
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
	}//end extractTemplateId()

	/**
	 * Resolve the CampaignTemplate schema slug from app config.
	 *
	 * @return string Schema slug.
	 */
	private function getCampaignTemplateSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'campaignTemplate_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return 'campaignTemplate';
	}//end getCampaignTemplateSchemaSlug()

	/**
	 * Withdraw consent for a (contact, channel) pair and skip any
	 * queued BlastDelivery rows for that contact.
	 *
	 * Sets `withdrawnAt` to the current UTC ISO-8601 timestamp and
	 * `withdrawnReason` to the supplied reason. Queued BlastDelivery
	 * rows for the contact are transitioned to
	 * `"unsubscribed-before-send"` so they are skipped before the
	 * provider receives them. Member 04 wires the deeper BlastService
	 * counter roll-up; until then this method updates the rows directly
	 * via ObjectService so the audit trail is preserved on the
	 * delivery rows themselves.
	 *
	 * Idempotent: a record that is already withdrawn is left unchanged.
	 * A missing ConsentRecord is created so the audit ledger always
	 * reflects the withdrawal event — this matches GDPR Art. 7(3) which
	 * grants a withdrawal even when consent was never formally captured.
	 *
	 * @param string $contactId Contact UUID / slug.
	 * @param string $channel "email" or "sms".
	 * @param string $reason Withdrawal reason
	 *                       ("user-unsubscribed", "bounce-hard",
	 *                       "bounce-soft-x5", "admin-removed",
	 *                       "complaint").
	 * @param string|null $sourceBlastId Blast UUID that triggered the
	 *                                   withdrawal (for audit context).
	 * @param string|null $listId MailingList id when the withdrawal ends one
	 *                            list membership; null withdraws the
	 *                            channel-wide record.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/marketing-compliance/spec.md#requirement-consent-withdrawal-propagates
	 */
	public function recordConsentWithdrawal(
		string $contactId,
		string $channel,
		string $reason,
		?string $sourceBlastId = null,
		?string $listId = null,
	): void {
		$channel = strtolower(trim($channel));
		if ($contactId === '' || $channel === '' || $reason === '') {
			return;
		}

		$now = gmdate('Y-m-d\TH:i:s\Z');

		$record = $this->findConsentRecord(contactId: $contactId, channel: $channel, listId: $listId);
		if ($record === null) {
			$this->persistWithdrawalLedger(
				contactId: $contactId,
				channel: $channel,
				reason: $reason,
				now: $now,
				listId: $listId,
			);
		}

		if ($record !== null) {
			$existingWithdrawnAt = $record['withdrawnAt'] ?? null;
			$alreadyWithdrawn = (is_string($existingWithdrawnAt) === true && trim($existingWithdrawnAt) !== '');
			if ($alreadyWithdrawn === true) {
				// Already withdrawn — keep first-withdrawal timestamp.
				$this->logger->info(
					'ComplianceService.recordConsentWithdrawal: already withdrawn',
					[
						'contactId' => $contactId,
						'channel' => $channel,
						'listId' => $listId,
						'sourceBlastId' => $sourceBlastId,
					]
				);
			}

			if ($alreadyWithdrawn === false) {
				$record['withdrawnAt'] = $now;
				$record['withdrawnReason'] = $reason;
				$this->persistConsentObject(payload: $record, id: $this->extractObjectId(payload: $record));
			}
		}//end if

		$this->transitionQueuedDeliveries(contactId: $contactId, sourceBlastId: $sourceBlastId);

		$this->logger->info(
			'ComplianceService.recordConsentWithdrawal: withdrawal recorded',
			[
				'contactId' => $contactId,
				'channel' => $channel,
				'listId' => $listId,
				'reason' => $reason,
				'sourceBlastId' => $sourceBlastId,
			]
		);
	}//end recordConsentWithdrawal()

	/**
	 * Persist a ConsentRecord, creating it when no id is supplied.
	 *
	 * One saver for the create and the update path, so the register/schema
	 * resolution and the failure logging cannot drift apart between them.
	 *
	 * @param array<string, mixed> $payload Record payload.
	 * @param string|null $id Existing record id, or null to create.
	 *
	 * @return void
	 */
	private function persistConsentObject(array $payload, ?string $id): void {
		$register = $this->getRegisterSlug();
		$schema = $this->getConsentRecordSchemaSlug();
		if ($register === '' || $schema === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		// Update and create stay two different calls, as they were before
		// mailing lists existed. `saveObject(uuid:)` would serve both, but
		// swapping the update path onto it changes which OpenRegister code
		// path a withdrawal takes, and a consent withdrawal is not the place
		// to find out what else that changed.
		try {
			if ($id !== null && $id !== '') {
				$objectService->updateObject(
					id: $id,
					object: $payload,
					register: $register,
					schema: $schema,
				);
				return;
			}

			$objectService->saveObject(
				object: $payload,
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.persistConsentObject: write failed',
				[
					'id' => $id,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end persistConsentObject()

	/**
	 * Write a synthetic ConsentRecord at withdrawal time when none existed.
	 *
	 * Preserves the audit ledger for GDPR Art. 7(3), which grants a
	 * withdrawal even where consent was never formally captured.
	 *
	 * @param string $contactId Contact UUID / slug.
	 * @param string $channel "email" or "sms".
	 * @param string $reason Withdrawal reason.
	 * @param string $now UTC timestamp string.
	 * @param string|null $listId MailingList id when the withdrawal is
	 *                            scoped to one list.
	 *
	 * @return void
	 */
	private function persistWithdrawalLedger(
		string $contactId,
		string $channel,
		string $reason,
		string $now,
		?string $listId = null,
	): void {
		$payload = [
			'contactId' => $contactId,
			'channel' => $channel,
			'lawfulBasis' => 'consent',
			'consentSource' => 'auto-withdrawal-ledger',
			'consentedAt' => $now,
			'withdrawnAt' => $now,
			'withdrawnReason' => $reason,
			'softBounceCount' => 0,
		];

		if ($listId !== null && $listId !== '') {
			$payload['listId'] = $listId;
		}

		$this->persistConsentObject(payload: $payload, id: null);
	}//end persistWithdrawalLedger()

	/**
	 * Transition queued BlastDelivery rows for the contact to
	 * `unsubscribed-before-send` so they are skipped on dispatch.
	 *
	 * Member 04 wires this through BlastService::transitionQueuedDeliveries
	 * for centralised counter roll-up. Until that lands the rows are
	 * updated directly so the audit trail and skip behaviour are still
	 * correct end-to-end.
	 *
	 * @param string $contactId Contact UUID / slug.
	 * @param string|null $sourceBlastId Blast UUID for audit context.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential guard clauses over queued rows; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential guard clauses over queued rows; extraction adds no clarity.
	 */
	private function transitionQueuedDeliveries(string $contactId, ?string $sourceBlastId): void {
		$register = $this->getRegisterSlug();
		$schema = $this->getBlastDeliverySchemaSlug();
		if ($register === '' || $schema === '') {
			return;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->info(
				'ComplianceService.transitionQueuedDeliveries: findAll failed',
				[
					'contactId' => $contactId,
					'exception' => $e->getMessage(),
				]
			);
			return;
		}

		foreach (($rows ?? []) as $row) {
			$array = $this->toArray(value: $row);
			if ($array === []) {
				continue;
			}

			$status = strtolower((string)($array['status'] ?? ''));
			if (in_array($status, self::QUEUED_DELIVERY_STATUSES, true) === false) {
				continue;
			}

			if ($sourceBlastId !== null && $sourceBlastId !== '') {
				$rowBlast = (string)($array['blastId'] ?? '');
				if ($rowBlast !== '' && $rowBlast !== $sourceBlastId) {
					// Sourcing blast pinned and delivery belongs to a
					// different blast — leave it alone.
					continue;
				}
			}

			$id = $this->extractObjectId(payload: $array);
			if ($id === '') {
				continue;
			}

			$array['status'] = self::STATUS_UNSUBSCRIBED_BEFORE_SEND;
			$array['unsubscribedAt'] = gmdate('Y-m-d\TH:i:s\Z');

			try {
				$objectService->updateObject(
					id: $id,
					object: $array,
					register: $register,
					schema: $schema,
				);
			} catch (Throwable $e) {
				$this->logger->warning(
					'ComplianceService.transitionQueuedDeliveries: updateObject failed',
					[
						'id' => $id,
						'exception' => $e->getMessage(),
					]
				);
			}
		}//end foreach
	}//end transitionQueuedDeliveries()

	/**
	 * Look up a ConsentRecord by (contactId, channel, list scope).
	 *
	 * The list scope is matched EXACTLY rather than loosely. `null` finds
	 * only the channel-wide record, the one with no `listId`, which is what
	 * the Segment path has always consulted; a string finds only that list's
	 * record. Taking the first row that matched (contactId, channel) would
	 * have let a list-scoped record answer for the whole channel the moment
	 * mailing lists shipped, which is exactly the regression this argument
	 * exists to prevent.
	 *
	 * @param string $contactId Contact UUID / slug.
	 * @param string $channel "email" or "sms".
	 * @param string|null $listId MailingList id, or null for the
	 *                            channel-wide record.
	 *
	 * @return array<string, mixed>|null Record array or null.
	 */
	private function findConsentRecord(string $contactId, string $channel, ?string $listId = null): ?array {
		$register = $this->getRegisterSlug();
		$schema = $this->getConsentRecordSchemaSlug();
		if ($register === '' || $schema === '') {
			return null;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return null;
		}

		try {
			$rows = $objectService->findAll(
				config: [
					'filters' => [
						'contactId' => $contactId,
						'channel' => $channel,
						'register' => $register,
						'schema' => $schema,
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.findConsentRecord: findAll failed',
				[
					'contactId' => $contactId,
					'channel' => $channel,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

		$wanted = '';
		if ($listId !== null) {
			$wanted = $listId;
		}

		foreach (($rows ?? []) as $row) {
			$array = $this->toArray(value: $row);
			$isMatch = $this->isConsentRecordFor(
				row: $array,
				contactId: $contactId,
				channel: $channel,
				wantedList: $wanted,
			);
			if ($isMatch === true) {
				return $array;
			}
		}

		return null;
	}//end findConsentRecord()

	/**
	 * Whether a row is the ConsentRecord for a contact, channel and scope.
	 *
	 * A defensive in-PHP re-check: OpenRegister's filter DSL ignores a key
	 * it does not recognise, and an ignored filter returns rows nobody asked
	 * for while looking exactly like a correct result.
	 *
	 * @param array<string, mixed> $row The candidate row.
	 * @param string $contactId The contact being looked up.
	 * @param string $channel The lower-cased channel.
	 * @param string $wantedList The list id, or an empty string for the
	 *                           channel-wide record.
	 *
	 * @return bool True when the row is the one asked for.
	 */
	private function isConsentRecordFor(array $row, string $contactId, string $channel, string $wantedList): bool {
		if ($row === []) {
			return false;
		}

		if ((string)($row['contactId'] ?? '') !== $contactId) {
			return false;
		}

		if (strtolower((string)($row['channel'] ?? '')) !== $channel) {
			return false;
		}

		return (string)($row['listId'] ?? '') === $wantedList;
	}//end isConsentRecordFor()

	/**
	 * Resolve the register slug from app config.
	 *
	 * @return string Register slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Resolve the ConsentRecord schema slug from app config.
	 *
	 * @return string Schema slug.
	 */
	private function getConsentRecordSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'consent_record_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_CONSENT_RECORD_SCHEMA_SLUG;
	}//end getConsentRecordSchemaSlug()

	/**
	 * Resolve the BlastDelivery schema slug from app config.
	 *
	 * @return string Schema slug.
	 */
	private function getBlastDeliverySchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'blast_delivery_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_BLAST_DELIVERY_SCHEMA_SLUG;
	}//end getBlastDeliverySchemaSlug()

	/**
	 * Resolve OpenRegister's ObjectService lazily.
	 *
	 * @return object|null ObjectService, or null when OpenRegister is
	 *                     not loaded.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'ComplianceService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OpenRegister entity (or array) to a plain array.
	 *
	 * @param mixed $value Entity object or array.
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
	 * Extract the canonical id from an OpenRegister entity payload.
	 *
	 * @param array<string, mixed> $payload Entity payload.
	 *
	 * @return string Identifier or empty string.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Ordered fallback lookup over id keys; extraction adds no clarity.
	 */
	private function extractObjectId(array $payload): string {
		foreach (['id', 'uuid', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true) {
				$value = (string)$payload[$key];
				if ($value !== '') {
					return $value;
				}
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				if (isset($payload['@self'][$key]) === true && is_scalar($payload['@self'][$key]) === true) {
					$value = (string)$payload['@self'][$key];
					if ($value !== '') {
						return $value;
					}
				}
			}
		}

		return '';
	}//end extractObjectId()
}//end class
