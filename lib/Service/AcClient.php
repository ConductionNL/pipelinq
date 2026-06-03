<?php

/**
 * Pipelinq AcClient.
 *
 * Autorisaties API (AC) client with scope caching. Queries the AC to discover
 * which scopes the configured ZgwClient holds per zaaktype, building an
 * in-process cache refreshed on a configurable interval (default 15m). Provides
 * the client-side pre-flight guard that blocks write operations the client is
 * not authorised for, before any HTTP call to ZRC/DRC/BRC (REQ-ZGW-006).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\InsufficientScopeException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Autorisaties API (AC) client with scope caching.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#2.6
 */
class AcClient
{
    /**
     * Default scope-cache refresh interval in seconds (15 minutes).
     *
     * @var int
     */
    private const DEFAULT_REFRESH_INTERVAL = 900;

    /**
     * Component-level scope used for DRC document creation (not zaaktype-scoped).
     *
     * @var string
     */
    public const SCOPE_DOCUMENTEN_AANMAKEN = 'documenten.aanmaken';

    /**
     * Scope cache: endpointId => ['expires' => int, 'scopes' => array<string, string[]>].
     *
     * The inner map is zaaktypeUrl => list of granted scopes. The special key
     * "*" holds component-level scopes (e.g. documenten.aanmaken).
     *
     * @var array<string, array{expires:int, scopes:array<string, array<int, string>>}>
     */
    private array $cache = [];

    /**
     * Constructor.
     *
     * @param ZgwApiClient    $apiClient The base ZGW HTTP client.
     * @param IAppConfig      $appConfig The app config (refresh-interval tuning).
     * @param LoggerInterface $logger    The logger.
     */
    public function __construct(
        private ZgwApiClient $apiClient,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get the configured refresh interval in seconds.
     *
     * @return int The interval.
     */
    private function refreshInterval(): int
    {
        $interval = (int) $this->appConfig->getValueString(
            Application::APP_ID,
            'zgw.ac_refresh_interval',
            (string) self::DEFAULT_REFRESH_INTERVAL
        );

        if ($interval <= 0) {
            return self::DEFAULT_REFRESH_INTERVAL;
        }

        return $interval;
    }//end refreshInterval()

    /**
     * Refresh the scope cache for an endpoint from the AC.
     *
     * On AC unreachability this logs a warning and leaves any existing cache in
     * place; the next refresh retries. Never throws (REQ-ZGW-006).
     *
     * @param array<string, mixed> $endpoint The ZgwEndpoint object array.
     * @param array<string, mixed> $client   The ZgwClient object array.
     *
     * @return void
     */
    public function refreshScopes(array $endpoint, array $client): void
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $acUrl      = (string) ($endpoint['componenten']['ac'] ?? '');
        $clientId   = (string) ($client['clientIdentifier'] ?? '');

        if ($endpointId === '' || $acUrl === '' || $clientId === '') {
            return;
        }

        try {
            $response = $this->apiClient->callComponent(
                $acUrl,
                'GET',
                '/applicaties?clientIds='.rawurlencode($clientId),
                $client
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'AcClient: scope refresh failed; keeping previous cache',
                ['endpoint' => $endpointId, 'exception' => $e->getMessage()]
            );
            return;
        }

        $this->cache[$endpointId] = [
            'expires' => (time() + $this->refreshInterval()),
            'scopes'  => $this->buildScopeMap(applicaties: ($response['body']['results'] ?? [])),
        ];
    }//end refreshScopes()

