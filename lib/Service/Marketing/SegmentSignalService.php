<?php

/**
 * Pipelinq SegmentSignalService.
 *
 * The eight derived fields a segment rule may use next to the schema's own
 * properties: six read out of shillinq's bookkeeping and two out of
 * pipelinq's own contracts and leads.
 *
 * 🔴 A SIGNAL THAT CANNOT BE RESOLVED MUST SHRINK THE AUDIENCE, NEVER WIDEN
 * IT. `SegmentService::compareNumeric()` returns 0 when either side is not
 * numeric, so `gte` and `lte` both answer TRUE for a value the evaluator
 * could not read. A rule saying "no invoice for twelve months" would then
 * match every customer on an instance without shillinq, and the mailing
 * would look like a correct result. {@see resolves()} is what stops that:
 * the evaluator asks it before it asks for a value, and a leaf on an
 * unresolved signal is false whatever the operator, `isNull` excepted.
 *
 * 🔴 READ ONLY, ON BOTH SIDES. Money stays in shillinq (ADR-107): every
 * amount here comes back through {@see ShillinqInvoiceReader}, which has no
 * write path, and nothing computed here is ever stored on a pipelinq object.
 * A tier cached on a client is a second source of truth that goes stale
 * silently, which is exactly the failure a derived field avoids.
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
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

use OCP\AppFramework\Utility\ITimeFactory;

/**
 * SegmentSignalService: derived segment fields, and whether they resolve.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One resolver over four
 *  collections and one external reader; splitting it per field would put the
 *  memo that makes the whole thing affordable in four places.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
 */
class SegmentSignalService {

	/**
	 * Recognised revenue over the signal window, in the tenant's currency.
	 *
	 * @var string
	 */
	public const FIELD_REVENUE = 'shillinqRecognisedRevenue';

	/**
	 * The value tier that revenue falls into.
	 *
	 * @var string
	 */
	public const FIELD_VALUE_TIER = 'shillinqValueTier';

	/**
	 * Whole months since the customer's most recent invoice.
	 *
	 * @var string
	 */
	public const FIELD_MONTHS_SINCE_INVOICE = 'shillinqMonthsSinceLastInvoice';

	/**
	 * Catalogue products the customer has been invoiced for.
	 *
	 * @var string
	 */
	public const FIELD_PRODUCTS = 'shillinqPurchasedProducts';

	/**
	 * Catalogue services the customer has been invoiced for.
	 *
	 * @var string
	 */
	public const FIELD_SERVICES = 'shillinqPurchasedServices';

	/**
	 * Where the customer stands with the credit control.
	 *
	 * @var string
	 */
	public const FIELD_DUNNING = 'shillinqDunningState';

	/**
	 * Days until the nearest contract of this customer ends.
	 *
	 * @var string
	 */
	public const FIELD_RENEWAL_DAYS = 'pipelinqContractRenewalDays';

	/**
	 * Days the longest-waiting open lead has sat in its stage.
	 *
	 * @var string
	 */
	public const FIELD_STALLED_DAYS = 'pipelinqStalledLeadDays';

	/**
	 * The dunning states a promotional send is suppressed on by default.
	 *
	 * @var array<int, string>
	 */
	public const SUPPRESSING_DUNNING_STATES = ['overdue', 'written-off'];

	/**
	 * The dunning state of a customer with no invoice at all.
	 *
	 * @var string
	 */
	public const DUNNING_UNKNOWN = 'unknown';

	/**
	 * Seconds in a day, for the two day-count signals.
	 *
	 * @var int
	 */
	private const DAY = 86400;

