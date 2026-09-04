<?php

/**
 * Pipelinq ContactVcardPropertyBuilder.
 *
 * Service for building vCard properties from Pipelinq object data.
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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Service for building vCard properties from Pipelinq object data.
 *
 * @spec openspec/specs/contacts-sync/spec.md#requirement-write-back-sync-mvp
 */
class ContactVcardPropertyBuilder {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Build vCard properties from Pipelinq object data.
	 *
	 * @param array $objData The object data.
	 * @param string $objectType The object type (client or contact).
	 *
	 * @return array The vCard properties.
	 *
	 * @spec openspec/specs/contacts-sync/spec.md
	 */
	public function buildProperties(array $objData, string $objectType): array {
		$name = $objData['name'] ?? 'Unknown';
		$properties = ['FN' => $name];

		$properties = $this->addChannelProperties(properties: $properties, objData: $objData);

		if ($objectType === 'client') {
			$properties = $this->addClientProperties(
				properties: $properties,
				objData: $objData,
				name: $name
			);
		}

		if ($objectType === 'contact') {
			$properties = $this->addContactProperties(
				properties: $properties,
				objData: $objData
			);
		}

		return $properties;
	}//end buildProperties()

	/**
	 * kind/network -> vCard TYPE token used by {@see buildTypedVcardEntries()}
	 * and {@see buildSocialProfileEntries()}. vCard has no native TYPE for
	 * `whatsapp`; it is written as CELL like `mobile`, so it round-trips as
	 * `mobile` on the next import — a documented, accepted limitation (see
	 * design.md, DEFERRED_QUESTIONS). `other`/unmapped kinds omit TYPE.
	 *
	 * @var array<string, string>
	 */
	private const KIND_TO_VCARD_TYPE = [
		'work' => 'WORK',
		'private' => 'HOME',
		'mobile' => 'CELL',
		'whatsapp' => 'CELL',
	];

	/**
	 * Build the EMAIL/TEL/X-SOCIALPROFILE vCard properties from the typed
	 * `emails[]`/`phones[]`/`socialProfiles[]` arrays, falling back to the
	 * legacy scalar `email`/`phone` fields when the corresponding array is
	 * absent or empty (objects not yet carrying the typed arrays, or a
	 * caller — e.g. {@see ContactVcardService::provisionContactFromForm()}
	 * — that only ever populated the scalar fields).
	 *
	 * @param array $properties The vCard properties built so far.
	 * @param array $objData The object data.
	 *
	 * @return array The updated properties.
	 *
	 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md#requirement-write-back-maps-channel-arrays-to-typed-vcard-properties
	 */
	private function addChannelProperties(array $properties, array $objData): array {
		$emailEntries = $this->buildTypedVcardEntries(entries: ($objData['emails'] ?? []));
		if ($emailEntries !== []) {
			$properties['EMAIL'] = $emailEntries;
		} elseif (empty($objData['email']) === false) {
			$properties['EMAIL'] = $objData['email'];
		}

		$phoneEntries = $this->buildTypedVcardEntries(entries: ($objData['phones'] ?? []));
		if ($phoneEntries !== []) {
			$properties['TEL'] = $phoneEntries;
		} elseif (empty($objData['phone']) === false) {
			$properties['TEL'] = $objData['phone'];
		}

		$socialEntries = $this->buildSocialProfileEntries(profiles: ($objData['socialProfiles'] ?? []));
		if ($socialEntries !== []) {
			$properties['X-SOCIALPROFILE'] = $socialEntries;
		}

		return $properties;
	}//end addChannelProperties()

	/**
	 * Build the `[{value, type}, ...]` vCard multi-value shape Nextcloud's
	 * `AddressBookImpl::createOrUpdate()` expects for a typed EMAIL/TEL
	 * list from a Pipelinq `emails[]`/`phones[]` array. Entries with an
	 * empty `value` are skipped; a `kind` with no vCard TYPE mapping omits
	 * the TYPE parameter rather than guessing.
	 *
	 * @param mixed $entries The `emails[]`/`phones[]` array.
	 *
	 * @return array<int, array{value:string, type?:string}> The vCard entries.
	 */
	private function buildTypedVcardEntries(mixed $entries): array {
		if (is_array($entries) === false) {
			return [];
		}

		$out = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$value = (string)($entry['value'] ?? '');
			if ($value === '') {
				continue;
			}

			$vcardEntry = ['value' => $value];
			$vcardType = self::KIND_TO_VCARD_TYPE[(string)($entry['kind'] ?? '')] ?? null;
			if ($vcardType !== null) {
				$vcardEntry['type'] = $vcardType;
			}

			$out[] = $vcardEntry;
		}

