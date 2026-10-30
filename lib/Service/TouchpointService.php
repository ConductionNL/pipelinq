<?php

/**
 * Pipelinq TouchpointService.
 *
 * The touchpoint log: one row per attributable interaction between a
 * person and a campaign. Attribution is computed over these rows at
 * report time and never written back, so changing the model changes the
 * report without rewriting history.
 *
 * The nonce is what makes the landing-page submission listener safe.
 * Portaliq stamps one per submission and can dispatch the same
 * submission twice, because its relay listens to OpenRegister's
 * `ObjectCreatedEvent` and a repair or a replayed write fires that
 * again. Looking the nonce up here, before anything is written, is the
 * whole idempotency guarantee.
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
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * TouchpointService: append and read the campaign touchpoint log.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */
class TouchpointService {

	/**
	 * The schema slug the register fragment declares.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'touchpoint';

	/**
	 * App-config key holding an override for the schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'touchpoint_schema';

	/**
	 * The interaction kinds a touchpoint may carry.
	 *
	 * @var array<int, string>
	 */
	public const KINDS = ['click', 'visit', 'submit', 'reply'];

	/**
	 * The contact schema slug, whose records carry the touches.
	 *
	 * @var string
	 */
	public const CONTACT_SCHEMA_SLUG = 'contact';

	/**
	 * The client schema slug, whose records carry the touches.
	 *
	 * @var string
	 */
	public const CLIENT_SCHEMA_SLUG = 'client';

	/**
	 * The campaign parameters a touch keeps.
	 *
	 * @var array<int, string>
	 */
	public const UTM_KEYS = ['campaign', 'source', 'medium', 'term', 'content'];

	/**
	 * The longest landing page or form name a touch keeps.
	 *
	 * @var int
	 */
	private const MAX_PAGE_LENGTH = 2048;

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function __construct(
		private ListObjectStore $store,
	) {
	}//end __construct()

	/**
	 * The touchpoint schema slug, honouring an app-config override.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}//end schemaSlug()

	/**
	 * Every touchpoint of one campaign, oldest first.
	 *
	 * Rows sharing an `occurredAt` keep the order they were written in,
	 * so first touch and last touch answer the same way on every run. A
	 * whole-second timestamp cannot order two events in the same second
	 * on its own, and a report that reorders itself between runs is worse
	 * than one that is arguably wrong.
	 *
	 * @param string $campaignId The campaign.
	 *
	 * @return array<int, array<string, mixed>> The touchpoints.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
	 */
	public function forCampaign(string $campaignId): array {
		if (trim($campaignId) === '') {
			return [];
		}

		$rows = $this->store->findAll(
			schemaSlug: $this->schemaSlug(),
			filters: ['campaignId' => trim($campaignId)],
		);

		$indexed = [];
		foreach ($rows as $index => $row) {
			$indexed[] = ['index' => $index, 'row' => $row];
		}

		usort(
			$indexed,
			static function (array $left, array $right): int {
				$compared = strcmp(
					(string)($left['row']['occurredAt'] ?? ''),
					(string)($right['row']['occurredAt'] ?? '')
				);
				if ($compared !== 0) {
					return $compared;
				}

				return ($left['index'] <=> $right['index']);
			}
		);

		return array_map(static fn (array $entry): array => $entry['row'], $indexed);
	}//end forCampaign()

	/**
	 * Whether a touchpoint already carries this nonce.
	 *
	 * @param string $nonce The producing system's own id for the interaction.
	 *
	 * @return bool True when the interaction was already recorded.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function existsForNonce(string $nonce): bool {
		if (trim($nonce) === '') {
			return false;
		}

		return ($this->store->findAll(schemaSlug: $this->schemaSlug(), filters: ['nonce' => trim($nonce)]) !== []);
	}//end existsForNonce()

	/**
	 * Append one touchpoint.
	 *
	 * @param array<string, mixed> $touchpoint The row: `campaignId`, `kind`,
	 *                                         `occurredAt` and whatever else is known.
	 *
	 * @return array<string, mixed>|null The saved row, or null when the write failed.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function append(array $touchpoint): ?array {
		$kind = (string)($touchpoint['kind'] ?? '');
		if (in_array($kind, self::KINDS, true) === false) {
			return null;
		}

		$row = $touchpoint;
		if (trim((string)($row['occurredAt'] ?? '')) === '') {
			$row['occurredAt'] = gmdate('Y-m-d\TH:i:sP');
		}

		$row['createdAt'] = gmdate('Y-m-d\TH:i:sP');

		return $this->store->save(schemaSlug: $this->schemaSlug(), payload: $row);
	}//end append()

	/**
	 * Record a form submission: append its touchpoint with the client and
	 * the landing page, then give the contact and its client their touches.
	 * The first touch is written once and kept; the last touch follows every
	 * submission. The client is the touchpoint's own, else the contact's.
	 *
	 * @param array<string, mixed> $touchpoint  The row, as for append(), with `contactId` when known.
	 * @param string               $landingPage The page the form sat on.
	 * @param string               $form        The form's name.
	 *
	 * @return array<string, mixed>|null The saved touchpoint, or null when the write failed.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-contacts-and-clients-show-how-they-found-you-req-moc-001
	 */
	public function recordSubmission(array $touchpoint, string $landingPage = '', string $form = ''): ?array {
		$contactId = trim((string)($touchpoint['contactId'] ?? ''));
		$contact   = $this->findRecord(schemaSlug: self::CONTACT_SCHEMA_SLUG, id: $contactId);

		$clientId = trim((string)($touchpoint['clientId'] ?? ''));
		if ($clientId === '') {
			$clientId = trim((string)($contact['client'] ?? ''));
		}

		unset($touchpoint['clientId'], $touchpoint['landingPage']);
		if ($clientId !== '') {
			$touchpoint['clientId'] = $clientId;
		}

		$landingPage = mb_substr(trim($landingPage), 0, self::MAX_PAGE_LENGTH);
		if ($landingPage !== '') {
			$touchpoint['landingPage'] = $landingPage;
		}

		$saved = $this->append(touchpoint: $touchpoint);
		if ($saved === null) {
			return null;
		}

		$touch = $this->touchOf(touchpoint: $saved, form: $form);
		$this->stampTouches(schemaSlug: self::CONTACT_SCHEMA_SLUG, id: $contactId, record: $contact, touch: $touch);
		$this->stampTouches(
			schemaSlug: self::CLIENT_SCHEMA_SLUG,
			id: $clientId,
			record: $this->findRecord(schemaSlug: self::CLIENT_SCHEMA_SLUG, id: $clientId),
			touch: $touch
		);

		return $saved;
	}//end recordSubmission()

