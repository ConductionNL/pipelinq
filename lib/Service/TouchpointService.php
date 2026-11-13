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
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;

/**
 * TouchpointService: append and read the campaign touchpoint log.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
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
	 * The contact schema a submission's touches are written on.
	 */
	public const CONTACT_SLUG = 'contact';

	/**
	 * The client schema a submission's touches are written on.
	 */
	public const CLIENT_SLUG = 'client';

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
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
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
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
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
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
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
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
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
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
	 * Record a form submission: append its touchpoint, naming the contact's client
	 * and the landing page, and update the contact's and the client's touches.
	 *
	 * The first touch is written once and never replaced; the last touch is
	 * replaced on every submission. A contact without a client updates only the
	 * contact; a submission without a contact only appends the touchpoint.
	 *
	 * @param array<string, mixed> $touchpoint  The row, as append() takes it: `contactId`,
	 *                                          `campaignId`, `utm`, `kind`, `occurredAt`, ...
	 * @param string               $landingPage The page the form was submitted on.
	 *
	 * @return array<string, mixed>|null The saved touchpoint, or null when the write failed.
	 *
	 * @spec openspec/changes/marketing-campaign-on-client/specs/marketing-origin-on-client/spec.md
	 */
	public function recordSubmission(array $touchpoint, string $landingPage): ?array {
		$contactId = trim((string)($touchpoint['contactId'] ?? ''));
		$contact = null;
		if ($contactId !== '') {
			$contact = $this->store->find(schemaSlug: self::CONTACT_SLUG, id: $contactId);
		}

		$clientId = $this->referenceId(value: ($contact['client'] ?? null));
		$row = array_merge($touchpoint, ['clientId' => $clientId, 'landingPage' => trim($landingPage)]);
		$saved = $this->append(touchpoint: $row);
		if ($saved === null) {
			return null;
		}

		$touch = $this->touchOf(touchpoint: $saved);
		if ($contact !== null) {
			$this->updateTouches(schemaSlug: self::CONTACT_SLUG, id: $contactId, record: $contact, touch: $touch);
		}

		if ($clientId !== '') {
			$client = $this->store->find(schemaSlug: self::CLIENT_SLUG, id: $clientId);
			if ($client !== null) {
				$this->updateTouches(schemaSlug: self::CLIENT_SLUG, id: $clientId, record: $client, touch: $touch);
			}
		}

		return $saved;
	}//end recordSubmission()

	/**
	 * The uuid in a reference: a plain string, or the `id`/`uuid` of an expanded object.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string '' when it holds none.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? $value['uuid'] ?? '');
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceId()

	/**
	 * The touch a submission leaves on its contact and client.
	 *
	 * @param array<string, mixed> $touchpoint The saved touchpoint.
	 *
	 * @return array<string, string>
	 */
	private function touchOf(array $touchpoint): array {
		$utm = $touchpoint['utm'] ?? [];
		if (is_array($utm) === false) {
			$utm = [];
		}

		$touch = [];
		foreach (['campaign', 'source', 'medium', 'term', 'content'] as $key) {
			$touch[$key] = trim((string)($utm[$key] ?? ''));
		}

		$touch['campaignId'] = trim((string)($touchpoint['campaignId'] ?? ''));
		$touch['landingPage'] = trim((string)($touchpoint['landingPage'] ?? ''));
		$touch['at'] = trim((string)($touchpoint['occurredAt'] ?? ''));

		return $touch;
	}//end touchOf()

	/**
	 * Set the last touch, and the first touch when there is none yet, on a record.
	 *
	 * @param string               $schemaSlug The record's schema.
	 * @param string               $id         The record.
	 * @param array<string, mixed> $record     The record as read.
	 * @param array<string, string> $touch      The touch.
	 *
	 * @return void
	 */
	private function updateTouches(string $schemaSlug, string $id, array $record, array $touch): void {
		$first = $record['firstTouch'] ?? null;
		if (is_array($first) === false || $first === []) {
			$record['firstTouch'] = $touch;
		}

		$record['lastTouch'] = $touch;
		unset($record['@self']);
		$this->store->save(schemaSlug: $schemaSlug, payload: $record, id: $id);
	}//end updateTouches()

	/**
	 * The canonical id of a touchpoint row.
	 *
	 * @param array<string, mixed>|null $touchpoint The touchpoint.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-landing-page-submission-becomes-a-contact-a-lead-and-a-touchpoint
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
	 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
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
