<?php

/**
 * Pipelinq NrcSubscriptionService.
 *
 * Manages the lifecycle of an NRC abonnement per ZgwEndpoint:
 *
 *   - registerAbonnement()   — POST /api/v1/abonnement on first activation;
 *                              persist the returned URL + a freshly-minted
 *                              callback bearer token onto a new NrcAbonnement.
 *   - syncAbonnement()       — when the configured kanalen drift from the
 *                              abonnement state, unsubscribe and re-register
 *                              with the new kanalen.
 *   - unregisterAbonnement() — DELETE the abonnement at NRC; mark inactive.
 *
 * The callback bearer is generated locally (32 bytes hex), persisted on the
 * NrcAbonnement as a vault reference, and shared with NRC at registration
 * time so subsequent inbound POSTs to /api/zgw/notificaties/inbox carry it
 * as the `Authorization: Bearer` header (verified by ZgwNotificationController).
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
 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Zgw;

use Throwable;

/**
 * NRC abonnement lifecycle service.
 *
 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
 */
class NrcSubscriptionService {
	/**
	 * Constructor.
	 *
	 * No logger is injected: every failure path in this service raises
	 * NrcSubscriptionFailedException, which the caller logs with its own
	 * context. A logger was injected here and never read — phpstan: "Property
	 * ...::$logger is never read, only written." Re-inject it together with the
	 * first call that actually writes a log line.
	 *
	 * @param ZgwApiClient $api Base transport.
	 * @param ZgwRegisterAccess $registers Register facade.
	 */
	public function __construct(
		private ZgwApiClient $api,
		private ZgwRegisterAccess $registers,
	) {
	}//end __construct()

	/**
	 * Register an abonnement at NRC and persist the NrcAbonnement record.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param AbonnementRequest    $request  The kanalen and the callback URL.
	 *
	 * @return array<string, mixed> Saved NrcAbonnement record.
	 *
	 * @throws NrcSubscriptionFailedException On HTTP failure.
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
	 */
	public function registerAbonnement(array $endpoint, AbonnementRequest $request): array {
		$nrc = new NrcConnection(
			client: $this->requireRegistrationClient(endpoint: $endpoint),
			url: self::requireNrcUrl(endpoint: $endpoint)
		);

		$bearer = $this->generateCallbackBearer();
		$abonnementUrl = $this->postAbonnement(nrc: $nrc, body: $this->callbackBody(request: $request, bearer: $bearer));

		$record = ['endpointId' => (string)($endpoint['id'] ?? ''), 'abonnementUrl' => $abonnementUrl]
			+ $this->storedCallback(request: $request, bearer: $bearer);

		$saved = $this->registers->save(ZgwRegisterAccess::SCHEMA_ABONN, $record);
		return $saved ?? $record;
	}//end registerAbonnement()

	/**
	 * The endpoint's client, refusing a registration when it cannot be resolved.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws NrcSubscriptionFailedException When the endpoint has no resolvable client.
	 */
	private function requireRegistrationClient(array $endpoint): array {
		$client = $this->registers->findClientForEndpoint($endpoint);
		if ($client === null) {
			throw new NrcSubscriptionFailedException(
				sprintf(
					'ZGW NRC: ZgwEndpoint "%s" has no resolvable client',
					(string)($endpoint['id'] ?? '?')
				)
			);
		}

		return $client;
	}//end requireRegistrationClient()

	/**
	 * The endpoint's NRC URL, refusing when it has none.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 *
	 * @return string
	 *
	 * @throws NrcSubscriptionFailedException When the endpoint has no NRC URL.
	 */
	private static function requireNrcUrl(array $endpoint): string {
		$nrcUrl = (string)($endpoint['componenten']['nrc'] ?? '');
		if ($nrcUrl === '') {
			throw new NrcSubscriptionFailedException('ZGW NRC: endpoint missing nrc URL');
		}

		return $nrcUrl;
	}//end requireNrcUrl()

	/**
	 * The body NRC gets: where to post, the bearer to post with, and the kanalen.
	 *
	 * @param AbonnementRequest $request The kanalen and the callback URL.
	 * @param string            $bearer  The callback bearer.
	 *
	 * @return array<string, mixed>
	 */
	private function callbackBody(AbonnementRequest $request, string $bearer): array {
		return [
			'callbackUrl' => $request->callbackUrl,
			'auth' => 'Bearer ' . $bearer,
			'kanalen' => $this->normaliseKanalen(kanalen: $request->kanalen),
		];
	}//end callbackBody()

	/**
	 * POST the abonnement and answer the URL NRC gives it.
	 *
	 * @param NrcConnection        $nrc  The client and NRC URL.
	 * @param array<string, mixed> $body The abonnement body.
	 *
	 * @return string The abonnement URL.
	 *
	 * @throws NrcSubscriptionFailedException When the POST fails or answers no URL.
	 */
	private function postAbonnement(NrcConnection $nrc, array $body): string {
		try {
			$response = $this->api->callComponent(
				componentUrl: $nrc->url,
				method: 'POST',
				path: '/abonnement',
				client: $nrc->client,
				body: $body
			);
		} catch (Throwable $e) {
			throw new NrcSubscriptionFailedException(
				'ZGW NRC: POST /abonnement failed: ' . $e->getMessage(),
				0,
				$e
			);
		}

		return self::requireAbonnementUrl(response: $response);
	}//end postAbonnement()

