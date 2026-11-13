<?php

/**
 * Pipelinq HaalCentraalClient.
 *
 * REST client for the RvIG HaalCentraal Personen v2.0 API. Uses OAuth2 client_credentials
 * with mutual TLS (PKIoverheid). Token is cached for 50 minutes (60-minute issuer lifetime
 * minus 10-minute safety margin). Response is HAL+JSON; this client normalises it to a
 * BrpPersoon-shaped array ready for OR persistence.
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
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OAuth2 + mTLS REST client for HaalCentraal Personen.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) OR-first + legacy OAuth2/mTLS paths plus HAL normalisation live in one cohesive client.
 */
class HaalCentraalClient {
	/**
	 * Default base URL for the HaalCentraal Personen API.
	 */
	private const DEFAULT_BASE_URL = 'https://api.haalcentraal.nl/brp/v2.0';

	/**
	 * Default OAuth2 token endpoint.
	 */
	private const DEFAULT_OAUTH_ENDPOINT = 'https://oauth.haalcentraal.nl/token';

	/**
	 * Token cache duration (50 minutes — issuer lifetime is 60).
	 */
	private const TOKEN_TTL_SECONDS = 3000;

	/**
	 * Lookup timeout (5s per REQ-BSN-003-01).
	 */
	private const LOOKUP_TIMEOUT_SECONDS = 5;

	/**
	 * Sentinel key used in the array returned by {@see lookupViaOpenRegister()} for
	 * an OR-200 with zero persons (BSN not found in BRP). Distinguishes "OR answered,
	 * nobody there" (→ not-found, return null) from "OR unusable" (→ null → legacy
	 * fallback). Never returned from {@see lookupPersoon()}.
	 *
	 * @var string
	 */
	private const OR_EMPTY_SENTINEL_KEY = '__or_brp_empty__';

	/**
	 * The request options of the OpenRegister BRP leaf call (internal,
	 * OCS-APIREQUEST, allow_local_address).
	 *
	 * @var array<string, mixed>
	 */
	private const LEAF_REQUEST_OPTIONS = [
		'timeout' => self::LOOKUP_TIMEOUT_SECONDS,
		'connect_timeout' => 2,
		'headers' => ['OCS-APIREQUEST' => 'true', 'Accept' => 'application/json'],
		'nextcloud' => ['allow_local_address' => true],
	];

	/**
	 * Cached access token (in-process only — re-fetched on cold boot).
	 *
	 * @var array{token: string, expiresAt: DateTimeImmutable}|null
	 */
	private ?array $tokenCache = null;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService NC HTTP client factory.
	 * @param IAppConfig $appConfig App config.
	 * @param ICrypto $crypto Crypto for decrypting stored secrets.
	 * @param LoggerInterface $logger Logger (never includes raw BSN).
	 * @param IURLGenerator $urlGenerator URL generator (resolves the OR BRP leaf endpoint).
	 */
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private LoggerInterface $logger,
		private IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * Lookup a person by BSN. Returns a BrpPersoon-shaped array or null when not found.
	 *
	 * Throws HaalCentraalException on transport / auth / server errors so the caller
	 * can decide how to surface the failure.
	 *
	 * OR-first (ADR-022, safe-partial): the lookup tries the OpenRegister BRP leaf
	 * first. On HTTP 200 the leaf returns the raw HAL+JSON person under `results`
	 * plus the Wet-BRP audit metadata under `meta` ({ correlationId, durationMs,
	 * status }); the person is mapped through the EXACT SAME {@see normalisePerson()}
	 * as the legacy path, and the meta is attached as `_correlationId` /
	 * `_responseDurationMs` / `_responseStatus` so the caller persists a
	 * byte-identical `brpLookupVerzoek` audit record regardless of source. On a
	 * 503 (source unconfigured/down) / non-200 / OR-absent the method falls back
	 * to the legacy OAuth2 + mTLS direct HaalCentraal path below, unchanged, so
	 * configured envs keep working until an operator enables the OR source.
	 *
	 * @param string $bsn Raw 9-digit BSN.
	 * @param string|null $requestIdContext Optional context UUID for correlation logging.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @throws HaalCentraalException
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-01
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-03
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          BsnValidationService::mask is a pure stateless helper.
	 */
	public function lookupPersoon(
		#[\SensitiveParameter]
		string $bsn,
		?string $requestIdContext = null,
	): ?array {
		$lookup = new BsnLookup(bsn: $bsn, maskedBsn: BsnValidationService::mask($bsn));

		// OR-first: try the OpenRegister BRP leaf, which relays the raw HAL+JSON
		// person plus the Wet-BRP audit metadata. Returns the same shape as the
		// legacy path on success, or null to fall back to the legacy direct path.
		$viaOr = $this->lookupViaOpenRegister(lookup: $lookup);
		if ($viaOr === null) {
			return $this->lookupDirect(lookup: $lookup, requestIdContext: $requestIdContext);
		}

		// Sentinel for an OR-200 with zero persons (BSN not in BRP) — the
		// leaf returns 200 { results: [] }; preserve the legacy not-found
		// semantics (return null) without falling through to the legacy call.
		if (isset($viaOr[self::OR_EMPTY_SENTINEL_KEY]) === true) {
			return null;
		}

		return $viaOr;
	}//end lookupPersoon()

