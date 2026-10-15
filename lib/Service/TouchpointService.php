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
 * recordSubmission() is the one writer for a web form submission, whichever
 * intake it came through: it appends the touchpoint and keeps the contact's
 * and its client's first and last touch. It writes as pipelinq's system
 * account with OpenRegister's access checks on (SystemObjectStore), because
 * it runs on public paths. append() and the readers keep ListObjectStore.
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
	 * Longest campaign value kept, in characters. Longer is cut, never refused.
	 *
	 * @var int
	 */
	public const MAX_UTM_LENGTH = 100;

	/**
	 * Longest landing page kept, in characters.
	 *
	 * @var int
	 */
	public const MAX_LANDING_PAGE_LENGTH = 500;

	/**
	 * The campaign values a touch carries, in the order a person reads them.
	 *
	 * @var array<int, string>
	 */
	public const UTM_KEYS = ['campaign', 'source', 'medium', 'term', 'content'];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore        $store       Register-scoped object access for the log's readers and append().
	 * @param SystemObjectStore|null $systemStore Access as the system account, checks on, for recordSubmission().
	 *                                            Nextcloud always injects it; null only in tests of the readers.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
	 */
	public function __construct(
		private ListObjectStore $store,
		private ?SystemObjectStore $systemStore=null,
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
	 * Record one web form submission: the touchpoint, and the contact's and its client's touches.
	 *
	 * The touchpoint is written when the submission belongs to a campaign (the
	 * touchpoint schema requires one). The contact's first touch is written once
	 * and never changed; its last touch follows every submission. The client is
	 * the contact's `client`, and gets the same two touches. Every read and write
	 * runs as pipelinq's system account with OpenRegister's access checks on.
	 *
	 * @param string               $contactId   The contact who submitted, or an empty string.
	 * @param string               $campaignId  The campaign the submission belongs to, or an empty string.
	 * @param array<string, mixed> $utm         Campaign values: campaign, source, medium, term, content.
	 * @param string               $landingPage The page the form was sent from.
	 * @param array<string, mixed> $extra       Optional: leadId, nonce, sourceRef, occurredAt.
	 *
	 * @return array{touchpoint: array<string, mixed>|null, touched: list<string>} The stored
	 *         touchpoint (null when none was written) and the schemas whose touches were written.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-contacts-and-clients-show-how-they-found-you-req-moc-001
	 */
	public function recordSubmission(
		string $contactId,
		string $campaignId,
		array $utm,
		string $landingPage,
		array $extra=[],
	): array {
		$result = ['touchpoint' => null, 'touched' => []];
		if ($this->systemStore === null) {
			return $result;
		}

		$campaignId = trim($campaignId);
		$contactId  = trim($contactId);
		$values     = $this->utmValues(utm: $utm);
		$page       = mb_substr(trim($landingPage), 0, self::MAX_LANDING_PAGE_LENGTH);
		$moment     = trim((string)($extra['occurredAt'] ?? ''));
		if ($moment === '') {
			$moment = gmdate('Y-m-d\TH:i:sP');
		}

		$contact = null;
		if ($contactId !== '') {
			$contact = $this->systemStore->find(schemaSlug: 'contact', id: $contactId);
		}

		$clientId = trim((string)($contact['client'] ?? ''));

		if ($campaignId !== '') {
			$result['touchpoint'] = $this->writeTouchpoint(
				campaignId: $campaignId,
				contactId: $contactId,
				clientId: $clientId,
				values: $values,
				page: $page,
				moment: $moment,
				extra: $extra
			);
		}

		$touch = array_filter(
			array_merge(['campaignId' => $campaignId], $values, ['landingPage' => $page, 'at' => $moment]),
			static fn (string $value): bool => $value !== ''
		);

		if ($contact !== null && $this->writeTouches(schemaSlug: 'contact', id: $contactId, record: $contact, touch: $touch) === true) {
			$result['touched'][] = 'contact';
		}

		if ($this->touchClient(clientId: $clientId, touch: $touch) === true) {
			$result['touched'][] = 'client';
		}

		return $result;
	}//end recordSubmission()

	/**
	 * The campaign values, each trimmed and cut to MAX_UTM_LENGTH; any other key is dropped.
	 *
	 * @param array<string, mixed> $utm The submitted values.
	 *
	 * @return array<string, string> One entry per UTM_KEYS key.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md#requirement-the-website-enquiry-form-keeps-campaign-values-req-moc-003
	 */
	public function utmValues(array $utm): array {
		$values = [];
		foreach (self::UTM_KEYS as $key) {
			$raw = ($utm[$key] ?? '');
			if (is_scalar($raw) === false) {
				$raw = '';
			}

			$values[$key] = mb_substr(trim((string)$raw), 0, self::MAX_UTM_LENGTH);
		}

		return $values;
	}//end utmValues()

	/**
	 * Write the `submit` touchpoint of a submission that belongs to a campaign.
	 *
	 * @param string                $campaignId The campaign.
	 * @param string                $contactId  The contact, may be empty.
	 * @param string                $clientId   The contact's client, may be empty.
	 * @param array<string, string> $values     The capped campaign values.
	 * @param string                $page       The landing page, may be empty.
	 * @param string                $moment     When the form was sent.
	 * @param array<string, mixed>  $extra      leadId, nonce, sourceRef.
	 *
	 * @return array<string, mixed>|null The stored touchpoint, or null.
	 */
	private function writeTouchpoint(
		string $campaignId,
		string $contactId,
		string $clientId,
		array $values,
		string $page,
		string $moment,
		array $extra,
	): ?array {
		$row = array_filter(
			[
				'campaignId' => $campaignId,
				'contactId' => $contactId,
				'clientId' => $clientId,
				'leadId' => trim((string)($extra['leadId'] ?? '')),
				'kind' => 'submit',
				'channel' => $this->channelForMedium(medium: $values['medium']),
				'utm' => $values,
				'landingPage' => $page,
				'sourceRef' => trim((string)($extra['sourceRef'] ?? '')),
				'nonce' => trim((string)($extra['nonce'] ?? '')),
				'occurredAt' => $moment,
				'createdAt' => gmdate('Y-m-d\TH:i:sP'),
			],
			static fn (mixed $value): bool => $value !== ''
		);

		return $this->systemStore?->save(schemaSlug: $this->schemaSlug(), payload: $row);
	}//end writeTouchpoint()

	/**
	 * Write the touches of the contact's client, when there is one.
	 *
	 * @param string                $clientId The client, may be empty.
	 * @param array<string, string> $touch    The touch of this submission.
	 *
	 * @return bool True when the client's touches were stored.
	 */
	private function touchClient(string $clientId, array $touch): bool {
		if ($clientId === '') {
			return false;
		}

		$client = $this->systemStore?->find(schemaSlug: 'client', id: $clientId);
		if ($client === null) {
			return false;
		}

		return $this->writeTouches(schemaSlug: 'client', id: $clientId, record: $client, touch: $touch);
	}//end touchClient()

	/**
	 * Write a record's touches: the first only when it has none, the last always.
	 *
	 * @param string                $schemaSlug The record's schema.
	 * @param string                $id         The record's id.
	 * @param array<string, mixed>  $record     The record as read.
	 * @param array<string, string> $touch      The touch of this submission.
	 *
	 * @return bool True when the write was stored.
	 */
	private function writeTouches(string $schemaSlug, string $id, array $record, array $touch): bool {
		$payload = $record;
		unset($payload['@self']);
		if (empty($payload['firstTouch']) === true) {
			$payload['firstTouch'] = $touch;
		}

		$payload['lastTouch'] = $touch;

		return ($this->systemStore?->save(schemaSlug: $schemaSlug, payload: $payload, id: $id) !== null);
	}//end writeTouches()

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
