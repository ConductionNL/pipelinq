<?php

/**
 * Pipelinq CampaignService.
 *
 * The campaign is the object that owns a UTM value. Everything a campaign
 * sends carries one `utm_campaign`, so two mailings and a post report as
 * one campaign instead of three, and this service is what mints, freezes
 * and hands out that value.
 *
 * Three rules live here rather than in the schema, because the schema
 * grammar cannot express them (ADR-031):
 *
 * 1. The slug is minted from the name on the first save and never changes.
 *    It is already written into portaliq's daily rollups and into links
 *    that have left the building, so a later edit would orphan them.
 * 2. Source and medium come from an admin-maintained vocabulary, not a
 *    shipped enum, because an administrator who needs `beurs` should not
 *    have to edit a register fragment.
 * 3. Both are lowercase. `LinkedIn` and `linkedin` are two campaigns in
 *    every analytics tool including portaliq's own rollup, and the split
 *    is invisible until a report comes back short.
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
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\IAppConfig;

/**
 * CampaignService: read, mint, validate and store a campaign.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */
class CampaignService {

	/**
	 * The schema slug the register fragment declares.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'campaign';

	/**
	 * App-config key holding an override for the schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'campaign_schema';

	/**
	 * App-config key holding the allowed `utm_source` values.
	 *
	 * @var string
	 */
	public const SOURCES_CONFIG_KEY = 'campaign.utm_sources';

	/**
	 * App-config key holding the allowed `utm_medium` values.
	 *
	 * @var string
	 */
	public const MEDIUMS_CONFIG_KEY = 'campaign.utm_mediums';

	/**
	 * The sources a fresh install starts with.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_SOURCES = [
		'email',
		'nieuwsbrief',
		'linkedin',
		'mastodon',
		'bluesky',
		'x',
		'facebook',
		'instagram',
		'google',
		'website',
		'print',
		'beurs',
	];

	/**
	 * The mediums a fresh install starts with, matching the GA4 vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_MEDIUMS = ['email', 'social', 'cpc', 'organic', 'referral', 'display', 'print', 'event'];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param CampaignLinkDecorator $decorator Owns the slug rule a campaign value is minted with.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function __construct(
		private ListObjectStore $store,
		private IAppConfig $appConfig,
		private CampaignLinkDecorator $decorator,
	) {
	}//end __construct()

	/**
	 * The campaign schema slug, honouring an app-config override.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}//end schemaSlug()

	/**
	 * One campaign by id or slug.
	 *
	 * @param string $id Campaign UUID or slug.
	 *
	 * @return array<string, mixed>|null The campaign, or null.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function find(string $id): ?array {
		if (trim($id) === '') {
			return null;
		}

		return $this->store->find(schemaSlug: $this->schemaSlug(), id: trim($id));
	}//end find()

	/**
	 * Every campaign matching a filter set.
	 *
	 * @param array<string, string> $filters Field-value pairs.
	 *
	 * @return array<int, array<string, mixed>> The campaigns.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function all(array $filters = []): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug(), filters: $filters);
	}//end all()

	/**
	 * The campaign a blast belongs to, or an empty array when it belongs
	 * to none. An empty array is the value the link decorator reads as
	 * "keep the per-blast defaults", so a missing campaign never changes
	 * how a blast is decorated.
	 *
	 * @param array<string, mixed> $blast The blast row.
	 *
	 * @return array<string, mixed> The campaign, or an empty array.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-tracked-link-is-minted-from-the-campaign-when-there-is-one
	 */
	public function forBlast(array $blast): array {
		$campaignId = trim((string)($blast['campaignId'] ?? ''));
		if ($campaignId === '') {
			return [];
		}

		return ($this->find(id: $campaignId) ?? []);
	}//end forBlast()