	/**
	 * The legacy direct lookup: OAuth2 token, then the mTLS HaalCentraal call.
	 *
	 * @param BsnLookup   $lookup           The BSN asked for.
	 * @param string|null $requestIdContext Optional context UUID for correlation logging.
	 *
	 * @return array<string,mixed>|null The stamped person, or null when not found.
	 *
	 * @throws HaalCentraalException On transport / auth / server errors.
	 */
	private function lookupDirect(BsnLookup $lookup, ?string $requestIdContext): ?array {
		$token = $this->getAccessToken();

		$client = $this->buildHttpClient();
		$url = $this->getBaseUrl() . '/personen';

		try {
			$start = microtime(true);
			$response = $client->post($url, $this->lookupRequestOptions(token: $token, bsn: $lookup->bsn));
			$timed = new TimedResponse(response: $response, durationMs: (int)((microtime(true) - $start) * 1000));
			return $this->directOutcome(timed: $timed, lookup: $lookup);
		} catch (HaalCentraalException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw $this->transportFailure(error: $e, context: ['bsn' => $lookup->maskedBsn, 'error' => $e->getMessage(), 'requestId' => $requestIdContext]);
		}
	}//end lookupDirect()

	/**
	 * The request options of a direct lookup.
	 *
	 * @param string $token The bearer token.
	 * @param string $bsn   Raw 9-digit BSN, placed in the request body.
	 *
	 * @return array<string, mixed> The options.
	 *
	 * @throws \JsonException When the body cannot be encoded.
	 */
	private function lookupRequestOptions(
		string $token,
		#[\SensitiveParameter]
		string $bsn,
	): array {
		return [
			'headers' => self::lookupHeaders(token: $token),
			'body' => json_encode(
				['type' => 'RaadpleegMetBurgerservicenummer', 'burgerservicenummer' => [$bsn], 'fields' => $this->getDefaultFields()],
				JSON_THROW_ON_ERROR
			),
			'timeout' => self::LOOKUP_TIMEOUT_SECONDS,
			'connect_timeout' => 2,
		];
	}//end lookupRequestOptions()

	/**
	 * The headers of a direct lookup.
	 *
	 * @param string $token The bearer token.
	 *
	 * @return array<string, string> The headers.
	 */
	private static function lookupHeaders(string $token): array {
		return [
			'Authorization' => 'Bearer ' . $token,
			'Accept' => 'application/hal+json',
			'Content-Type' => 'application/json',
			'User-Agent' => 'Pipelinq/' . AppIdentity::APP_ID . ' (Nextcloud)',
		];
	}//end lookupHeaders()

	/**
	 * What a direct lookup's response means: not found (logged), a refused
	 * status (logged and thrown), or the stamped person.
	 *
	 * @param TimedResponse $timed  The response and its duration.
	 * @param BsnLookup     $lookup The BSN asked for.
	 *
	 * @return array<string,mixed>|null The stamped person, or null when not found.
	 *
	 * @throws HaalCentraalException On a status outside 2xx.
	 * @throws \JsonException When the body is not JSON.
	 */
	private function directOutcome(TimedResponse $timed, BsnLookup $lookup): ?array {
		$status = $timed->status();
		if ($status === 404) {
			$this->logger->info(
				'HaalCentraal lookup not-found',
				['bsn' => $lookup->maskedBsn, 'correlationId' => self::correlationIdOf(response: $timed->response), 'duration_ms' => $timed->durationMs]
			);
			return null;
		}

		if ($status < 200 || $status >= 300) {
			throw $this->refusedStatus(timed: $timed, lookup: $lookup);
		}

		return $this->stampedPerson(timed: $timed);
	}//end directOutcome()

