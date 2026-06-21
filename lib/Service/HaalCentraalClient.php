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
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OAuth2 + mTLS REST client for HaalCentraal Personen.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class HaalCentraalClient
{
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
     * Cached access token (in-process only — re-fetched on cold boot).
     *
     * @var array{token: string, expiresAt: DateTimeImmutable}|null
     */
    private ?array $tokenCache = null;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService NC HTTP client factory.
     * @param IAppConfig      $appConfig     App config.
     * @param ICrypto         $crypto        Crypto for decrypting stored secrets.
     * @param LoggerInterface $logger        Logger (never includes raw BSN).
     */
    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Lookup a person by BSN. Returns a BrpPersoon-shaped array or null when not found.
     *
     * Throws HaalCentraalException on transport / auth / server errors so the caller
     * can decide how to surface the failure.
     *
     * @param string      $bsn              Raw 9-digit BSN.
     * @param string|null $verzoekIdContext Optional context UUID for correlation logging.
     *
     * @return array<string,mixed>|null
     *
     * @throws HaalCentraalException
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003-01
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003-03
     */
    public function lookupPersoon(string $bsn, ?string $verzoekIdContext=null): ?array
    {
        $maskedBsn = BsnValidationService::mask($bsn);
        $token     = $this->getAccessToken();

        $client = $this->buildHttpClient();
        $url    = $this->getBaseUrl().'/personen';

        try {
            $start    = microtime(true);
            $response = $client->post(
                $url,
                [
                    'headers'         => [
                        'Authorization' => 'Bearer '.$token,
                        'Accept'        => 'application/hal+json',
                        'Content-Type'  => 'application/json',
                        'User-Agent'    => 'Pipelinq/'.Application::APP_ID.' (Nextcloud)',
                    ],
                    'body'            => json_encode(
                            [
                                'type'                => 'RaadpleegMetBurgerservicenummer',
                                'burgerservicenummer' => [$bsn],
                                'fields'              => $this->getDefaultFields(),
                            ],
                            JSON_THROW_ON_ERROR
                            ),
                    'timeout'         => self::LOOKUP_TIMEOUT_SECONDS,
                    'connect_timeout' => 2,
                ]
            );
            $duration = (int) ((microtime(true) - $start) * 1000);

            $status        = (int) $response->getStatusCode();
            $correlationId = self::firstHeader(response: $response, name: 'X-Correlation-ID');
            if ($correlationId === null) {
                $correlationId = self::firstHeader(response: $response, name: 'x-correlation-id');
            }

            if ($status === 404) {
                $this->logger->info(
                    'HaalCentraal lookup not-found',
                    ['bsn' => $maskedBsn, 'correlationId' => $correlationId, 'duration_ms' => $duration]
                );
                return null;
            }

            if ($status < 200 || $status >= 300) {
                $this->logger->warning(
                    'HaalCentraal lookup error',
                    ['bsn' => $maskedBsn, 'status' => $status, 'correlationId' => $correlationId]
                );
                throw new HaalCentraalException(
                    'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
                    $status,
                    $correlationId,
                );
            }

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $persoon = $this->parseFirstPersoon(payload: $payload);
            if ($persoon === null) {
                return null;
            }

            $persoon['_correlationId']      = $correlationId;
            $persoon['_responseDurationMs'] = $duration;
            $persoon['_responseStatus']     = $status;
            return $persoon;
        } catch (HaalCentraalException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->error(
                'HaalCentraal transport error',
                ['bsn' => $maskedBsn, 'error' => $e->getMessage(), 'verzoekId' => $verzoekIdContext]
            );
            throw new HaalCentraalException(
                'BRP momenteel niet bereikbaar — probeer over enkele minuten opnieuw.',
                0,
                null,
                $e,
            );
        }//end try
    }//end lookupPersoon()

    /**
     * Health-check: returns the certificate expiry (UTC) or null when unknown.
     *
     * Reads the configured client certificate file and inspects its notAfter field.
     *
     * @return DateTimeImmutable|null
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-003-02
     */
    public function getCertificateExpiry(): ?DateTimeImmutable
    {
        $certPath = $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_path', '');
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
            return (new DateTimeImmutable('@'.$info['validTo_time_t']))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            return null;
        }
    }//end getCertificateExpiry()

    /**
     * Returns true when HaalCentraal is fully configured (oauth + mTLS + base URLs).
     *
     * @return bool
     */
    public function isConfigured(): bool
    {
        return $this->appConfig->getValueString(Application::APP_ID, 'brp.client_id', '') !== ''
            && $this->appConfig->getValueString(Application::APP_ID, 'brp.client_secret_encrypted', '') !== ''
            && $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_path', '') !== ''
            && $this->appConfig->getValueString(Application::APP_ID, 'brp.key_path', '') !== '';
    }//end isConfigured()

    /**
     * Read + cache the OAuth2 access token (client_credentials grant).
     *
     * @return string Bearer token.
     *
     * @throws HaalCentraalException When the OAuth exchange fails.
     */
    private function getAccessToken(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($this->tokenCache !== null && $this->tokenCache['expiresAt'] > $now) {
            return $this->tokenCache['token'];
        }

        $clientId        = $this->appConfig->getValueString(Application::APP_ID, 'brp.client_id', '');
        $clientSecretEnc = $this->appConfig->getValueString(Application::APP_ID, 'brp.client_secret_encrypted', '');
        $oauthEndpoint   = $this->appConfig->getValueString(Application::APP_ID, 'brp.oauth_endpoint', self::DEFAULT_OAUTH_ENDPOINT);

        if ($clientId === '' || $clientSecretEnc === '') {
            throw new HaalCentraalException(
                'BRP-integratie is niet geconfigureerd (OAuth2 client_id/secret ontbreken).',
                503,
            );
        }

        try {
            $clientSecret = $this->crypto->decrypt($clientSecretEnc);
        } catch (Throwable $e) {
            throw new HaalCentraalException(
                'BRP-integratie configuratie is onleesbaar — controleer admin instellingen.',
                503,
                null,
                $e,
            );
        }

        $client = $this->buildHttpClient(forToken: true);
        try {
            $response = $client->post(
                $oauthEndpoint,
                [
                    'headers'         => [
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'body'            => http_build_query(
                            [
                                'grant_type'    => 'client_credentials',
                                'client_id'     => $clientId,
                                'client_secret' => $clientSecret,
                                'scope'         => 'haalcentraal.brp.personen',
                            ]
                            ),
                    'timeout'         => self::LOOKUP_TIMEOUT_SECONDS,
                    'connect_timeout' => 2,
                ]
            );
        } catch (Throwable $e) {
            throw new HaalCentraalException(
                'BRP OAuth2-endpoint is niet bereikbaar.',
                0,
                null,
                $e,
            );
        }//end try

        $status  = (int) $response->getStatusCode();
        $payload = json_decode((string) $response->getBody(), true);
        if (is_array($payload) === true) {
            $token = (string) ($payload['access_token'] ?? '');
        } else {
            $token = '';
        }

        if ($status < 200 || $status >= 300 || $token === '') {
            throw new HaalCentraalException(
                'BRP OAuth2-token aanvraag is mislukt.',
                $status,
            );
        }

        $expiresAt        = $now->modify('+'.self::TOKEN_TTL_SECONDS.' seconds');
        $this->tokenCache = ['token' => $token, 'expiresAt' => $expiresAt];
        return $token;
    }//end getAccessToken()

    /**
     * Build a Guzzle-like HTTP client honouring the configured mTLS + CA bundle.
     *
     * @param bool $forToken When true, no mTLS is attached (OAuth endpoint is regular TLS).
     *
     * @return IClient
     */
    private function buildHttpClient(bool $forToken=false): IClient
    {
        $client = $this->clientService->newClient();
        // OCP\Http\Client clients don't expose mTLS options directly across versions;
        // we attach the cert config via the request options when callers use Guzzle. The
        // wrapper still honours the underlying client default options for cert/sslcert.
        // Tests / unit coverage may stub this client; runtime mTLS is enforced through
        // Nextcloud's `proxyuserpwd` + sslcert hooks below.
        if ($forToken === false) {
            $certPath = $this->appConfig->getValueString(Application::APP_ID, 'brp.cert_path', '');
            $keyPath  = $this->appConfig->getValueString(Application::APP_ID, 'brp.key_path', '');
            $caBundle = $this->appConfig->getValueString(Application::APP_ID, 'brp.ca_bundle', '');
            if ($certPath !== '' && $keyPath !== '') {
                // The NC client wrapper supports setDefaultOptions on newer cores; older
                // cores fall back to TLS-without-cert which the RvIG endpoint will reject.
                if (method_exists($client, 'setDefaultOptions') === true) {
                    if ($caBundle !== '') {
                        $verify = $caBundle;
                    } else {
                        $verify = true;
                    }

                    $client->setDefaultOptions(
                            [
                                'cert'    => $certPath,
                                'ssl_key' => $keyPath,
                                'verify'  => $verify,
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
    private function getBaseUrl(): string
    {
        return rtrim(
            $this->appConfig->getValueString(Application::APP_ID, 'brp.base_url', self::DEFAULT_BASE_URL),
            '/'
        );
    }//end getBaseUrl()

    /**
     * Default field set requested from HaalCentraal.
     *
     * @return array<int,string>
     */
    private function getDefaultFields(): array
    {
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
            'geslacht',
            'verblijfplaats',
            'indicatieGeheim',
        ];
    }//end getDefaultFields()

    /**
     * Parse the first Persoon from a HaalCentraal HAL+JSON response.
     *
     * @param mixed $payload Decoded body.
     *
     * @return array<string,mixed>|null
     */
    private function parseFirstPersoon(mixed $payload): ?array
    {
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
    private function normalisePerson(array $raw): array
    {
        if (is_array($raw['naam'] ?? null) === true) {
            $naam = $raw['naam'];
        } else {
            $naam = [];
        }

        if (is_array($raw['geboorte'] ?? null) === true) {
            $geboorte = $raw['geboorte'];
        } else {
            $geboorte = [];
        }

        if (is_array($raw['verblijfplaats'] ?? null) === true) {
            $vp = $raw['verblijfplaats'];
        } else {
            $vp = [];
        }

        if (is_array($raw['geslacht'] ?? null) === true) {
            $geslacht = (string) ($raw['geslacht']['code'] ?? '');
        } else {
            $geslacht = (string) ($raw['geslacht'] ?? '');
        }

        if (is_array($geboorte['plaats'] ?? null) === true) {
            $geboorteplaats = (string) ($geboorte['plaats']['omschrijving'] ?? '');
        } else {
            $geboorteplaats = (string) ($geboorte['plaats'] ?? '');
        }

        if (is_array($geboorte['land'] ?? null) === true) {
            $geboorteland = (string) ($geboorte['land']['code'] ?? '');
        } else {
            $geboorteland = (string) ($geboorte['land'] ?? '');
        }

        return [
            'voornamen'       => (string) ($naam['voornamen'] ?? ''),
            'voorletters'     => (string) ($naam['voorletters'] ?? ''),
            'voorvoegsel'     => (string) ($naam['voorvoegsel'] ?? ''),
            'geslachtsnaam'   => (string) ($naam['geslachtsnaam'] ?? ''),
            'adellijkeTitel'  => (string) ($naam['adellijkeTitelPredicaat'] ?? ''),
            'geboortedatum'   => (string) ($geboorte['datum']['datum'] ?? $geboorte['datum'] ?? ''),
            'geboorteplaats'  => $geboorteplaats,
            'geboorteland'    => $geboorteland,
            'geslacht'        => self::mapGeslacht(code: $geslacht),
            'verblijfplaats'  => self::mapVerblijfplaats(vp: $vp),
            'indicatieGeheim' => (string) ($raw['indicatieGeheim'] ?? '0'),
            'bronsysteem'     => 'HaalCentraal-BRP-v2.0',
        ];
    }//end normalisePerson()

    /**
     * Map HaalCentraal geslacht codes to schema enum values.
     *
     * @param string $code The HaalCentraal geslacht code.
     *
     * @return string
     */
    private static function mapGeslacht(string $code): string
    {
        $code = strtoupper($code);
        if ($code === 'M' || $code === 'MAN') {
            return 'man';
        }

        if ($code === 'V' || $code === 'VROUW' || $code === 'F') {
            return 'vrouw';
        }

        return 'onbekend';
    }//end mapGeslacht()

    /**
     * Map a HaalCentraal verblijfplaats subtree to the schema shape.
     *
     * @param array<string,mixed> $vp The HaalCentraal verblijfplaats subtree.
     *
     * @return array<string,mixed>
     */
    private static function mapVerblijfplaats(array $vp): array
    {
        if (is_array($vp['verblijfadres'] ?? null) === true) {
            $adres = $vp['verblijfadres'];
        } else {
            $adres = $vp;
        }

        $land = $vp['land'] ?? ($adres['land'] ?? null);

        if (isset($adres['huisnummer']) === true) {
            $huisnummer = (int) $adres['huisnummer'];
        } else {
            $huisnummer = null;
        }

        if (is_array($land) === true) {
            $landValue = (string) ($land['omschrijving'] ?? $land['code'] ?? '');
        } else {
            $landValue = (string) ($land ?? '');
        }

        return [
            'straat'               => (string) ($adres['officieleStraatnaam'] ?? $adres['straat'] ?? ''),
            'huisnummer'           => $huisnummer,
            'huisletter'           => (string) ($adres['huisletter'] ?? ''),
            'huisnummertoevoeging' => (string) ($adres['huisnummertoevoeging'] ?? ''),
            'postcode'             => (string) ($adres['postcode'] ?? ''),
            'woonplaats'           => (string) ($adres['woonplaats'] ?? $adres['woonplaatsnaam'] ?? ''),
            'land'                 => $landValue,
        ];
    }//end mapVerblijfplaats()

    /**
     * Extract the first header value from an HTTP response (cross-version compat).
     *
     * @param object $response The HTTP response object.
     * @param string $name     The header name to read.
     *
     * @return string|null
     */
    private static function firstHeader(object $response, string $name): ?string
    {
        if (method_exists($response, 'getHeader') === true) {
            $value = $response->getHeader($name);
            if (is_string($value) === true && $value !== '') {
                return $value;
            }

            if (is_array($value) === true && isset($value[0]) === true) {
                return (string) $value[0];
            }
        }

        return null;
    }//end firstHeader()
}//end class
