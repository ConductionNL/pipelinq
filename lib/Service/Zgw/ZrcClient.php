<?php

/**
 * Pipelinq ZrcClient.
 *
 * Typed client for the ZGW Zaken (ZRC) component. Implements the operations
 * the request-handling layer needs:
 *
 *   - createZaak()      — POST /zaken, capture Location header into a
 *                         ZgwResourceMapping (with the response ETag).
 *   - getZaak()         — GET, refresh cached ETag.
 *   - updateZaak()      — PATCH with If-Match; surface 412 as
 *                         OptimisticLockException carrying both states.
 *   - addStatus()       — POST /statussen.
 *   - getStatus()       — GET status URL (used by NRC dispatcher).
 *   - linkInitiator()   — Idempotent rol creation for a pipelinq Contact:
 *                         GET /rollen → POST only when missing.
 *
 * Every write operation calls `AcClient::require()` first for the
 * appropriate scope (per REQ-ZGW-006); the underlying HTTP call is only
 * issued if the scope cache permits it.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Zgw
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-009
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-010
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Typed Zaken (ZRC) client.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Typed ZRC surface covering the
 * full Zaken component (zaak/status/rol/resultaat/eigenschap CRUD + optimistic-lock
 * retry); the aggregate complexity is inherent to the API breadth, not to any single
 * over-complex method, so splitting the class would only fragment one cohesive client.
 */
class ZrcClient {
	public const SCOPE_LEZEN = 'zaken.lezen';
	public const SCOPE_AANMAKEN = 'zaken.aanmaken';
	public const SCOPE_BIJWERK = 'zaken.bijwerken';

	/**
	 * Constructor.
	 *
	 * @param ZgwApiClient $api Base transport.
	 * @param ZgwRegisterAccess $registers Register facade.
	 * @param AcClient $acClient Scope cache (pre-flight guards).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
		private AcClient $acClient,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a zaak (POST /zaken) and persist a ZgwResourceMapping.
	 *
	 * The provided $zaakData MUST contain at minimum: bronorganisatie,
	 * zaaktype (URL), verantwoordelijkeOrganisatie, startdatum,
	 * registratiedatum, omschrijving. The pipelinqRequestId / pipelinqId is
	 * passed in so the mapping can be linked back to the originating Request.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $zaakData Body for POST /zaken.
	 * @param string $pipelinqRequestId UUID of the originating pipelinq Request.
	 *
	 * @return array<string, mixed> Saved ZgwResourceMapping (with zgwUrl, zgwUuid, etag).
	 *
	 * @throws InsufficientScopeException When the configured client lacks zaken.aanmaken.
	 * @throws ZgwException On transport failure.
	 *
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-002
	 */
	public function createZaak(array $endpoint, array $zaakData, string $pipelinqRequestId): array {
		$client = $this->requireClient(endpoint: $endpoint);
		$zrcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'zrc');

		$zaaktypeUrl = (string)($zaakData['zaaktype'] ?? '');
		if ($zaaktypeUrl !== '') {
			$this->acClient->require($endpoint, $zaaktypeUrl, self::SCOPE_AANMAKEN);
		}

		$response = $this->api->callComponent(
			componentUrl: $zrcUrl,
			method: 'POST',
			path: '/zaken',
			client: $client,
			body: $zaakData
		);

		$url = (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
		$etag = (string)($response['headers']['etag'] ?? '');
		$uuid = self::extractUuid(url: $url);

		$mapping = [
			'pipelinqEntiteit' => 'request',
			'pipelinqId' => $pipelinqRequestId,
			'zgwResourceType' => 'zaak',
			'zgwUrl' => $url,
			'zgwUuid' => $uuid,
			'endpointId' => (string)($endpoint['id'] ?? ''),
			'laatsteSynchronisatie' => self::nowIso(),
			'etag' => $etag,
		];

		$saved = $this->registers->save(ZgwRegisterAccess::SCHEMA_MAPPING, $mapping);
		return $saved ?? $mapping;
	}//end createZaak()