	/**
	 * The abonnement URL from NRC's answer (Location header, else body url).
	 *
	 * @param array<string, mixed> $response The NRC answer.
	 *
	 * @return string
	 *
	 * @throws NrcSubscriptionFailedException When the answer carries no URL.
	 */
	private static function requireAbonnementUrl(array $response): string {
		$abonnementUrl = (string)($response['headers']['location'] ?? $response['body']['url'] ?? '');
		if ($abonnementUrl === '') {
			throw new NrcSubscriptionFailedException('ZGW NRC: registration returned no abonnement URL');
		}

		return $abonnementUrl;
	}//end requireAbonnementUrl()

	/**
	 * The callback half of the stored NrcAbonnement record, active.
	 *
	 * @param AbonnementRequest $request The kanalen and the callback URL.
	 * @param string            $bearer  The callback bearer.
	 *
	 * @return array<string, mixed>
	 */
	private function storedCallback(AbonnementRequest $request, string $bearer): array {
		return [
			'callbackUrl' => $request->callbackUrl,
			'callbackAuth' => $bearer,
			'kanalen' => $this->normaliseKanalen(kanalen: $request->kanalen),
			'active' => true,
		];
	}//end storedCallback()

	/*
	 * NO syncAbonnement() HERE.
	 *
	 * It reconciled a stored NrcAbonnement against a desired kanalen list by
	 * unregistering and re-registering. Nothing called it — nothing in this app
	 * drives abonnement lifecycle at all; `ZgwNotificationController` only
	 * authorises inbound callbacks against an already-stored record. Reviving
	 * it would have meant building the admin surface that decides when kanalen
	 * drift, which does not exist here.
	 */

	/**
	 * Remove an abonnement at NRC and mark the local record inactive.
	 *
	 * @param array<string, mixed> $endpoint ZgwEndpoint payload.
	 * @param array<string, mixed> $abonnement Current NrcAbonnement record.
	 *
	 * @return void
	 *
	 * @throws NrcSubscriptionFailedException On HTTP failure.
	 *
	 * @spec openspec/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
	 */
	public function unregisterAbonnement(array $endpoint, array $abonnement): void {
		$client = $this->registers->findClientForEndpoint($endpoint);
		if ($client === null) {
			throw new NrcSubscriptionFailedException('ZGW NRC: endpoint has no resolvable client');
		}

		$abonnementUrl = (string)($abonnement['abonnementUrl'] ?? '');
		if ($abonnementUrl !== '') {
			$this->deleteAbonnement(nrc: new NrcConnection(client: $client, url: $abonnementUrl));
		}

		$abonnement['active'] = false;
		$uuid = (string)($abonnement['@self']['uuid'] ?? $abonnement['id'] ?? '');
		$saveUuid = null;
		if ($uuid !== '') {
			$saveUuid = $uuid;
		}

		$this->registers->save(ZgwRegisterAccess::SCHEMA_ABONN, $abonnement, $saveUuid);
	}//end unregisterAbonnement()

	/**
	 * DELETE an abonnement at NRC.
	 *
	 * @param NrcConnection $nrc The client and the abonnement URL (as the component URL).
	 *
	 * @return void
	 *
	 * @throws NrcSubscriptionFailedException When the DELETE fails.
	 */
	private function deleteAbonnement(NrcConnection $nrc): void {
		try {
			$this->api->callComponent(
				componentUrl: $nrc->url,
				method: 'DELETE',
				path: '',
				client: $nrc->client
			);
		} catch (Throwable $e) {
			throw new NrcSubscriptionFailedException(
				'ZGW NRC: DELETE failed: ' . $e->getMessage(),
				0,
				$e
			);
		}
	}//end deleteAbonnement()

	/**
	 * Generate a fresh 256-bit callback bearer token.
	 *
	 * @return string Hex-encoded random token.
	 * @spec openspec/specs/vng-klantinteracties-leaf/spec.md
	 */
	public function generateCallbackBearer(): string {
		return bin2hex(random_bytes(32));
	}//end generateCallbackBearer()

	/**
	 * Normalise the kanalen list (drop empties, sort for deterministic compare).
	 *
	 * @param array<int, array<string, mixed>> $kanalen Raw list.
	 *
	 * @return array<int, array{name:string,filters:array<string,mixed>}>
	 */
	private function normaliseKanalen(array $kanalen): array {
		$out = [];
		foreach ($kanalen as $entry) {
			$name = (string)($entry['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$filters = $entry['filters'] ?? [];
			if (is_array($filters) === false) {
				$filters = [];
			}

			ksort($filters);
			$out[] = ['name' => $name, 'filters' => $filters];
		}

		usort($out, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
		return $out;
	}//end normaliseKanalen()
}//end class
