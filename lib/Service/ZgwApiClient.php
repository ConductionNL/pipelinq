<?php

/**
 * Pipelinq ZgwApiClient.
 *
 * Base client for all ZGW (zaakgericht-werken) component calls. Mints a fresh
 * HS256 JWT per request following the VNG-API-Common authentication profile (no
 * token caching) and performs the outbound HTTP call with Bearer auth, mapping
 * transport/HTTP faults onto the bridge's domain exceptions.
 *
 * ZgwClient and ZgwEndpoint are passed as OpenRegister object arrays (the shape
 * the bridge persists and reads via ObjectService) rather than bespoke entities.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\Exception\ClockSkewException;
use OCA\Pipelinq\Exception\InsufficientScopeException;
use OCA\Pipelinq\Exception\ZgwBridgeException;
use OCA\Pipelinq\Exception\ZgwResourceNotFoundException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use Psr\Log\LoggerInterface;

/**
 * Base ZGW component HTTP client with per-request JWT minting.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#2.1
 */
class ZgwApiClient
{
    /**
     * Default JWT lifetime in seconds when a client does not override it.
     *
     * @var int
     */
    private const DEFAULT_TOKEN_LIFETIME = 3600;

    /**
     * Constructor.
     *
     * @param IClientService    $clientService  The Nextcloud HTTP client service.
     * @param ZgwSecretResolver $secretResolver Resolves the client secret from its vault reference.
     * @param LoggerInterface   $logger         The logger.
     */
    public function __construct(
        private IClientService $clientService,
        private ZgwSecretResolver $secretResolver,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Base64url-encode without padding (per RFC 7515).
     *
     * @param string $data The raw bytes.
     *
     * @return string The base64url string.
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }//end base64UrlEncode()

    /**
     * Mint a per-request HS256 JWT for a ZgwClient (VNG-API-Common profile).
     *
     * The token carries iss, client_id, user_id, user_representation, iat and
     * exp. No leeway is added on the minting side; the receiving component allows
     * ±60s per the VNG profile.
     *
     * @param array<string, mixed> $client    The ZgwClient object array.
     * @param int|null             $expiresIn Optional lifetime override in seconds.
     *
     * @return string The signed JWT.
     *
     * @throws ZgwBridgeException When the client secret cannot be resolved.
     */
    public function mintJwt(array $client, ?int $expiresIn=null): string
    {
        $clientIdentifier = (string) ($client['clientIdentifier'] ?? '');
        $secretRef        = (string) ($client['secretKluisRef'] ?? '');
        $secret           = $this->secretResolver->resolve($secretRef);

        if ($clientIdentifier === '' || $secret === null) {
            throw new ZgwBridgeException(
                message: 'ZGW-client is niet volledig geconfigureerd: clientIdentifier of secret (kluis) ontbreekt.'
            );
        }

        $lifetime = $expiresIn;
        if ($lifetime === null) {
            $lifetime = (int) ($client['tokenLevensduurSeconden'] ?? self::DEFAULT_TOKEN_LIFETIME);
        }

        if ($lifetime <= 0) {
            $lifetime = self::DEFAULT_TOKEN_LIFETIME;
        }

        $issuedAt = time();
        $header   = ['typ' => 'JWT', 'alg' => 'HS256'];
        $payload  = [
            'iss'                 => $clientIdentifier,
            'client_id'           => $clientIdentifier,
            'user_id'             => (string) ($client['userId'] ?? ''),
            'user_representation' => (string) ($client['userRepresentation'] ?? ''),
            'iat'                 => $issuedAt,
            'exp'                 => ($issuedAt + $lifetime),
        ];

        $segments     = [
            $this->base64UrlEncode(data: (string) json_encode($header)),
            $this->base64UrlEncode(data: (string) json_encode($payload)),
        ];
        $signingInput = implode('.', $segments);
        $signature    = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[]   = $this->base64UrlEncode(data: $signature);

        return implode('.', $segments);
    }//end mintJwt()