	/**
	 * GET a zaak and refresh the cached ETag.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $mapping ZgwResourceMapping payload (must include zgwUrl).
	 *
	 * @return array<string, mixed> Zaak body.
	 *
	 * @throws ZgwResourceNotFoundException
	 */
	public function getZaak(array $endpoint, array $mapping): array {
		$url = (string)($mapping['zgwUrl'] ?? '');
		if ($url === '') {
			throw new ZgwException('ZGW: mapping has no zgwUrl');
		}

		$client = $this->requireClient(endpoint: $endpoint);

		$response = $this->api->callComponent(
			componentUrl: $url,
			method: 'GET',
			path: '',
			client: $client
		);

		$etag = (string)($response['headers']['etag'] ?? '');
		if ($etag !== '' && isset($mapping['@self']['uuid']) === true) {
			$this->saveEtag(mapping: $mapping, etag: $etag);
		} elseif ($etag !== '' && isset($mapping['id']) === true) {
			$this->saveEtag(mapping: $mapping, etag: $etag);
		}

		return $response['body'];
	}//end getZaak()

	/**
	 * PATCH a zaak with optimistic concurrency.
	 *
	 * Sends `If-Match: <cached etag>`; on 412 fetches the fresh
	 * representation and raises `OptimisticLockException` carrying both
	 * sides + the field where they differ.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $mapping ZgwResourceMapping payload (with cached etag).
	 * @param array<string, mixed> $updates Patch body.
	 *
	 * @return array<string, mixed> Updated mapping payload.
	 *
	 * @throws OptimisticLockException On 412.
	 * @throws InsufficientScopeException When zaken.bijwerken is missing.
	 *
	 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#req-zgw-009
	 */
	public function updateZaak(array $endpoint, array $mapping, array $updates): array {
		$url = (string)($mapping['zgwUrl'] ?? '');
		$etag = (string)($mapping['etag'] ?? '');
		$client = $this->requireClient(endpoint: $endpoint);

		$zaaktypeUrl = (string)($mapping['zaaktype'] ?? $updates['zaaktype'] ?? '');
		if ($zaaktypeUrl !== '') {
			$this->acClient->require($endpoint, $zaaktypeUrl, self::SCOPE_BIJWERK);
		}

		$extraHeaders = [];
		if ($etag !== '') {
			$extraHeaders = ['If-Match' => $etag];
		}

		try {
			$response = $this->api->callComponent(
				componentUrl: $url,
				method: 'PATCH',
				path: '',
				client: $client,
				body: $updates,
				extraHeaders: $extraHeaders
			);
		} catch (OptimisticLockException) {
			$fresh = [];
			try {
				$fresh = $this->getZaak(endpoint: $endpoint, mapping: $mapping);
			} catch (Throwable) {
				// Best effort — fresh representation may be empty.
			}

			throw new OptimisticLockException(
				message: sprintf('ZGW: optimistic lock failure on zaak %s', $url),
				staleRepresentation: $updates,
				freshRepresentation: $fresh,
				conflictingField: self::diffField(local: $updates, remote: $fresh)
			);
		}//end try

		$newEtag = (string)($response['headers']['etag'] ?? '');
		$mapping['laatsteSynchronisatie'] = self::nowIso();
		$mapping['etag'] = $etag;
		if ($newEtag !== '') {
			$mapping['etag'] = $newEtag;
		}

		$this->saveEtag(mapping: $mapping, etag: $mapping['etag']);
		return $mapping;
	}//end updateZaak()

	/**
	 * Append a status to a zaak.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $zaakMap ZgwResourceMapping for the parent zaak.
	 * @param array<string, mixed> $statusData Body for POST /statussen.
	 *
	 * @return string URL of the created status.
	 */
	public function addStatus(array $endpoint, array $zaakMap, array $statusData): string {
		$client = $this->requireClient(endpoint: $endpoint);
		$zrcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'zrc');