    /**
     * Build a zaaktypeUrl => scopes map from AC applicatie autorisaties.
     *
     * @param mixed $applicaties The AC "results" array (list of applicaties).
     *
     * @return array<string, array<int, string>> The scope map.
     */
    private function buildScopeMap(mixed $applicaties): array
    {
        $map = [];
        if (is_array($applicaties) === false) {
            return $map;
        }

        foreach ($applicaties as $applicatie) {
            $autorisaties = ($applicatie['autorisaties'] ?? []);
            if (is_array($autorisaties) === false) {
                continue;
            }

            foreach ($autorisaties as $autorisatie) {
                $scopes = ($autorisatie['scopes'] ?? []);
                if (is_array($scopes) === false) {
                    continue;
                }

                $zaaktype = (string) ($autorisatie['zaaktype'] ?? ($autorisatie['besluittype'] ?? ($autorisatie['informatieobjecttype'] ?? '*')));
                if ($zaaktype === '') {
                    $zaaktype = '*';
                }

                $existing       = ($map[$zaaktype] ?? []);
                $map[$zaaktype] = array_values(array_unique(array_merge($existing, array_map('strval', $scopes))));
                // Component-level scopes (e.g. documenten.aanmaken) are also
                // discoverable under the wildcard for DRC pre-flight checks.
                $map['*'] = array_values(array_unique(array_merge(($map['*'] ?? []), $map[$zaaktype])));
            }//end foreach
        }//end foreach

        return $map;
    }//end buildScopeMap()

    /**
     * Ensure the endpoint's scope cache is populated and fresh.
     *
     * @param array<string, mixed> $endpoint The ZgwEndpoint object array.
     * @param array<string, mixed> $client   The ZgwClient object array.
     *
     * @return void
     */
    private function ensureFresh(array $endpoint, array $client): void
    {
        $endpointId = (string) ($endpoint['id'] ?? '');
        $entry      = ($this->cache[$endpointId] ?? null);

        if ($entry === null || $entry['expires'] < time()) {
            $this->refreshScopes(endpoint: $endpoint, client: $client);
        }
    }//end ensureFresh()

    /**
     * Determine whether the client holds a scope for a zaaktype (or component-wide).
     *
     * @param array<string, mixed> $endpoint    The ZgwEndpoint object array.
     * @param array<string, mixed> $client      The ZgwClient object array.
     * @param string               $zaaktypeUrl The zaaktype/besluittype URL, or '*' for component-level.
     * @param string               $scope       The scope to check (e.g. "zaken.aanmaken").
     *
     * @return bool True when the scope is granted.
     */
    public function hasScope(array $endpoint, array $client, string $zaaktypeUrl, string $scope): bool
    {
        $this->ensureFresh(endpoint: $endpoint, client: $client);
        $endpointId = (string) ($endpoint['id'] ?? '');
        $scopes     = ($this->cache[$endpointId]['scopes'] ?? []);

        if (in_array($scope, ($scopes[$zaaktypeUrl] ?? []), true) === true) {
            return true;
        }

        // Fall back to component-wide grants (e.g. documenten.aanmaken).
        return in_array($scope, ($scopes['*'] ?? []), true);
    }//end hasScope()

    /**
     * Get the list of granted scopes for a zaaktype.
     *
     * @param array<string, mixed> $endpoint    The ZgwEndpoint object array.
     * @param array<string, mixed> $client      The ZgwClient object array.
     * @param string               $zaaktypeUrl The zaaktype URL.
     *
     * @return array<int, string> The granted scopes.
     */
    public function getScopesFor(array $endpoint, array $client, string $zaaktypeUrl): array
    {
        $this->ensureFresh(endpoint: $endpoint, client: $client);
        $endpointId = (string) ($endpoint['id'] ?? '');

        return ($this->cache[$endpointId]['scopes'][$zaaktypeUrl] ?? []);
    }//end getScopesFor()

    /**
     * Pre-flight guard: assert the client holds a scope or raise.
     *
     * @param array<string, mixed> $endpoint    The ZgwEndpoint object array.
     * @param array<string, mixed> $client      The ZgwClient object array.
     * @param string               $zaaktypeUrl The target zaaktype/besluittype URL (or '*').
     * @param string               $scope       The required scope.
     *
     * @return void
     *
     * @throws InsufficientScopeException When the scope is not granted.
     */
    public function requireScope(array $endpoint, array $client, string $zaaktypeUrl, string $scope): void
    {
        if ($this->hasScope(endpoint: $endpoint, client: $client, zaaktypeUrl: $zaaktypeUrl, scope: $scope) === false) {
            throw new InsufficientScopeException(scope: $scope, targetUrl: $zaaktypeUrl);
        }
    }//end requireScope()
}//end class