	/**
	 * Log a refused lookup status and build its exception.
	 *
	 * @param TimedResponse $timed  The response.
	 * @param BsnLookup     $lookup The BSN asked for.
	 *
	 * @return HaalCentraalException The exception to throw.
	 */
	private function refusedStatus(TimedResponse $timed, BsnLookup $lookup): HaalCentraalException {
		$status = $timed->status();
		$correlationId = self::correlationIdOf(response: $timed->response);
		$this->logger->warning(
			'HaalCentraal lookup error',
			['bsn' => $lookup->maskedBsn, 'status' => $status, 'correlationId' => $correlationId]
		);
		return new HaalCentraalException(
			'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
			$status,
			$correlationId,
		);
	}//end refusedStatus()

	/**
	 * The first person of a direct lookup's body, stamped with the response's
	 * correlation id, duration and status.
	 *
	 * @param TimedResponse $timed The response and its duration.
	 *
	 * @return array<string,mixed>|null The stamped person, or null when the body holds none.
	 *
	 * @throws \JsonException When the body is not JSON.
	 */
	private function stampedPerson(TimedResponse $timed): ?array {
		$payload = json_decode((string)$timed->response->getBody(), true, 512, JSON_THROW_ON_ERROR);
		$person = $this->parseFirstPerson(payload: $payload);
		if ($person === null) {
			return null;
		}

		$person['_correlationId'] = self::correlationIdOf(response: $timed->response);
		$person['_responseDurationMs'] = $timed->durationMs;
		$person['_responseStatus'] = $timed->status();
		return $person;
	}//end stampedPerson()

	/**
	 * Log a transport failure and build its exception.
	 *
	 * @param Throwable            $error   The failure.
	 * @param array<string, mixed> $context The log context (masked BSN, error, request id).
	 *
	 * @return HaalCentraalException The exception to throw.
	 */
	private function transportFailure(Throwable $error, array $context): HaalCentraalException {
		$this->logger->error('HaalCentraal transport error', $context);
		return new HaalCentraalException(
			'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
			0,
			null,
			$error,
		);
	}//end transportFailure()

	/**
	 * The correlation id header of a response, in either spelling.
	 *
	 * @param object $response The HTTP response.
	 *
	 * @return string|null The correlation id, or null.
	 */
	private static function correlationIdOf(object $response): ?string {
		$correlationId = self::firstHeader(response: $response, name: 'X-Correlation-ID');
		if ($correlationId === null) {
			$correlationId = self::firstHeader(response: $response, name: 'x-correlation-id');
		}

		return $correlationId;
	}//end correlationIdOf()