	/**
	 * The lead statuses that are still worth nudging.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_LEAD_STATUSES = ['new', 'open', 'in-progress', 'qualified', 'proposal', 'negotiation'];

	/**
	 * Per-instance memo of the whole signal set, keyed by client id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $memo = [];

	/**
	 * Per-instance memo of the product catalogue, name => type.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $catalogue = null;

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object plumbing.
	 * @param BookkeepingSignals $bookkeeping The six shillinq-derived fields.
	 * @param ITimeFactory $time Clock.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function __construct(
		private ListObjectStore $store,
		private BookkeepingSignals $bookkeeping,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The signal catalogue: field name to the description a builder shows.
	 *
	 * Every entry carries the JSON-schema `type` the rule validator needs,
	 * so the catalogue is the single place a signal's operator matrix is
	 * decided rather than a second list kept in step by hand.
	 *
	 * @return array<string, array{type: string, title: string, source: string, description: string}>
	 *         The catalogue, in the order a builder lists it.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function catalogue(): array {
		return [
			self::FIELD_REVENUE => [
				'type' => 'number',
				'title' => 'Recognised revenue',
				'source' => 'shillinq',
				'description' => 'Paid invoices for this customer over the signal window.',
			],
			self::FIELD_VALUE_TIER => [
				'type' => 'string',
				'title' => 'Value tier',
				'source' => 'shillinq',
				'description' => 'top, mid, low or none, from recognised revenue against the tier thresholds.',
			],
			self::FIELD_MONTHS_SINCE_INVOICE => [
				'type' => 'integer',
				'title' => 'Months since the last invoice',
				'source' => 'shillinq',
				'description' => 'Whole months since the most recent invoice that left the draft stage.',
			],
			self::FIELD_PRODUCTS => [
				'type' => 'array',
				'title' => 'Purchased products',
				'source' => 'shillinq',
				'description' => 'Catalogue products named on this customer\'s invoice lines.',
			],
			self::FIELD_SERVICES => [
				'type' => 'array',
				'title' => 'Purchased services',
				'source' => 'shillinq',
				'description' => 'Catalogue services named on this customer\'s invoice lines.',
			],
			self::FIELD_DUNNING => [
				'type' => 'string',
				'title' => 'Dunning state',
				'source' => 'shillinq',
				'description' => 'current, overdue, disputed, written-off or unknown.',
			],
			self::FIELD_RENEWAL_DAYS => [
				'type' => 'integer',
				'title' => 'Days to contract renewal',
				'source' => 'pipelinq',
				'description' => 'Days until the nearest contract of this customer ends.',
			],
			self::FIELD_STALLED_DAYS => [
				'type' => 'integer',
				'title' => 'Days a lead has been stalled',
				'source' => 'pipelinq',
				'description' => 'Days the longest-waiting open lead has sat in its current stage.',
			],
		];
	}//end catalogue()

	/**
	 * The catalogue shaped as an OpenRegister property map.
	 *
	 * `SegmentService` merges this over the schema's own properties so a
	 * rule leaf on a signal validates by the same code path as a rule leaf
	 * on a stored field. A signal never shadows a real property: the merge
	 * puts the schema first.
	 *
	 * @return array<string, array{type: string, title: string, description: string}> Property map.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-segment-builder-lists-the-signals-and-validates-a-rule-on-one
	 */
	public function schemaProperties(): array {
		$properties = [];
		foreach ($this->catalogue() as $field => $entry) {
			$properties[$field] = [
				'type' => $entry['type'],
				'title' => $entry['title'],
				'description' => $entry['description'],
			];
		}

		return $properties;
	}//end schemaProperties()

	/**
	 * Whether a rule field names a signal rather than a stored property.
	 *
	 * @param string $field The rule leaf's field.
	 *
	 * @return bool True when the field is one of the eight.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-segment-builder-lists-the-signals-and-validates-a-rule-on-one
	 */
	public function isSignalField(string $field): bool {
		return array_key_exists($field, $this->catalogue());
	}//end isSignalField()

	/**
	 * Whether shillinq is present, and what it means when it is not.
	 *
	 * @return array{shillinq: bool, reason: string} The availability report.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-an-unresolved-signal-shrinks-the-audience
	 */
	public function availability(): array {
		$available = $this->bookkeeping->isAvailable();
		$reason = 'shillinq_not_installed';
		if ($available === true) {
			$reason = '';
		}

		return ['shillinq' => $available, 'reason' => $reason];
	}//end availability()

	/**
	 * Whether this signal resolves to a value for this entity.
	 *
	 * False means "no answer", not "the answer is zero". The evaluator
	 * turns that into a false leaf whatever the operator, which is the one
	 * rule that keeps an unreadable bookkeeping from widening an audience.
	 *
	 * @param string $field The signal field.
	 * @param array<string, mixed> $entity The contact or client payload.
	 *
	 * @return bool True when {@see valueFor()} has an answer.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-an-unresolved-signal-shrinks-the-audience
	 */
	public function resolves(string $field, array $entity): bool {
		return ($this->valueFor(field: $field, entity: $entity) !== null);
	}//end resolves()

