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
