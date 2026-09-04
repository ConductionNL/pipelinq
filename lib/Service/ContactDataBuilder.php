<?php

/**
 * Pipelinq ContactDataBuilder.
 *
 * Service for building data arrays from Nextcloud contact data for import.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * Service for building import data arrays from Nextcloud contact data.
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-write-back-sync-mvp
 */
class ContactDataBuilder {
	/**
	 * vCard TYPE parameter (uppercased, single token) -> our `kind` enum
	 * used on `emails[]`/`phones[]`. TYPE tokens with no mapping (or no
	 * TYPE at all) fall back to `other`. There is no vCard-native TYPE for
	 * `whatsapp`, so a WhatsApp number written back to a vCard round-trips
	 * as `mobile` on the next import — a documented, accepted limitation
	 * (see design.md, DEFERRED_QUESTIONS).
	 *
	 * @var array<string, string>
	 */
	private const VCARD_TYPE_TO_KIND = [
		'WORK' => 'work',
		'HOME' => 'private',
		'CELL' => 'mobile',
		'MOBILE' => 'mobile',
		'IPHONE' => 'mobile',
	];

	/**
	 * X-SOCIALPROFILE TYPE values recognised as one of our `network` enum
	 * values. Anything else maps to `other`.
	 *
	 * @var string[]
	 */
	private const SOCIAL_NETWORKS = [
		'linkedin',
		'x',
		'mastodon',
		'bluesky',
		'facebook',
		'instagram',
		'threads',
		'tiktok',
		'youtube',
	];

	/**
	 * Build client data from a Nextcloud contact.
	 *
	 * @param array $ncContact The Nextcloud contact data.
	 * @param string $uid The contact UID.
	 *
	 * @return array The client data ready for saving.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-import-maps-typed-vcard-properties-to-channel-arrays
	 */
	public function buildClientImportData(array $ncContact, string $uid): array {
		$name = $this->extractFirstValue(value: ($ncContact['FN'] ?? 'Unknown'));
		$org = $this->extractFirstValue(value: ($ncContact['ORG'] ?? ''));

		$clientType = $this->determineClientType(name: $name, org: $org);

		if ($name === '' && $org !== '') {
			$name = $org;
		}

		$industry = '';
		if ($clientType === 'person') {
			$industry = $org;
		}

		$emails = $this->extractTypedEntries(value: ($ncContact['EMAIL'] ?? null));
		$phones = $this->extractTypedEntries(value: ($ncContact['TEL'] ?? null));

		$data = [
			'name' => $name,
			'type' => $clientType,
			'email' => $emails[0]['value'] ?? '',
			'phone' => $phones[0]['value'] ?? '',
			'emails' => $emails,
			'phones' => $phones,
			'socialProfiles' => $this->extractSocialProfiles(value: ($ncContact['X-SOCIALPROFILE'] ?? null)),
			'website' => $this->extractFirstValue(value: ($ncContact['URL'] ?? '')),
			'industry' => $industry,
			'contactsUid' => $uid,
		];

		// Scalar values are filtered out when empty; `emails`/`phones`/
		// `socialProfiles` are arrays (never `''`) so array_filter's
		// `!== ''` check always keeps them, including when empty — an
		// explicit "no channels known" is preferable to a silently
		// missing key.
		$data = array_filter($data, fn ($v) => $v !== '');
		$data['name'] = $name;
		$data['type'] = $clientType;

		return $data;
	}//end buildClientImportData()

	/**
	 * Build contact person data from a Nextcloud contact.
	 *
	 * @param array $ncContact The Nextcloud contact data.
	 * @param string $uid The contact UID.
	 * @param ?string $clientId The optional client ID.
	 *
	 * @return array The contact data ready for saving.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-import-maps-typed-vcard-properties-to-channel-arrays
	 */
	public function buildContactImportData(array $ncContact, string $uid, ?string $clientId): array {
		$name = $this->extractFirstValue(value: ($ncContact['FN'] ?? 'Unknown'));

		$emails = $this->extractTypedEntries(value: ($ncContact['EMAIL'] ?? null));
		$phones = $this->extractTypedEntries(value: ($ncContact['TEL'] ?? null));

		$data = [
			'name' => $name,
			'email' => $emails[0]['value'] ?? '',
			'phone' => $phones[0]['value'] ?? '',
			'emails' => $emails,
			'phones' => $phones,
			'socialProfiles' => $this->extractSocialProfiles(value: ($ncContact['X-SOCIALPROFILE'] ?? null)),
			'role' => $this->extractFirstValue(value: ($ncContact['ROLE'] ?? $ncContact['TITLE'] ?? '')),
			'contactsUid' => $uid,
		];

		if ($clientId !== null && $clientId !== '') {
			$data['client'] = $clientId;
		}

		// Same as above: emails/phones/socialProfiles are arrays, so the
		// `!== ''` check always keeps them.
		$data = array_filter($data, fn ($v) => $v !== '');
		$data['name'] = $name;

		return $data;
	}//end buildContactImportData()

