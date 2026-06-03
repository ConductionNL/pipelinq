<?php

/**
 * Pipelinq HaalCentraalClient.
 *
 * Concrete BRP client for the RvIG HaalCentraal Personen API v2.0 over OAuth2
 * client-credentials + mutual TLS. All credentials come from encrypted app
 * config (the client_secret via {@see ICrypto}, the same primitive OpenRegister
 * uses for its source vault) and certificate file paths — NOTHING is hardcoded
 * (ADR-005). The BSN is sent in the request BODY, never the URL, and is never
 * logged or embedded in a thrown message (REQ-BSN-009).
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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Bsn\BrpClientInterface;
use OCA\Pipelinq\Service\Bsn\BrpPersoon;
use OCA\Pipelinq\Service\Bsn\BsnMasker;
use OCA\Pipelinq\Service\Bsn\HaalCentraalException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

/**
 * HaalCentraal Personen REST client (REQ-BSN-003).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires exactly the platform
 *  collaborators an mTLS OAuth2 client needs (HTTP client, encrypted config,
 *  token cache, logger); none are incidental.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
 */
class HaalCentraalClient implements BrpClientInterface
{
    /**
     * Cache key under which the OAuth2 access token is held.
     *
     * @var string
     */
    private const TOKEN_CACHE_KEY = 'brp_oauth_token';

    /**
     * Token cache lifetime in seconds (token expires at 60 min; refresh at 50).
     *
     * @var int
     */
    private const TOKEN_TTL = 3000;

    /**
     * Request timeout in seconds (REQ-BSN-003: error surfaced past ~5s).
     *
     * @var int
     */
    private const REQUEST_TIMEOUT = 5;