		return $out;
	}//end buildTypedVcardEntries()

	/**
	 * Build the `[{value, type}, ...]` vCard multi-value shape for
	 * X-SOCIALPROFILE from a Pipelinq `socialProfiles[]` array. Prefers
	 * the profile `url`, falling back to `handle` when no URL is set; an
	 * entry with neither is skipped. TYPE is the network name verbatim
	 * (already one of our lower-case enum values), so import can match it
	 * straight back without a translation table.
	 *
	 * @param mixed $profiles The `socialProfiles[]` array.
	 *
	 * @return array<int, array{value:string, type?:string}> The vCard entries.
	 */
	private function buildSocialProfileEntries(mixed $profiles): array {
		if (is_array($profiles) === false) {
			return [];
		}

		$out = [];
		foreach ($profiles as $profile) {
			if (is_array($profile) === false) {
				continue;
			}

			$value = (string)($profile['url'] ?? '');
			if ($value === '') {
				$value = (string)($profile['handle'] ?? '');
			}

			if ($value === '') {
				continue;
			}

			$entry = ['value' => $value];
			$network = (string)($profile['network'] ?? '');
			if ($network !== '') {
				$entry['type'] = $network;
			}

			$out[] = $entry;
		}

		return $out;
	}//end buildSocialProfileEntries()

	/**
	 * Add client-specific vCard properties.
	 *
	 * @param array $properties The existing properties.
	 * @param array $objData The object data.
	 * @param string $name The client name.
	 *
	 * @return array The updated properties.
	 */
	private function addClientProperties(array $properties, array $objData, string $name): array {
		if (($objData['type'] ?? '') === 'organization') {
			$properties['ORG'] = $name;
		}

		if (empty($objData['website']) === false) {
			$properties['URL'] = $objData['website'];
		}

		if (empty($objData['address']) === false) {
			$properties['ADR'] = $objData['address'];
		}

		if (empty($objData['notes']) === false) {
			$properties['NOTE'] = $objData['notes'];
		}

		return $properties;
	}//end addClientProperties()

	/**
	 * Add contact-specific vCard properties.
	 *
	 * @param array $properties The existing properties.
	 * @param array $objData The object data.
	 *
	 * @return array The updated properties.
	 */
	private function addContactProperties(array $properties, array $objData): array {
		if (empty($objData['role']) === false) {
			$properties['ROLE'] = $objData['role'];
		}

		if (empty($objData['client']) === false) {
			$orgName = $this->resolveClientName(clientId: $objData['client']);
			if ($orgName !== null) {
				$properties['ORG'] = $orgName;
			}
		}

		return $properties;
	}//end addContactProperties()

	/**
	 * Resolve a client UUID to its name.
	 *
	 * @param string $clientId The client ID to resolve.
	 *
	 * @return ?string The client name or null.
	 */
	private function resolveClientName(string $clientId): ?string {
		$objectService = $this->getObjectService();
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'client_schema', '');

		if ($registerId === '' || $schemaId === '') {
			return null;
		}

		try {
			$client = $objectService->find(
				$clientId,
				[],
				false,
				$registerId,
				$schemaId
			);
			$data = $this->serializeResult(result: $client);
			return $data['name'] ?? null;
		} catch (\Exception $e) {
			return null;
		}
	}//end resolveClientName()

	/**
	 * Serialize an object or array result to an array.
	 *
	 * @param mixed $result The result to serialize.
	 *
	 * @return array The serialized result.
	 */
	private function serializeResult(mixed $result): array {
		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return $result->jsonSerialize();
		}

		if (is_array($result) === true) {
			return $result;
		}

		return [];
	}//end serializeResult()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class