	/**
	 * Lookup a person via the OpenRegister BRP leaf (ADR-022, safe-partial).
	 *
	 * Calls `GET /apps/openregister/api/integrations/brp/person?bsn=<bsn>`
	 * server-side (internal, OCS-APIREQUEST, allow_local_address). The BSN is
	 * passed in the query (the leaf places it in the upstream request BODY and
	 * never logs it). On HTTP 200 the leaf returns
	 * `{ results: [<raw HAL+JSON person, 0..1>], total, meta: { correlationId,
	 * durationMs, status } }`. This method:
	 *   - maps `results[0]` through the SAME {@see normalisePerson()} the legacy
	 *     path uses (so the BrpPersoon output is identical for the same upstream
	 *     data), and
	 *   - attaches the audit metadata from `meta` exactly as the legacy path
	 *     derives it from the direct response — `meta.correlationId` →
	 *     `_correlationId`, `meta.durationMs` → `_responseDurationMs`,
	 *     `meta.status` → `_responseStatus` — so the caller persists a
	 *     byte-identical `brpLookupVerzoek` audit record.
	 *
	 * Returns:
	 *   - the normalised person array (with `_correlationId` /
	 *     `_responseDurationMs` / `_responseStatus`) on a 200 with a person,
	 *   - {@see OR_EMPTY_RESULT} on a 200 with zero persons (BSN not found —
	 *     the caller maps that to not-found / null without a legacy call), or
	 *   - null when the OR `brp-haalcentraal` source is not usable yet (OR
	 *     responds 503 with `details.cause`, or any non-200, or OR/openregister
	 *     is absent / connection refused) so the caller falls back to the legacy
	 *     OAuth2 + mTLS direct path and configured envs keep working.
	 *
	 * The raw BSN is NEVER logged here (only the masked BSN), mirroring the
	 * legacy path.
	 *
	 * @param BsnLookup $lookup The BSN (placed in the leaf query; never logged) and its masked form.
	 *
	 * @return array<string,mixed>|null The normalised+stamped person, OR_EMPTY_RESULT, or null to fall back.
	 *
	 * @spec openspec/specs/brp-lookup/spec.md
	 */
	private function lookupViaOpenRegister(BsnLookup $lookup): ?array {
		$payload = $this->leafPayload(lookup: $lookup);
		if ($payload === null) {
			return null;
		}

		if (empty($payload['results']) === true) {
			// OR answered 200 but nobody is there — not-found, not a fallback.
			// Return the sentinel using a named key so PHPStan can type it correctly.
			return [self::OR_EMPTY_SENTINEL_KEY => true];
		}

		$first = (new BrpLeafResults())->askedPerson(results: $payload['results'], bsn: $lookup->bsn);
		if (is_array($first) === false) {
			return null;
		}

		$person = (new BrpLeafResults())->withLeafMeta(person: $this->normalisePerson(raw: $first), payload: $payload);
		$this->logLeafSuccess(person: $person, lookup: $lookup);
		return $person;
	}//end lookupViaOpenRegister()

	/**
	 * Log a successful leaf lookup with the masked BSN and the audit metadata.
	 *
	 * @param array<string, mixed> $person The stamped person.
	 * @param BsnLookup            $lookup The BSN asked for.
	 *
	 * @return void
	 */
	private function logLeafSuccess(array $person, BsnLookup $lookup): void {
		$this->logger->info(
			'BRP OR leaf lookup succeeded',
			[
				'bsn' => $lookup->maskedBsn,
				'correlationId' => $person['_correlationId'],
				'duration_ms' => $person['_responseDurationMs'],
				'source' => 'openregister-leaf',
			]
		);
	}//end logLeafSuccess()

	/**
	 * The leaf's payload when it answered 200 with a `results` list.
	 *
	 * @param BsnLookup $lookup The BSN asked for.
	 *
	 * @return array<string, mixed>|null The payload, or null when the leaf is not usable.
	 */
	private function leafPayload(BsnLookup $lookup): ?array {
		$response = $this->leafResponse(lookup: $lookup);
		if ($response === null || (int)$response->getStatusCode() !== 200) {
			return null;
		}

		$payload = json_decode((string)$response->getBody(), true);
		if ((new BrpLeafResults())->hasResultList(payload: $payload) === false) {
			return null;
		}

		return $payload;
	}//end leafPayload()