	/**
	 * The allowed source and medium values.
	 *
	 * Both in one call because they are one decision: an administrator
	 * who adds a source almost always adds the medium it arrives on, and
	 * a page that offers one picker without the other is half a form.
	 *
	 * @return array{sources: array<int, string>, mediums: array<int, string>}
	 *         Both vocabularies, lowercase and unique.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function vocabularies(): array {
		return [
			'sources' => $this->vocabulary(configKey: self::SOURCES_CONFIG_KEY, defaults: self::DEFAULT_SOURCES),
			'mediums' => $this->vocabulary(configKey: self::MEDIUMS_CONFIG_KEY, defaults: self::DEFAULT_MEDIUMS),
		];
	}//end vocabularies()

	/**
	 * Mint a campaign value from a name.
	 *
	 * Reuses the link decorator's slug rule so a campaign value and a
	 * blast value can never disagree about what a slug is (ADR-012).
	 *
	 * @param string $name The campaign name.
	 *
	 * @return string The slug, empty when nothing survives.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function mint(string $name): string {
		return $this->decorator->slug(value: $name);
	}//end mint()

	/**
	 * Create or update a campaign.
	 *
	 * On a create the slug is minted from the name. On an update the
	 * stored slug wins over anything the caller sent, so a rename cannot
	 * move it. A source or medium outside the vocabulary is refused with
	 * the offending value and the allowed list named, rather than
	 * silently lowercased: correcting it here would hide a typo that
	 * splits a campaign in two.
	 *
	 * @param array<string, mixed> $payload The campaign fields.
	 * @param string|null $id Existing campaign id when updating.
	 * @param string $uid The user making the change.
	 *
	 * @return array{error: string, value: string, allowed: array<int, string>, campaign: array<string, mixed>|null}
	 *         `error` empty on success.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function save(array $payload, ?string $id = null, string $uid = ''): array {
		$existing = null;
		if ($id !== null && trim($id) !== '') {
			$existing = $this->find(id: $id);
			if ($existing === null) {
				return $this->failure(error: 'not_found', value: (string)$id, allowed: []);
			}
		}

		$name = trim((string)($payload['name'] ?? ($existing['name'] ?? '')));
		if ($name === '') {
			return $this->failure(error: 'name_required', value: '', allowed: []);
		}

		$check = $this->checkVocabulary(payload: $payload);
		if ($check['error'] !== '') {
			return $check;
		}

		$record = array_merge(($existing ?? []), $payload);
		$record['name'] = $name;
		$record['utmCampaign'] = $this->frozenSlug(existing: $existing, name: $name);
		if ($existing === null) {
			$record['createdAt'] = gmdate('Y-m-d\TH:i:sP');
			$record['createdBy'] = $uid;
		}

		unset($record['@self'], $record['id'], $record['uuid'], $record['slug']);

		$saved = $this->store->save(schemaSlug: $this->schemaSlug(), payload: $record, id: $id);
		if ($saved === null) {
			return $this->failure(error: 'write_failed', value: $name, allowed: []);
		}

		return ['error' => '', 'value' => '', 'allowed' => [], 'campaign' => $saved];
	}//end save()

	/**
	 * Record what portaliq answered when this campaign asked for a page.
	 *
	 * @param string $id The campaign id.
	 * @param array<string, mixed> $landingPage `portal`, `route`, `pageId`, `publicUrl`, `createdAt`.
	 * @param string $formId The created form's id.
	 *
	 * @return array<string, mixed>|null The saved campaign, or null when the write failed.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
	 */
	public function recordLandingPage(string $id, array $landingPage, string $formId): ?array {
		$campaign = $this->find(id: $id);
		if ($campaign === null) {
			return null;
		}

		$campaign['landingPage'] = $landingPage;
		$campaign['formRef'] = $formId;
		unset($campaign['@self'], $campaign['id'], $campaign['uuid'], $campaign['slug']);

		return $this->store->save(schemaSlug: $this->schemaSlug(), payload: $campaign, id: $id);
	}//end recordLandingPage()

	/**
	 * The canonical id of a campaign row.
	 *
	 * @param array<string, mixed>|null $campaign The campaign.
	 *
	 * @return string The id, or an empty string.
	 *
	 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
	 */
	public function idOf(?array $campaign): string {
		return $this->store->idOf(payload: $campaign);
	}//end idOf()

	/**
	 * The slug the campaign keeps: the stored one when there is one, a
	 * freshly minted one otherwise.
	 *
	 * @param array<string, mixed>|null $existing The stored campaign.
	 * @param string $name The campaign name.
	 *
	 * @return string The slug.
	 */
	private function frozenSlug(?array $existing, string $name): string {
		$stored = trim((string)($existing['utmCampaign'] ?? ''));
		if ($stored !== '') {
			return $stored;
		}

		return $this->mint(name: $name);
	}//end frozenSlug()

	/**
	 * Check a payload's source and medium against the vocabularies.
	 *
	 * @param array<string, mixed> $payload The campaign fields.
	 *
	 * @return array{error: string, value: string, allowed: array<int, string>, campaign: array<string, mixed>|null}
	 *         `error` empty when both are acceptable.
	 */
	private function checkVocabulary(array $payload): array {
		$vocabularies = $this->vocabularies();
		$checks = [
			['key' => 'utmSource', 'error' => 'unknown_utm_source', 'allowed' => $vocabularies['sources']],
			['key' => 'utmMedium', 'error' => 'unknown_utm_medium', 'allowed' => $vocabularies['mediums']],
		];

		foreach ($checks as $check) {
			$value = trim((string)($payload[$check['key']] ?? ''));
			if ($value === '') {
				continue;
			}

			if (in_array($value, $check['allowed'], true) === false) {
				return $this->failure(error: $check['error'], value: $value, allowed: $check['allowed']);
			}
		}

		return ['error' => '', 'value' => '', 'allowed' => [], 'campaign' => null];
	}//end checkVocabulary()

	/**
	 * A refusal, shaped like every other answer this service gives.
	 *
	 * @param string $error The machine-readable reason.
	 * @param string $value The offending value.
	 * @param array<int, string> $allowed The vocabulary, when there is one.
	 *
	 * @return array{error: string, value: string, allowed: array<int, string>, campaign: array<string, mixed>|null}
	 */
	private function failure(string $error, string $value, array $allowed): array {
		return ['error' => $error, 'value' => $value, 'allowed' => $allowed, 'campaign' => null];
	}//end failure()

	/**
	 * Read a comma-separated vocabulary from app config.
	 *
	 * @param string $configKey The app-config key.
	 * @param array<int, string> $defaults What a fresh install starts with.
	 *
	 * @return array<int, string> Lowercase, trimmed, unique, in order.
	 */
	private function vocabulary(string $configKey, array $defaults): array {
		$raw = trim($this->appConfig->getValueString(Application::APP_ID, $configKey, ''));
		if ($raw === '') {
			return $defaults;
		}

		$values = [];
		foreach (explode(',', $raw) as $part) {
			$value = strtolower(trim($part));
			if ($value !== '' && in_array($value, $values, true) === false) {
				$values[] = $value;
			}
		}

		if ($values === []) {
			return $defaults;
		}

		return $values;
	}//end vocabulary()
}//end class