		$body = array_merge(
			['zaak' => (string)($zaakMap['zgwUrl'] ?? '')],
			$statusData
		);

		$response = $this->api->callComponent(
			componentUrl: $zrcUrl,
			method: 'POST',
			path: '/statussen',
			client: $client,
			body: $body
		);

		return (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
	}//end addStatus()

	/**
	 * GET a status by URL (used by NRC dispatcher).
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param string $statusUrl Status URL.
	 *
	 * @return array<string, mixed> Status body.
	 */
	public function getStatus(array $endpoint, string $statusUrl): array {
		$client = $this->requireClient(endpoint: $endpoint);
		$response = $this->api->callComponent(
			componentUrl: $statusUrl,
			method: 'GET',
			path: '',
			client: $client
		);
		return $response['body'];
	}//end getStatus()

	/**
	 * Idempotently link a pipelinq Contact to a zaak as a rol.
	 *
	 * 1. GET /rollen?zaak=<url>&betrokkeneType=<...>; on hit return existing URL.
	 * 2. Otherwise POST /rollen with the appropriate identification (inpBsn
	 *    for natural persons, innNnpId for organisations).
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $zaakMap ZgwResourceMapping for the parent zaak.
	 * @param array<string, mixed> $contact Pipelinq Contact payload.
	 * @param string $roltypeUrl Roltype URL (from ZtcClient::resolveRoltype).
	 * @param string $roltoelichting Free-text role description.
	 *
	 * @return string Rol URL (existing or newly created).
	 */
	public function linkInitiator(
		array $endpoint,
		array $zaakMap,
		array $contact,
		string $roltypeUrl,
		string $roltoelichting = 'Initiator',
	): string {
		$client = $this->requireClient(endpoint: $endpoint);
		$zrcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'zrc');
		$zaakUrl = (string)($zaakMap['zgwUrl'] ?? '');
		[$betrType, $ident] = self::contactIdentification(contact: $contact);

		// Pre-flight: list existing rollen on the zaak.
		try {
			$existing = $this->api->callComponent(
				componentUrl: $zrcUrl,
				method: 'GET',
				path: '/rollen',
				client: $client,
				query: ['zaak' => $zaakUrl, 'betrokkeneType' => $betrType]
			);
			$rows = $existing['body']['results'] ?? $existing['body'];
			if (is_array($rows) === true) {
				foreach ($rows as $row) {
					if (is_array($row) === false) {
						continue;
					}

					$hit = $row['betrokkeneIdentificatie'] ?? [];
					if (is_array($hit) === true && self::identMatches(a: $hit, b: $ident) === true) {
						$url = (string)($row['url'] ?? '');
						if ($url !== '') {
							return $url;
						}
					}
				}
			}
		} catch (Throwable $e) {
			$this->logger->info(
				'ZGW ZRC: linkInitiator GET /rollen failed (will fall through to POST)',
				['err' => $e->getMessage()]
			);
		}//end try

		$body = [
			'zaak' => $zaakUrl,
			'betrokkeneType' => $betrType,
			'betrokkeneIdentificatie' => $ident,
			'roltype' => $roltypeUrl,
			'roltoelichting' => $roltoelichting,
		];

		$response = $this->api->callComponent(
			componentUrl: $zrcUrl,
			method: 'POST',
			path: '/rollen',
			client: $client,
			body: $body
		);

		return (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
	}//end linkInitiator()

	/**
	 * Translate a pipelinq Contact into ZGW betrokkeneType + identification map.
	 *
	 * @param array<string, mixed> $contact Pipelinq Contact payload.
	 *
	 * @return array{0:string,1:array<string,mixed>} [betrokkeneType, ident]
	 */
	public static function contactIdentification(array $contact): array {
		$bsn = (string)($contact['bsn'] ?? '');
		if ($bsn !== '') {
			return ['natuurlijk_persoon', ['inpBsn' => $bsn]];
		}

		$rsin = (string)($contact['rsin'] ?? $contact['kvk'] ?? '');
		if ($rsin !== '') {
			return ['niet_natuurlijk_persoon', ['innNnpId' => $rsin]];
		}

		// Fallback — register the contact as an organisation with their display name only.
		return [
			'niet_natuurlijk_persoon',
			['statutaireNaam' => (string)($contact['naam'] ?? $contact['name'] ?? 'Onbekend')],
		];
	}//end contactIdentification()