	/**
	 * One signal's value for one contact or client, or null.
	 *
	 * @param string $field The signal field.
	 * @param array<string, mixed> $entity The contact or client payload.
	 *
	 * @return mixed The value, or null when it does not resolve.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function valueFor(string $field, array $entity): mixed {
		if ($this->isSignalField(field: $field) === false) {
			return null;
		}

		$clientId = $this->clientIdOf(entity: $entity);
		if ($clientId === '') {
			return null;
		}

		$signals = $this->signalsFor(clientId: $clientId);
		return ($signals[$field] ?? null);
	}//end valueFor()

	/**
	 * Every signal of one client, memoised for the life of this instance.
	 *
	 * Evaluating a rule tree walks every contact, and several of them share
	 * a client. Reading the bookkeeping once per client rather than once per
	 * contact is the difference between a preview that answers and one that
	 * times out.
	 *
	 * @param string $clientId The pipelinq client id.
	 *
	 * @return array<string, mixed> Field to value, nulls included.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function signalsFor(string $clientId): array {
		if (array_key_exists($clientId, $this->memo) === true) {
			return $this->memo[$clientId];
		}

		$client = $this->store->find(schemaSlug: $this->store->schemaSlug('client_schema', 'client'), id: $clientId);
		$signals = array_merge(
			$this->bookkeeping->forCustomer(
				customerRef: (string)(($client ?? [])['shillinqOrganisationRef'] ?? ''),
				catalogue: $this->productCatalogue()
			),
			$this->crmSignals(clientId: $clientId)
		);

		$this->memo[$clientId] = $signals;
		return $signals;
	}//end signalsFor()

	/**
	 * Where one contact's customer stands with the credit control.
	 *
	 * The consent gate asks this, and it asks by contact id because that is
	 * all a segment member carries. Null means "no answer": the contact is
	 * unknown, has no customer, or the bookkeeping cannot be read. A null
	 * never suppresses, because refusing to mail everybody the moment
	 * shillinq is uninstalled is worse than mailing a late payer once.
	 *
	 * @param string $contactId The contact id.
	 *
	 * @return string|null The dunning state, or null when there is no answer.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-promotional-send-skips-a-customer-in-dunning
	 */
	public function dunningStateForContact(string $contactId): ?string {
		$contactId = trim($contactId);
		if ($contactId === '') {
			return null;
		}

		$contact = $this->store->find(
			schemaSlug: $this->store->schemaSlug('contact_schema', 'contact'),
			id: $contactId
		);
		if ($contact === null) {
			return null;
		}

		$state = $this->valueFor(field: self::FIELD_DUNNING, entity: $contact);
		if (is_string($state) === false || $state === self::DUNNING_UNKNOWN) {
			return null;
		}

		return $state;
	}//end dunningStateForContact()

	/**
	 * Forget every memoised answer.
	 *
	 * A long-running job that evaluates the same segment twice around a
	 * bookkeeping change would otherwise read the first answer twice.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-bookkeeping-supplies-six-segment-fields-and-pipelinq-stores-none-of-them
	 */
	public function forget(): void {
		$this->memo = [];
		$this->catalogue = null;
	}//end forget()

	/**
	 * The two pipelinq signals for one client.
	 *
	 * @param string $clientId The pipelinq client id.
	 *
	 * @return array<string, int|null> The renewal and stall day counts.
	 */
	private function crmSignals(string $clientId): array {
		return [
			self::FIELD_RENEWAL_DAYS => $this->renewalDays(clientId: $clientId),
			self::FIELD_STALLED_DAYS => $this->stalledDays(clientId: $clientId),
		];
	}//end crmSignals()