    /**
     * Call a ZGW component endpoint with a freshly minted Bearer JWT.
     *
     * @param string                    $componentUrl The component base URL (no trailing slash required).
     * @param string                    $method       HTTP method (GET/POST/PATCH/PUT/DELETE).
     * @param string                    $path         The path or absolute URL of the resource.
     * @param array<string, mixed>      $client       The ZgwClient object array.
     * @param array<string, mixed>|null $body         Optional JSON body for POST/PATCH/PUT.
     * @param array<string, string>     $extraHeaders Optional extra headers (e.g. If-Match).
     *
     * @return array{status:int, body:array<string, mixed>, headers:array<string, mixed>, etag:string} The response.
     *
     * @throws ClockSkewException         On a JWT-timing 403 (no auto-retry).
     * @throws InsufficientScopeException On a 403 scope rejection.
     * @throws ZgwResourceNotFoundException On a 404.
     * @throws ZgwBridgeException         On other transport/HTTP faults.
     */
    public function callComponent(
        string $componentUrl,
        string $method,
        string $path,
        array $client,
        ?array $body=null,
        array $extraHeaders=[]
    ): array {
        $jwt = $this->mintJwt(client: $client);
        $url = $this->buildUrl(componentUrl: $componentUrl, path: $path);

        $headers = array_merge(
            [
                'Authorization' => 'Bearer '.$jwt,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            $extraHeaders
        );

        $options = [
            'headers'     => $headers,
            'timeout'     => 30,
            // Let us branch on 403/404/412 status codes instead of the client
            // throwing before we can inspect the response.
            'http_errors' => false,
        ];

        if ($body !== null) {
            $options['body'] = (string) json_encode($body);
        }

        try {
            $httpClient = $this->clientService->newClient();
            $response   = $this->dispatch(httpClient: $httpClient, method: strtoupper($method), url: $url, options: $options);
        } catch (\Throwable $e) {
            $this->logger->error(
                'ZgwApiClient: transport failure',
                ['url' => $url, 'method' => $method, 'exception' => $e->getMessage()]
            );
            throw new ZgwBridgeException(message: 'ZGW-aanroep mislukt (transport): '.$e->getMessage(), code: 0, previous: $e);
        }

        return $this->interpret(response: $response, url: $url, method: $method);
    }//end callComponent()

    /**
     * Join a component base URL and a path/absolute URL.
     *
     * @param string $componentUrl The component base URL.
     * @param string $path         The path or absolute URL.
     *
     * @return string The full URL.
     */
    private function buildUrl(string $componentUrl, string $path): string
    {
        if (str_starts_with($path, 'http://') === true || str_starts_with($path, 'https://') === true) {
            return $path;
        }

        return rtrim($componentUrl, '/').'/'.ltrim($path, '/');
    }//end buildUrl()

    /**
     * Dispatch the HTTP request by method.
     *
     * @param \OCP\Http\Client\IClient $httpClient The HTTP client.
     * @param string                   $method     The upper-cased HTTP method.
     * @param string                   $url        The full URL.
     * @param array<string, mixed>     $options    The request options.
     *
     * @return IResponse The response.
     */
    private function dispatch(\OCP\Http\Client\IClient $httpClient, string $method, string $url, array $options): IResponse
    {
        return match ($method) {
            'POST'   => $httpClient->post($url, $options),
            'PATCH'  => $httpClient->patch($url, $options),
            'PUT'    => $httpClient->put($url, $options),
            'DELETE' => $httpClient->delete($url, $options),
            default  => $httpClient->get($url, $options),
        };
    }//end dispatch()

    /**
     * Interpret a ZGW HTTP response, mapping fault codes to domain exceptions.
     *
     * @param IResponse $response The HTTP response.
     * @param string    $url      The requested URL (for error context).
     * @param string    $method   The HTTP method (for error context).
     *
     * @return array{status:int, body:array<string, mixed>, headers:array<string, mixed>, etag:string} The result.
     *
     * @throws ClockSkewException         On a JWT-timing 403.
     * @throws InsufficientScopeException On a 403 scope rejection.
     * @throws ZgwResourceNotFoundException On a 404.
     * @throws ZgwBridgeException         On other >=400 responses.
     */
    private function interpret(IResponse $response, string $url, string $method): array
    {
        $status  = $response->getStatusCode();
        $rawBody = (string) $response->getBody();
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded) === false) {
            $decoded = [];
        }

        $etag = $response->getHeader('ETag');

        if ($status >= 200 && $status < 300) {
            return [
                'status'  => $status,
                'body'    => $decoded,
                'headers' => $response->getHeaders(),
                'etag'    => $etag,
            ];
        }

        if ($status === 403) {
            $this->raiseForbidden(body: $decoded, url: $url);
        }

        if ($status === 404) {
            throw new ZgwResourceNotFoundException(url: $url);
        }

        // 412 is handled by the calling client (optimistic lock); surface the
        // raw status so it can fetch the fresh representation.
        if ($status === 412) {
            return [
                'status'  => 412,
                'body'    => $decoded,
                'headers' => $response->getHeaders(),
                'etag'    => $etag,
            ];
        }

        throw new ZgwBridgeException(
            message: sprintf('ZGW-aanroep %s %s gaf onverwachte status %d.', $method, $url, $status)
        );
    }//end interpret()

    /**
     * Map a 403 ZGW fault onto either a clock-skew or insufficient-scope exception.
     *
     * @param array<string, mixed> $body The decoded fault body.
     * @param string               $url  The requested URL.
     *
     * @return never
     *
     * @throws ClockSkewException         On a JWT-timing fault.
     * @throws InsufficientScopeException On a scope rejection.
     * @throws ZgwBridgeException         On an unrecognised 403.
     */
    private function raiseForbidden(array $body, string $url): never
    {
        $detail = strtolower((string) ($body['detail'] ?? ($body['title'] ?? '')));

        if (str_contains($detail, 'jwt verlopen') === true
            || str_contains($detail, 'jwt nog niet geldig') === true
            || str_contains($detail, 'jwt expired') === true
            || str_contains($detail, 'jwt not yet valid') === true
        ) {
            $detailText = 'JWT timing';
            if ($detail !== '') {
                $detailText = $detail;
            }

            $now = time();
            throw new ClockSkewException(
                message: sprintf(
                    'JWT-timingfout van ZGW-component (%s). Lokale tijd: %s. '
                    .'Controleer de klok-/NTP-synchronisatie van de pipelinq-host.',
                    $detailText,
                    gmdate('c')
                ),
                observedAt: $now,
                serverAt: $now
            );
        }

        // A 403 that is not a JWT-timing fault is a scope/authorisation refusal.
        throw new InsufficientScopeException(scope: 'zgw.autorisatie', targetUrl: $url);
    }//end raiseForbidden()
}//end class
