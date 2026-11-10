<?php

/**
 * Pipelinq ZrcClient.
 *
 * Typed client for the ZGW Zaken (ZRC) component. Implements the operations
 * the request-handling layer needs:
 *
 *   - getZaak()         — GET, refresh cached ETag.
 *   - addStatus()       — POST /statussen.
 *   - getStatus()       — GET status URL (used by NRC dispatcher).
 *   - linkInitiator()   — Idempotent rol creation for a pipelinq Contact:
 *                         GET /rollen → POST only when missing.
 *
 * NOTE: the class docblock used to claim that every write operation calls
 * `AcClient::require()` first for the appropriate scope (per REQ-ZGW-006). It
 * does not — no method in this class ever read the injected AcClient, which is
 * why phpstan reported the property as written-but-never-read. The dependency
 * has been removed rather than left as a claim the code does not honour; the
 * scope pre-flight must be reinstated together with its call sites.
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-009
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-010
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
 *
 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
 */
class ZrcClient {
	public const SCOPE_LEZEN = 'zaken.lezen';

	/**
	 * Constructor.
	 *
	 * The AcClient scope cache used to be injected here for pre-flight scope
	 * guards. Those guards live in the transport now, so the dependency was
	 * written and never read — phpstan: "Property ...::$acClient is never read,
	 * only written." Re-add it together with the guard that uses it, not before.
	 *
	 * @param ZgwApiClient $api Base transport.
	 * @param ZgwRegisterAccess $registers Register facade.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/*
	 * NO ZAAK CREATE/UPDATE HERE — THE WRITE BRIDGE LIVES IN OPENCONNECTOR.
	 *
	 * `createZaak()` (POST /zaken + ZgwResourceMapping) and `updateZaak()`
	 * (PATCH with If-Match) stood here with zero callers, and this app's own
	 * shipped configuration says why: `lib/Settings/register.d/80-zgw-api-bridge.json`
	 * documents the ZgwEndpoint schema as "addressed only by the inbound status
	 * path (NrcNotificationListener via ZrcClient::getStatus) and
	 * NrcSubscriptionService; the ZGW write bridge (zaak/document/besluit
	 * creation) is not wired in this app — ZGW writes are routed via the
	 * openconnector ZGW connector instead."
	 *
	 * That is ADR-085: a national-standard surface (ZGW/StUF/DSO) belongs in
	 * openconnector, not in a leaf. Wiring these back would have re-created a
	 * second write path to the same national API from an app that has
	 * deliberately delegated it.
	 */