    /**
     * Lazily-created token cache.
     *
     * @var ICache|null
     */
    private ?ICache $cache = null;

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The Nextcloud HTTP client service.
     * @param IAppConfig      $appConfig     The app config (endpoints + cert paths).
     * @param ICrypto         $crypto        The authenticated-encryption primitive.
     * @param ICacheFactory   $cacheFactory  The cache factory for token caching.
     * @param LoggerInterface $logger        The logger (BSN-masked only).
     */
    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private ICrypto $crypto,
        private ICacheFactory $cacheFactory,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Read a plain (non-secret) BRP config value.
     *
     * @param string $key     The config key suffix (e.g. brp.oauth_endpoint).
     * @param string $default The default value.
     *
     * @return string The configured value.
     */
    private function config(string $key, string $default=''): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
    }//end config()

    /**
     * Resolve the decrypted OAuth2 client secret from encrypted app config.
     *
     * @return string The plaintext secret, or '' when unprovisioned.
     */
    private function clientSecret(): string
    {
        $stored = $this->appConfig->getValueString(Application::APP_ID, 'brp.client_secret', '');
        if ($stored === '') {
            return '';
        }

        try {
            return $this->crypto->decrypt($stored);
        } catch (\Throwable $e) {
            $this->logger->error('HaalCentraalClient: failed to decrypt client secret');
            return '';
        }
    }//end clientSecret()

    /**
     * {@inheritDoc}
     *
     * @return bool True when endpoint, credentials and certificate are present.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
     */
    public function isConfigured(): bool
    {
        return $this->config(key: 'brp.oauth_endpoint') !== ''
            && $this->config(key: 'brp.personen_endpoint') !== ''
            && $this->config(key: 'brp.client_id') !== ''
            && $this->clientSecret() !== ''
            && $this->config(key: 'brp.cert_path') !== ''
            && $this->config(key: 'brp.key_path') !== '';
    }//end isConfigured()

    /**
     * Build the mTLS / verification options shared by token and lookup requests.
     *
     * @return array<string, mixed> The HTTP client SSL options.
     */
    private function tlsOptions(): array
    {
        $options  = [];
        $certPath = $this->config(key: 'brp.cert_path');
        $keyPath  = $this->config(key: 'brp.key_path');
        $caBundle = $this->config(key: 'brp.ca_bundle');

        if ($certPath !== '') {
            $options['cert'] = $certPath;
        }

        if ($keyPath !== '') {
            $options['ssl_key'] = $keyPath;
        }

        // Always verify the server certificate; pin to the configured CA bundle
        // when provided, otherwise use the system trust store.
        $options['verify'] = true;
        if ($caBundle !== '') {
            $options['verify'] = $caBundle;
        }

        return $options;
    }//end tlsOptions()

    /**
     * Get the token cache, creating it on first use.
     *
     * @return ICache The token cache.
     */
    private function tokenCache(): ICache
    {
        if ($this->cache === null) {
            $this->cache = $this->cacheFactory->createLocal('pipelinq_brp');
        }

        return $this->cache;
    }//end tokenCache()

    /**
     * Obtain a (cached) OAuth2 access token via the client-credentials grant.
     *
     * @return string The bearer access token.
     *
     * @throws HaalCentraalException When the token request fails.
     */
    private function getAccessToken(): string
    {
        $cached = $this->tokenCache()->get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) === true && $cached !== '') {
            return $cached;
        }

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $this->config(key: 'brp.oauth_endpoint'),
                array_merge(
                    $this->tlsOptions(),
                    [
                        'headers' => ['Accept' => 'application/json'],
                        'body'    => [
                            'grant_type'    => 'client_credentials',
                            'client_id'     => $this->config(key: 'brp.client_id'),
                            'client_secret' => $this->clientSecret(),
                        ],
                        'timeout' => self::REQUEST_TIMEOUT,
                    ]
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error('HaalCentraalClient: OAuth2 token request failed');
            throw new HaalCentraalException(
                message: 'Kon geen toegang krijgen tot de BRP-koppeling.',
                statusCode: 0,
                previous: $e
            );
        }//end try

        $decoded = json_decode((string) $response->getBody(), true);
        $token   = '';
        if (is_array($decoded) === true && isset($decoded['access_token']) === true) {
            $token = (string) $decoded['access_token'];
        }

        if ($token === '') {
            throw new HaalCentraalException(message: 'Ongeldig antwoord van de BRP-autorisatieserver.', statusCode: 0);
        }

        $this->tokenCache()->set(self::TOKEN_CACHE_KEY, $token, self::TOKEN_TTL);

        return $token;
    }//end getAccessToken()

    /**
     * {@inheritDoc}
     *
     * @param string $bsn The 9-digit BSN (raw; never logged or placed in a URL).
     *
     * @return BrpPersoon|null The normalised person, or null when not found.
     *
     * @throws HaalCentraalException When the lookup fails.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.2
     */
    public function lookupPersoon(string $bsn): ?BrpPersoon
    {
        if ($this->isConfigured() === false) {
            throw new HaalCentraalException(message: 'BRP-koppeling is niet geconfigureerd.', statusCode: 0);
        }

        $token = $this->getAccessToken();

        try {
            $client   = $this->clientService->newClient();
            $response = $client->post(
                $this->config(key: 'brp.personen_endpoint'),
                array_merge(
                    $this->tlsOptions(),
                    [
                        'headers' => [
                            'Authorization' => 'Bearer '.$token,
                            'Accept'        => 'application/json',
                            'Content-Type'  => 'application/json',
                        ],
                        // BSN travels in the request body, never the URL (REQ-BSN-009).
                        'body'    => json_encode(
                            [
                                'type'                => 'RaadpleegMetBurgerservicenummer',
                                'burgerservicenummer' => [$bsn],
                                'fields'              => $this->requestedFields(),
                            ]
                        ),
                        'timeout' => self::REQUEST_TIMEOUT,
                    ]
                )
            );
        } catch (\Throwable $e) {
            $status = $this->extractStatus(e: $e);
            // A 404 is a clean "not found", not an error condition.
            if ($status === 404) {
                return null;
            }

            $this->logger->warning(
                'HaalCentraalClient: BRP lookup failed',
                ['bsn' => BsnMasker::mask($bsn), 'status' => $status]
            );
            throw new HaalCentraalException(message: 'De BRP is momenteel niet bereikbaar.', statusCode: $status, previous: $e);
        }//end try

        $decoded = json_decode((string) $response->getBody(), true);
        $persoon = null;
        if (is_array($decoded) === true) {
            $persoon = ($decoded['personen'][0] ?? null);
        }

        if (is_array($persoon) === false) {
            return null;
        }

        return $this->normalize(data: $persoon, bsn: $bsn);
    }//end lookupPersoon()

    /**
     * The set of BRP fields requested (data-minimisation, AVG art. 5).
     *
     * @return string[] The requested field paths.
     */
    private function requestedFields(): array
    {
        return [
            'naam',
            'geboorte',
            'geslacht',
            'verblijfplaats',
            'geheimhoudingPersoonsgegevens',
        ];
    }//end requestedFields()

    /**
     * Best-effort extraction of an HTTP status code from a client throwable.
     *
     * @param \Throwable $e The thrown error.
     *
     * @return int The status code, or 0 when not determinable.
     */
    private function extractStatus(\Throwable $e): int
    {
        if (method_exists($e, 'getResponse') === true) {
            $response = $e->getResponse();
            if (is_object($response) === true && method_exists($response, 'getStatusCode') === true) {
                return (int) $response->getStatusCode();
            }
        }

        $code = $e->getCode();
        if (is_int($code) === true && $code >= 100 && $code < 600) {
            return $code;
        }

        return 0;
    }//end extractStatus()

    /**
     * Normalise a HaalCentraal person payload into a {@see BrpPersoon}.
     *
     * @param array<string, mixed> $data The raw `personen[0]` payload.
     * @param string               $bsn  The looked-up BSN (for the masked field).
     *
     * @return BrpPersoon The normalised person.
     */
    private function normalize(array $data, string $bsn): BrpPersoon
    {
        $naam       = (array) ($data['naam'] ?? []);
        $geboorte   = (array) ($data['geboorte'] ?? []);
        $geboortedt = (array) ($geboorte['datum'] ?? []);
        $plaats     = (array) ($geboorte['plaats'] ?? []);
        $land       = (array) ($geboorte['land'] ?? []);
        $geslacht   = (array) ($data['geslacht'] ?? []);
        $geheim     = (array) ($data['geheimhoudingPersoonsgegevens'] ?? []);

        $indicatieGeheim = '0';
        if (($geheim['indicatieGeheim'] ?? false) === true || (string) ($geheim['waarde'] ?? '0') === '1') {
            $indicatieGeheim = '1';
        }

        return new BrpPersoon(
            bsnGemaskeerd: BsnMasker::mask($bsn),
            voornamen: (string) ($naam['voornamen'] ?? ''),
            geslachtsnaam: (string) ($naam['geslachtsnaam'] ?? ''),
            geboortedatum: (string) ($geboortedt['datum'] ?? ''),
            geslacht: (string) ($geslacht['omschrijving'] ?? 'onbekend'),
            indicatieGeheim: $indicatieGeheim,
            verblijfplaats: $this->normalizeAddress(vp: (array) ($data['verblijfplaats'] ?? [])),
            voorletters: $this->nullableString(value: $naam['voorletters'] ?? null),
            voorvoegsel: $this->nullableString(value: $naam['voorvoegsel'] ?? null),
            adellijkeTitel: $this->nullableString(value: $naam['adellijkeTitelPredicaat']['omschrijvingAdellijkeTitel'] ?? null),
            geboorteplaats: $this->nullableString(value: $plaats['omschrijving'] ?? null),
            geboorteland: $this->nullableString(value: $land['omschrijving'] ?? null),
        );
    }//end normalize()

    /**
     * Normalise the BRP verblijfplaats sub-object.
     *
     * @param array<string, mixed> $vp The raw verblijfplaats payload.
     *
     * @return array<string, mixed> The normalised address.
     */
    private function normalizeAddress(array $vp): array
    {
        $adres = (array) ($vp['verblijfadres'] ?? $vp);

        return [
            'straat'               => (string) ($adres['officieleStraatnaam'] ?? $adres['straat'] ?? ''),
            'huisnummer'           => ($adres['huisnummer'] ?? null),
            'huisletter'           => $this->nullableString(value: $adres['huisletter'] ?? null),
            'huisnummertoevoeging' => $this->nullableString(value: $adres['huisnummertoevoeging'] ?? null),
            'postcode'             => (string) ($adres['postcode'] ?? ''),
            'woonplaats'           => (string) ($adres['woonplaats'] ?? ''),
            'land'                 => (string) ($adres['land']['omschrijving'] ?? 'Nederland'),
        ];
    }//end normalizeAddress()

    /**
     * Coerce a value to a trimmed non-empty string, or null.
     *
     * @param mixed $value The raw value.
     *
     * @return string|null The string, or null when empty.
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        return $string;
    }//end nullableString()
}//end class