	/**
	 * Days until the nearest contract of this client ends.
	 *
	 * A contract that already ended is reported as a negative number rather
	 * than dropped, so "renewing in the next ninety days" and "lapsed last
	 * month" are two rules over one field instead of two fields.
	 *
	 * @param string $clientId The pipelinq client id.
	 *
	 * @return int|null Days, or null when no contract carries an end date.
	 */
	private function renewalDays(string $clientId): ?int {
		$rows = $this->store->findAll(
			schemaSlug: $this->store->schemaSlug('contract_schema', 'contract'),
			filters: ['clientRef' => $clientId]
		);

		$nearest = null;
		foreach ($rows as $row) {
			if ((string)($row['clientRef'] ?? '') !== $clientId) {
				continue;
			}

			$days = $this->daysUntil(value: ($row['endDate'] ?? null));
			if ($days === null) {
				continue;
			}

			if ($nearest === null || $days < $nearest) {
				$nearest = $days;
			}
		}

		return $nearest;
	}//end renewalDays()

	/**
	 * Days the longest-waiting open lead of this client has sat in its stage.
	 *
	 * @param string $clientId The pipelinq client id.
	 *
	 * @return int|null Days, or null when the client has no dated open lead.
	 */
	private function stalledDays(string $clientId): ?int {
		$rows = $this->store->findAll(
			schemaSlug: $this->store->schemaSlug('lead_schema', 'lead'),
			filters: ['client' => $clientId]
		);

		$longest = null;
		foreach ($rows as $row) {
			if ((string)($row['client'] ?? '') !== $clientId) {
				continue;
			}

			$status = strtolower(trim((string)($row['status'] ?? '')));
			if ($status !== '' && in_array($status, self::OPEN_LEAD_STATUSES, true) === false) {
				continue;
			}

			$days = $this->daysUntil(value: ($row['stageEnteredAt'] ?? null));
			if ($days === null) {
				continue;
			}

			$waited = -$days;
			if ($longest === null || $waited > $longest) {
				$longest = $waited;
			}
		}

		return $longest;
	}//end stalledDays()

	/**
	 * Whole CALENDAR days from today to a date, negative when it has passed.
	 *
	 * Both sides are floored to midnight on purpose. Comparing a date-only
	 * field against the current clock makes the same contract read as 44 days
	 * away at noon and 45 at breakfast, so a journey evaluated twice in one
	 * day would disagree with itself about a ninety-day window.
	 *
	 * @param mixed $value A date or date-time string.
	 *
	 * @return int|null The day count, or null when the value is not a date.
	 */
	private function daysUntil(mixed $value): ?int {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$stamp = strtotime(substr(trim($value), 0, 10) . ' 00:00:00');
		if ($stamp === false) {
			return null;
		}

		$today = strtotime(date('Y-m-d', $this->time->getTime()) . ' 00:00:00');
		if ($today === false) {
			return null;
		}

		return (int)round((($stamp - $today) / self::DAY));
	}//end daysUntil()

	/**
	 * The product catalogue as lowercase name to `product` or `service`.
	 *
	 * @return array<string, string> The catalogue.
	 */
	private function productCatalogue(): array {
		if ($this->catalogue !== null) {
			return $this->catalogue;
		}

		$catalogue = [];
		foreach ($this->store->findAll(schemaSlug: $this->store->schemaSlug('product_schema', 'product')) as $row) {
			$name = strtolower(trim((string)($row['name'] ?? '')));
			if ($name === '') {
				continue;
			}

			$catalogue[$name] = 'product';
			if (strtolower(trim((string)($row['type'] ?? 'product'))) === 'service') {
				$catalogue[$name] = 'service';
			}
		}

		$this->catalogue = $catalogue;
		return $catalogue;
	}//end productCatalogue()

	/**
	 * The client a signal is read for: the entity itself, or its client.
	 *
	 * A `client` object has no `client` property and a `contact` does, so
	 * the two cases separate without the caller having to say which one it
	 * is holding.
	 *
	 * @param array<string, mixed> $entity The contact or client payload.
	 *
	 * @return string The client id, empty when there is none.
	 */
	private function clientIdOf(array $entity): string {
		$link = ($entity['client'] ?? null);
		if (is_scalar($link) === true && trim((string)$link) !== '') {
			return trim((string)$link);
		}

		if (is_array($link) === true) {
			$nested = $this->store->idOf($link);
			if ($nested !== '') {
				return $nested;
			}
		}

		return $this->store->idOf($entity);
	}//end clientIdOf()

}//end class