	/**
	 * GET a zaak and refresh the cached ETag.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $mapping ZgwResourceMapping payload (must include zgwUrl).
	 *
	 * @return array<string, mixed> Zaak body.
	 *
	 * @throws ZgwResourceNotFoundException
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
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
	 * Append a status to a zaak.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $caseMap ZgwResourceMapping for the parent zaak.
	 * @param array<string, mixed> $statusData Body for POST /statussen.
	 *
	 * @return string URL of the created status.
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
	 */
	public function addStatus(array $endpoint, array $caseMap, array $statusData): string {
		$client = $this->requireClient(endpoint: $endpoint);
		$zrcUrl = $this->requireComponentUrl(endpoint: $endpoint, key: 'zrc');

		$body = array_merge(
			['zaak' => (string)($caseMap['zgwUrl'] ?? '')],
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
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
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
	 * @param InitiatorLink $link The endpoint, zaak mapping, contact, roltype and role description.
	 *
	 * @return string Rol URL (existing or newly created).
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
	 */
	public function linkInitiator(InitiatorLink $link): string {
		$run = $this->startRolLink(link: $link);

		$existing = $this->findExistingRol(run: $run);
		if ($existing !== null) {
			return $existing;
		}

		return $this->createRol(run: $run);
	}//end linkInitiator()

	/**
	 * Resolve the client, the ZRC URL and the contact identification for a link.
	 *
	 * @param InitiatorLink $link The link request.
	 *
	 * @return RolLinkRun
	 *
	 * @throws ZgwException When the client or the ZRC URL cannot be resolved.
	 */
	private function startRolLink(InitiatorLink $link): RolLinkRun {
		$client = $this->requireClient(endpoint: $link->endpoint);
		$zrcUrl = $this->requireComponentUrl(endpoint: $link->endpoint, key: 'zrc');
		[$betrType, $ident] = self::contactIdentification(contact: $link->contact);

		return new RolLinkRun(link: $link, client: $client, zrcUrl: $zrcUrl, betrType: $betrType, ident: $ident);
	}//end startRolLink()

	/**
	 * Pre-flight: the URL of a rol on the zaak that already identifies the contact.
	 *
	 * A failed listing is logged and answers null, so the caller falls through to POST.
	 *
	 * @param RolLinkRun $run The link run.
	 *
	 * @return string|null The existing rol URL, or null when there is none.
	 */
	private function findExistingRol(RolLinkRun $run): ?string {
		try {
			return self::matchingRolUrl(rows: $this->listRollen(run: $run), ident: $run->ident);
		} catch (Throwable $e) {
			$this->logger->info(
				'ZGW ZRC: linkInitiator GET /rollen failed (will fall through to POST)',
				['err' => $e->getMessage()]
			);
		}

		return null;
	}//end findExistingRol()

	/**
	 * GET the rollen of the zaak for the contact's betrokkeneType.
	 *
	 * @param RolLinkRun $run The link run.
	 *
	 * @return mixed The result rows (paginated `results` or the bare body).
	 */
	private function listRollen(RolLinkRun $run): mixed {
		$existing = $this->api->callComponent(
			componentUrl: $run->zrcUrl,
			method: 'GET',
			path: '/rollen',
			client: $run->client,
			query: ['zaak' => $run->link->caseUrl(), 'betrokkeneType' => $run->betrType]
		);

		return $existing['body']['results'] ?? $existing['body'];
	}//end listRollen()

	/**
	 * The URL of the first row whose identification overlaps the contact's.
	 *
	 * @param mixed                $rows  The listed rollen.
	 * @param array<string, mixed> $ident The contact's identification.
	 *
	 * @return string|null
	 */
	private static function matchingRolUrl(mixed $rows, array $ident): ?string {
		if (is_array($rows) === false) {
			return null;
		}

		foreach ($rows as $row) {
			$url = self::rolUrlWhenMatching(row: $row, ident: $ident);
			if ($url !== '') {
				return $url;
			}
		}

		return null;
	}//end matchingRolUrl()

	/**
	 * The row's URL when its identification overlaps the contact's, otherwise ''.
	 *
	 * @param mixed                $row   One listed rol.
	 * @param array<string, mixed> $ident The contact's identification.
	 *
	 * @return string
	 */
	private static function rolUrlWhenMatching(mixed $row, array $ident): string {
		if (is_array($row) === false) {
			return '';
		}

		$hit = $row['betrokkeneIdentificatie'] ?? [];
		if (is_array($hit) === false || self::identMatches(a: $hit, b: $ident) === false) {
			return '';
		}

		return (string)($row['url'] ?? '');
	}//end rolUrlWhenMatching()

	/**
	 * POST a new rol for the contact on the zaak.
	 *
	 * @param RolLinkRun $run The link run.
	 *
	 * @return string The created rol URL (Location header, body url, or '').
	 */
	private function createRol(RolLinkRun $run): string {
		$body = [
			'zaak' => $run->link->caseUrl(),
			'betrokkeneType' => $run->betrType,
			'betrokkeneIdentificatie' => $run->ident,
			'roltype' => $run->link->roltypeUrl,
			'roltoelichting' => $run->link->roltoelichting,
		];

		$response = $this->api->callComponent(
			componentUrl: $run->zrcUrl,
			method: 'POST',
			path: '/rollen',
			client: $run->client,
			body: $body
		);

		return (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
	}//end createRol()

	/**
	 * Translate a pipelinq Contact into ZGW betrokkeneType + identification map.
	 *
	 * @param array<string, mixed> $contact Pipelinq Contact payload.
	 *
	 * @return array{0:string,1:array<string,mixed>} [betrokkeneType, ident]
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-002
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
			['statutaireNaam' => (string)($contact['name'] ?? 'Onbekend')],
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
		$mapping['lastSynchronisation'] = self::nowIso();
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
	 * Current ISO 8601 timestamp (UTC).
	 *
	 * @return string
	 */
	private static function nowIso(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