	/**
	 * Extract typed channel entries (emails or phones) from a vCard
	 * property value in any of the three shapes IManager may hand back:
	 * a plain string, an untyped array of strings, or (with the `types`
	 * search option) an array of `{type, value}` pairs. The first entry
	 * becomes `primary` — the IManager search surface exposes no vCard
	 * `PREF` ordering, so "first written" is the closest available signal
	 * (see design.md, DEFERRED_QUESTIONS).
	 *
	 * @param mixed $value The vCard property value (EMAIL or TEL).
	 *
	 * @return array<int, array{kind:string,value:string,primary:bool,verified:bool}>
	 *
	 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-import-maps-typed-vcard-properties-to-channel-arrays
	 */
	private function extractTypedEntries(mixed $value): array {
		$items = $this->normaliseToList(value: $value);

		$entries = [];
		foreach ($items as $item) {
			$raw = (string)$item;
			if (is_array($item) === true) {
				$raw = (string)($item['value'] ?? '');
			}

			if ($raw === '') {
				continue;
			}

			$type = '';
			if (is_array($item) === true) {
				$type = (string)($item['type'] ?? '');
			}

			$entries[] = [
				'kind' => $this->mapVcardTypeToKind(type: $type),
				'value' => $raw,
				'primary' => ($entries === []),
				'verified' => false,
			];
		}

		return $entries;
	}//end extractTypedEntries()

	/**
	 * Extract X-SOCIALPROFILE entries into the `socialProfiles[]` shape.
	 * The vCard value is treated as a URL when it looks like one, else as
	 * a bare handle; the TYPE parameter (when present) is matched
	 * case-insensitively against the network enum, falling back to
	 * `other`.
	 *
	 * @param mixed $value The vCard X-SOCIALPROFILE property value.
	 *
	 * @return array<int, array{network:string,handle:string,url:string,verified:bool,followedByUs:bool,followsUs:bool}>
	 *
	 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-import-maps-typed-vcard-properties-to-channel-arrays
	 */
	private function extractSocialProfiles(mixed $value): array {
		$items = $this->normaliseToList(value: $value);

		$profiles = [];
		foreach ($items as $item) {
			$raw = (string)$item;
			if (is_array($item) === true) {
				$raw = (string)($item['value'] ?? '');
			}

			if ($raw === '') {
				continue;
			}

			$typeRaw = '';
			if (is_array($item) === true) {
				$typeRaw = (string)($item['type'] ?? '');
			}

			$typeRaw = strtolower($typeRaw);

			$network = 'other';
			if (in_array($typeRaw, self::SOCIAL_NETWORKS, true) === true) {
				$network = $typeRaw;
			}

			$isUrl = (str_starts_with($raw, 'http://') === true || str_starts_with($raw, 'https://') === true);

			$handle = $raw;
			$url = '';
			if ($isUrl === true) {
				$handle = '';
				$url = $raw;
			}

			$profiles[] = [
				'network' => $network,
				'handle' => $handle,
				'url' => $url,
				'verified' => false,
				'followedByUs' => false,
				'followsUs' => false,
			];
		}

		return $profiles;
	}//end extractSocialProfiles()

	/**
	 * Normalise a vCard property value (string, untyped array, single
	 * `{type,value}` pair, or list of pairs) into a flat list ready for
	 * per-entry extraction.
	 *
	 * @param mixed $value The raw vCard property value.
	 *
	 * @return array<int, mixed> A list of strings and/or `{type,value}` arrays.
	 */
	private function normaliseToList(mixed $value): array {
		if ($value === null || $value === '') {
			return [];
		}

		if (is_array($value) === false) {
			return [$value];
		}

		// A single {type,value} pair looks like ['type' => ..., 'value' =>
		// ...] rather than a list — wrap it so the caller's loop sees one
		// item instead of iterating its 'type'/'value' keys.
		if (array_key_exists('value', $value) === true && array_key_exists(0, $value) === false) {
			return [$value];
		}

		return $value;
	}//end normaliseToList()

	/**
	 * Map a vCard TYPE parameter value (possibly comma-separated, e.g.
	 * `"CELL,VOICE"`) to our `kind` enum, matching the first recognised
	 * token. Falls back to `other` when nothing matches or TYPE is absent.
	 *
	 * @param string $type The raw TYPE parameter value.
	 *
	 * @return string One of the `kind` enum values.
	 */
	private function mapVcardTypeToKind(string $type): string {
		foreach (explode(',', $type) as $part) {
			$kind = self::VCARD_TYPE_TO_KIND[strtoupper(trim($part))] ?? null;
			if ($kind !== null) {
				return $kind;
			}
		}

		return 'other';
	}//end mapVcardTypeToKind()

	/**
	 * Determine the client type based on name and org fields.
	 *
	 * @param string $name The contact name.
	 * @param string $org The organization name.
	 *
	 * @return string The client type (person or organization).
	 */
	private function determineClientType(string $name, string $org): string {
		if ($org !== '' && ($org === $name || $name === '')) {
			return 'organization';
		}

		return 'person';
	}//end determineClientType()

	/**
	 * Extract first value from a vCard property that may be an array or string.
	 *
	 * @param mixed $value The value to extract from.
	 *
	 * @return string The extracted string value.
	 */
	private function extractFirstValue(mixed $value): string {
		if (is_array($value) === true) {
			return (string)($value[0] ?? '');
		}

		return (string)$value;
	}//end extractFirstValue()
}//end class