	/**
	 * A contact or client by id, or null when there is no id or no record.
	 *
	 * @param string $schemaSlug The record's schema.
	 * @param string $id         The record's id, may be empty.
	 *
	 * @return array<string, mixed>|null The record.
	 */
	private function findRecord(string $schemaSlug, string $id): ?array {
		if ($id === '') {
			return null;
		}

		return $this->store->find(schemaSlug: $schemaSlug, id: $id);
	}//end findRecord()

	/**
	 * The touch a contact or client keeps of one submission.
	 *
	 * @param array<string, mixed> $touchpoint The saved touchpoint.
	 * @param string               $form       The form's name.
	 *
	 * @return array<string, string> The touch: the campaign parameters, the campaign, the landing page, the form and when.
	 */
	private function touchOf(array $touchpoint, string $form): array {
		$utm = $touchpoint['utm'] ?? [];
		if (is_array($utm) === false) {
			$utm = [];
		}

		$touch = [];
		foreach (self::UTM_KEYS as $key) {
			$touch[$key] = mb_substr(trim((string)($utm[$key] ?? '')), 0, 255);
		}

		$touch['campaignId']  = trim((string)($touchpoint['campaignId'] ?? ''));
		$touch['landingPage'] = (string)($touchpoint['landingPage'] ?? '');
		$touch['form']        = mb_substr(trim($form), 0, 255);
		$touch['at']          = (string)($touchpoint['occurredAt'] ?? '');

		return $touch;
	}//end touchOf()

	/**
	 * Write a touch on a contact or client: the first touch only when it has
	 * none, the last touch always. A failed write is left to the store's log;
	 * the touchpoint is already kept.
	 *
	 * @param string                    $schemaSlug The record's schema.
	 * @param string                    $id         The record's id.
	 * @param array<string, mixed>|null $record     The record as stored, or null when there is none.
	 * @param array<string, string>     $touch      The touch.
	 *
	 * @return void
	 */
	private function stampTouches(string $schemaSlug, string $id, ?array $record, array $touch): void {
		if ($record === null) {
			return;
		}

		unset($record['@self']);
		$first = $record['firstTouch'] ?? null;
		if (is_array($first) === false || trim((string)($first['at'] ?? '')) === '') {
			$record['firstTouch'] = $touch;
		}

		$record['lastTouch'] = $touch;
		$this->store->save(schemaSlug: $schemaSlug, payload: $record, id: $id);
	}//end stampTouches()

	/**
	 * The canonical id of a touchpoint row.
	 *
	 * @param array<string, mixed>|null $touchpoint The touchpoint.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function idOf(?array $touchpoint): string {
		return $this->store->idOf(payload: $touchpoint);
	}//end idOf()

	/**
	 * The channel a medium belongs to.
	 *
	 * A medium is what a link carried; a channel is what a marketer reads
	 * in the report. `cpc` and `display` are both paid, `social` is a
	 * post, and anything unknown is reported as it arrived rather than
	 * bucketed into "other", which would hide a typo in a UTM value.
	 *
	 * @param string $medium The `utm_medium` value.
	 *
	 * @return string The channel.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
	 */
	public function channelForMedium(string $medium): string {
		$value = strtolower(trim($medium));
		if ($value === '') {
			return 'direct';
		}

		$map = ['cpc' => 'paid', 'display' => 'paid', 'ppc' => 'paid'];

		return ($map[$value] ?? $value);
	}//end channelForMedium()
}//end class