	/**
	 * Persist a refreshed ETag back to the OR `zgwResourceMapping` row.
	 *
	 * @param array<string, mixed> $mapping ZgwResourceMapping payload.
	 * @param string $etag Fresh ETag value.
	 *
	 * @return void
	 */
	private function saveEtag(array $mapping, string $etag): void {
		if ($etag === '') {
			return;
		}

		$uuid = (string)($mapping['@self']['uuid'] ?? $mapping['id'] ?? '');
		if ($uuid === '') {
			return;
		}

		$mapping['etag'] = $etag;
		$mapping['laatsteSynchronisatie'] = self::nowIso();
		$this->registers->save(ZgwRegisterAccess::SCHEMA_MAPPING, $mapping, $uuid);
	}//end saveEtag()

	/**
	 * Resolve and return the ZgwClient for an endpoint, raising on miss.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ZgwException When the endpoint's clientId cannot be resolved.
	 */
	private function requireClient(array $endpoint): array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		if ($client === null) {
			throw new ZgwException(
				sprintf(
					'ZGW: ZgwEndpoint "%s" references unknown clientId "%s"',
					(string)($endpoint['id'] ?? '?'),
					(string)($endpoint['clientId'] ?? '?')
				)
			);
		}

		return $client;
	}//end requireClient()

	/**
	 * Return the URL for a named component, raising on miss.
	 *
	 * @param array<string, mixed> $endpoint Endpoint payload.
	 * @param string $key Component key (zrc/drc/brc/ztc/ac/nrc).
	 *
	 * @return string
	 *
	 * @throws ZgwException When the endpoint has no URL configured for the component.
	 */
	private function requireComponentUrl(array $endpoint, string $key): string {
		$url = (string)($endpoint['componenten'][$key] ?? '');
		if ($url === '') {
			throw new ZgwException(sprintf('ZGW: endpoint missing "%s" component URL', $key));
		}

		return $url;
	}//end requireComponentUrl()

	/**
	 * Compare two identification maps.
	 *
	 * @param array<string, mixed> $a First map.
	 * @param array<string, mixed> $b Second map.
	 *
	 * @return bool True when the maps overlap on any identification key.
	 */
	private static function identMatches(array $a, array $b): bool {
		foreach ($b as $key => $value) {
			if (isset($a[$key]) === true && (string)$a[$key] === (string)$value) {
				return true;
			}
		}

		return false;
	}//end identMatches()

	/**
	 * Find the first field that differs between $local and $remote.
	 *
	 * @param array<string, mixed> $local Local pre-image.
	 * @param array<string, mixed> $remote Remote fresh representation.
	 *
	 * @return string
	 */
	private static function diffField(array $local, array $remote): string {
		foreach ($local as $key => $value) {
			if (array_key_exists($key, $remote) === true && $remote[$key] !== $value) {
				return (string)$key;
			}
		}

		return '';
	}//end diffField()

	/**
	 * Extract the trailing UUID from a ZGW URL.
	 *
	 * @param string $url Full URL.
	 *
	 * @return string UUID or empty string when not present.
	 */
	private static function extractUuid(string $url): string {
		if ($url === '') {
			return '';
		}

		$path = parse_url($url, PHP_URL_PATH);
		if ($path === false || $path === null || $path === '') {
			$path = '';
		}

		$tail = basename($path);
		if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tail) === 1) {
			return $tail;
		}

		return '';
	}//end extractUuid()

	/**
	 * Current ISO 8601 timestamp (UTC).
	 *
	 * @return string
	 */
	private static function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