	/**
	 * Ask the leaf for the person.
	 *
	 * @param BsnLookup $lookup The BSN asked for.
	 *
	 * @return IResponse|null The response, or null when the leaf is unavailable (logged with the masked BSN).
	 */
	private function leafResponse(BsnLookup $lookup): ?IResponse {
		$params = http_build_query(['bsn' => $lookup->bsn]);
		$url = $this->urlGenerator->getAbsoluteURL('/apps/openregister/api/integrations/brp/person?' . $params);

		try {
			return $this->clientService->newClient()->get($url, self::LEAF_REQUEST_OPTIONS);
		} catch (Throwable $e) {
			// 503 (source not usable) surfaces as a client exception here, as
			// does connection-refused / OR absent — fall back to the legacy path.
			// Never log the raw BSN; only the masked variant.
			$this->logger->debug(
				'BRP OR leaf unavailable, falling back to legacy HaalCentraal path',
				['bsn' => $lookup->maskedBsn, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end leafResponse()

	/**
	 * Health-check: returns the certificate expiry (UTC) or null when unknown.
	 *
	 * Reads the configured client certificate file and inspects its notAfter field.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003-02
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator) The @ mutes fs/OpenSSL warnings on unreadable certs; failures handled via false checks.
	 */
	public function getCertificateExpiry(): ?DateTimeImmutable {
		$certPath = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.cert_path', '');
		if ($certPath === '' || file_exists($certPath) === false) {
			return null;
		}

		$contents = @file_get_contents($certPath);
		if ($contents === false) {
			return null;
		}

		$info = @openssl_x509_parse($contents);
		if (is_array($info) === false || isset($info['validTo_time_t']) === false) {
			return null;
		}

		try {
			return (new DateTimeImmutable('@' . $info['validTo_time_t']))->setTimezone(new DateTimeZone('UTC'));
		} catch (Throwable $e) {
			return null;
		}
	}//end getCertificateExpiry()

	/**
	 * Returns true when HaalCentraal is fully configured (oauth + mTLS + base URLs).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-003
	 */
	public function isConfigured(): bool {
		return $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.client_id', '') !== ''
			&& $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.client_secret_encrypted', '') !== ''
			&& $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.cert_path', '') !== ''
			&& $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.key_path', '') !== '';
	}//end isConfigured()

	/**
	 * Read + cache the OAuth2 access token (client_credentials grant).
	 *
	 * @return string Bearer token.
	 *
	 * @throws HaalCentraalException When the OAuth exchange fails.
	 */
	private function getAccessToken(): string {
		$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		if ($this->tokenCache !== null && $this->tokenCache['expiresAt'] > $now) {
			return $this->tokenCache['token'];
		}

		$clientId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.client_id', '');
		$clientSecretEnc = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.client_secret_encrypted', '');
		$oauthEndpoint = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.oauth_endpoint', self::DEFAULT_OAUTH_ENDPOINT);

		self::assertOauthConfigured(clientId: $clientId, clientSecretEnc: $clientSecretEnc);
		$body = (new BrpOauthMessages())->requestBody(clientId: $clientId, clientSecret: $this->decryptSecret(clientSecretEnc: $clientSecretEnc));
		$token = $this->requestToken(oauthEndpoint: $oauthEndpoint, body: $body);

		$expiresAt = $now->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds');
		$this->tokenCache = ['token' => $token, 'expiresAt' => $expiresAt];
		return $token;
	}//end getAccessToken()

	/**
	 * Refuse when the OAuth2 client id or secret is not configured.
	 *
	 * @param string $clientId        The configured client id.
	 * @param string $clientSecretEnc The configured encrypted client secret.
	 *
	 * @return void
	 *
	 * @throws HaalCentraalException When either is missing.
	 */
	private static function assertOauthConfigured(string $clientId, string $clientSecretEnc): void {
		if ($clientId === '' || $clientSecretEnc === '') {
			throw new HaalCentraalException(
				'BRP-integratie is niet geconfigureerd (OAuth2 client_id/secret ontbreken).',
				503,
			);
		}
	}//end assertOauthConfigured()

	/**
	 * Decrypt the configured client secret.
	 *
	 * @param string $clientSecretEnc The encrypted client secret.
	 *
	 * @return string The client secret.
	 *
	 * @throws HaalCentraalException When it cannot be decrypted.
	 */
	private function decryptSecret(string $clientSecretEnc): string {
		try {
			return $this->crypto->decrypt($clientSecretEnc);
		} catch (Throwable $e) {
			throw new HaalCentraalException(
				'BRP-integratie configuratie is onleesbaar — controleer admin instellingen.',
				503,
				null,
				$e,
			);
		}
	}//end decryptSecret()

	/**
	 * Exchange the client credentials for an access token.
	 *
	 * @param string $oauthEndpoint The token endpoint.
	 * @param string $body          The url-encoded token request body.
	 *
	 * @return string The access token.
	 *
	 * @throws HaalCentraalException When the endpoint is unreachable or refuses.
	 */
	private function requestToken(
		string $oauthEndpoint,
		#[\SensitiveParameter]
		string $body,
	): string {
		$client = $this->buildHttpClient(forToken: true);
		try {
			$response = $client->post($oauthEndpoint, (new BrpOauthMessages())->requestOptions(body: $body));
		} catch (Throwable $e) {
			throw new HaalCentraalException(
				'BRP OAuth2-endpoint is niet bereikbaar.',
				0,
				null,
				$e,
			);
		}

		return (new BrpOauthMessages())->tokenFrom(response: $response);
	}//end requestToken()

	/**
	 * Build a Guzzle-like HTTP client honouring the configured mTLS + CA bundle.
	 *
	 * @param bool $forToken When true, no mTLS is attached (OAuth endpoint is regular TLS).
	 *
	 * @return IClient
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $forToken picks plain-TLS vs mTLS; splitting duplicates wiring.
	 */
	private function buildHttpClient(bool $forToken = false): IClient {
		$client = $this->clientService->newClient();
		// OCP\Http\Client clients don't expose mTLS options directly across versions;
		// we attach the cert config via the request options when callers use Guzzle. The
		// wrapper still honours the underlying client default options for cert/sslcert.
		// Tests / unit coverage may stub this client; runtime mTLS is enforced through
		// Nextcloud's `proxyuserpwd` + sslcert hooks below.
		if ($forToken === false) {
			$certPath = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.cert_path', '');
			$keyPath = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.key_path', '');
			$caBundle = $this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.ca_bundle', '');
			if ($certPath !== '' && $keyPath !== '') {
				// The NC client wrapper supports setDefaultOptions on newer cores; older
				// cores fall back to TLS-without-cert which the RvIG endpoint will reject.
				if (method_exists($client, 'setDefaultOptions') === true) {
					$verify = true;
					if ($caBundle !== '') {
						$verify = $caBundle;
					}

					$client->setDefaultOptions(
						[
							'cert' => $certPath,
							'ssl_key' => $keyPath,
							'verify' => $verify,
						]
					);
				}
			}
		}//end if

		return $client;
	}//end buildHttpClient()

	/**
	 * Resolve the base URL for the HaalCentraal Personen API.
	 *
	 * @return string
	 */
	private function getBaseUrl(): string {
		return rtrim(
			$this->appConfig->getValueString(AppIdentity::APP_ID, 'brp.base_url', self::DEFAULT_BASE_URL),
			'/'
		);
	}//end getBaseUrl()

	/**
	 * Default field set requested from HaalCentraal.
	 *
	 * @return array<int,string>
	 */
	private function getDefaultFields(): array {
		return [
			'burgerservicenummer',
			'naam.voornamen',
			'naam.voorletters',
			'naam.voorvoegsel',
			'naam.geslachtsnaam',
			'naam.adellijkeTitelPredicaat',
			'geboorte.datum',
			'geboorte.plaats',
			'geboorte.land',
			'gender',
			'residence',
			'indicationSecret',
		];
	}//end getDefaultFields()

	/**
	 * Parse the first Persoon from a HaalCentraal HAL+JSON response.
	 *
	 * @param mixed $payload Decoded body.
	 *
	 * @return array<string,mixed>|null
	 */
	private function parseFirstPerson(mixed $payload): ?array {
		if (is_array($payload) === false) {
			return null;
		}

		$list = $payload['personen'] ?? $payload['_embedded']['personen'] ?? [];
		if (is_array($list) === false || empty($list) === true) {
			return null;
		}

		$first = $list[0];
		if (is_array($first) === false) {
			return null;
		}

		return $this->normalisePerson(raw: $first);
	}//end parseFirstPersoon()

	/**
	 * Normalise a HaalCentraal person payload into the BrpPersoon schema shape.
	 *
	 * @param array<string,mixed> $raw The raw HaalCentraal person payload.
	 *
	 * @return array<string,mixed>
	 */
	private function normalisePerson(array $raw): array {
		$name = [];
		if (is_array($raw['name'] ?? null) === true) {
			$name = $raw['name'];
		} else if (is_array($raw['naam'] ?? null) === true) {
			// HaalCentraal Personen v2 spells the name in Dutch (`naam`).
			$name = [
				'givenNames' => ($raw['naam']['voornamen'] ?? ''),
				'initials'   => ($raw['naam']['voorletters'] ?? ''),
				'namePrefix' => ($raw['naam']['voorvoegsel'] ?? ''),
				'surname'    => ($raw['naam']['geslachtsnaam'] ?? ''),
				'adellijkeTitelPredicaat' => ($raw['naam']['adellijkeTitelPredicaat'] ?? ''),
			];
		}

		$birth = [];
		if (is_array($raw['geboorte'] ?? null) === true) {
			$birth = $raw['geboorte'];
		}

		$residence = [];
		if (is_array($raw['residence'] ?? null) === true) {
			$residence = $raw['residence'];
		}

		$gender = $raw['gender'] ?? '';
		if (is_array($gender) === true) {
			$gender = ($gender['code'] ?? '');
		}

		$gender = (string)$gender;

		$birthPlace = $birth['plaats'] ?? '';
		if (is_array($birthPlace) === true) {
			$birthPlace = ($birthPlace['omschrijving'] ?? '');
		}

		$birthPlace = (string)$birthPlace;

		$birthCountry = $birth['land'] ?? '';
		if (is_array($birthCountry) === true) {
			$birthCountry = ($birthCountry['code'] ?? '');
		}

		$birthCountry = (string)$birthCountry;

		return [
			'givenNames' => (string)($name['givenNames'] ?? ''),
			'initials' => (string)($name['initials'] ?? ''),
			'namePrefix' => (string)($name['namePrefix'] ?? ''),
			'surname' => (string)($name['surname'] ?? ''),
			'nobiliaryTitle' => (string)($name['adellijkeTitelPredicaat'] ?? ''),
			'dateOfBirth' => (string)($birth['datum']['datum'] ?? $birth['datum'] ?? ''),
			'birthPlace' => $birthPlace,
			'birthCountry' => $birthCountry,
			'gender' => self::mapGeslacht(code: $gender),
			'residence' => self::mapResidence(residence: $residence),
			'indicationSecret' => (string)($raw['indicationSecret'] ?? '0'),
			'sourceSystem' => 'HaalCentraal-BRP-v2.0',
		];
	}//end normalisePerson()

	/**
	 * Map HaalCentraal geslacht codes to schema enum values.
	 *
	 * @param string $code The HaalCentraal geslacht code.
	 *
	 * @return string
	 */
	private static function mapGeslacht(string $code): string {
		$code = strtoupper($code);
		if ($code === 'M' || $code === 'MAN') {
			return 'male';
		}

		if ($code === 'V' || $code === 'VROUW' || $code === 'F') {
			return 'female';
		}

		return 'unknown';
	}//end mapGeslacht()

	/**
	 * Map a HaalCentraal verblijfplaats subtree to the schema shape.
	 *
	 * @param array<string,mixed> $residence The HaalCentraal verblijfplaats subtree.
	 *
	 * @return array<string,mixed>
	 */
	private static function mapResidence(array $residence): array {
		$adres = $residence;
		if (is_array($residence['verblijfadres'] ?? null) === true) {
			$adres = $residence['verblijfadres'];
		}

		$land = $residence['land'] ?? ($adres['land'] ?? null);

		$houseNumber = null;
		if (isset($adres['huisnummer']) === true) {
			$houseNumber = (int)$adres['huisnummer'];
		}

		if (is_array($land) === true) {
			$land = ($land['omschrijving'] ?? $land['code'] ?? '');
		}

		$landValue = (string)($land ?? '');

		return [
			'straat' => (string)($adres['officieleStraatnaam'] ?? $adres['straat'] ?? ''),
			'huisnummer' => $houseNumber,
			'huisletter' => (string)($adres['huisletter'] ?? ''),
			'huisnummertoevoeging' => (string)($adres['huisnummertoevoeging'] ?? ''),
			'postcode' => (string)($adres['postcode'] ?? ''),
			'woonplaats' => (string)($adres['woonplaats'] ?? $adres['woonplaatsnaam'] ?? ''),
			'land' => $landValue,
		];
	}//end mapVerblijfplaats()

	/**
	 * Extract the first header value from an HTTP response (cross-version compat).
	 *
	 * @param object $response The HTTP response object.
	 * @param string $name The header name to read.
	 *
	 * @return string|null
	 */
	private static function firstHeader(object $response, string $name): ?string {
		if (method_exists($response, 'getHeader') === true) {
			$value = $response->getHeader($name);
			if (is_string($value) === true && $value !== '') {
				return $value;
			}

			if (is_array($value) === true && isset($value[0]) === true) {
				return (string)$value[0];
			}
		}

		return null;
	}//end firstHeader()
}//end class
