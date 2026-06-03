<?php

/**
 * Pipelinq NrcSubscriptionService.
 *
 * Manages the NRC (Notificaties Routerings Component) abonnement lifecycle for a
 * ZGW endpoint: registers an abonnement on first activation, keeps the
 * subscribed kanalen/filters in sync, and unregisters on deactivation. The
 * per-abonnement callback bearer secret is generated here and stored encrypted
 * via {@see ZgwSecretResolver}; only its vault reference is persisted on the
 * NrcAbonnement (REQ-ZGW-007, ADR-005).
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
 * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Exception\NrcSubscriptionFailedException;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * NRC abonnement lifecycle management.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#3.3
 */
class NrcSubscriptionService
{
    /**
     * Constructor.
     *
     * @param ZgwApiClient        $apiClient      The base ZGW HTTP client.
     * @param ZgwObjectRepository $repository     The ZGW object persistence helper.
     * @param ZgwSecretResolver   $secretResolver The encrypted-secret store.
     * @param ISecureRandom       $secureRandom   The CSPRNG for callback bearer tokens.
     * @param IAppConfig          $appConfig      The app config (callback URL base).
     * @param LoggerInterface     $logger         The logger.
     */
    public function __construct(
        private ZgwApiClient $apiClient,
        private ZgwObjectRepository $repository,
        private ZgwSecretResolver $secretResolver,
        private ISecureRandom $secureRandom,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the configured pipelinq NRC callback URL.
     *
     * @return string The callback URL.
     */
    private function callbackUrl(): string
    {
        return $this->appConfig->getValueString(
            Application::APP_ID,
            'zgw.nrc_callback_url',
            ''
        );
    }//end callbackUrl()

    /**
     * Register an NRC abonnement for an endpoint and persist it.
     *
     * Generates a per-abonnement callback bearer token (stored encrypted), POSTs
     * the abonnement to the NRC and persists the resulting NrcAbonnement.
     *
     * @param array<string, mixed>             $endpoint The ZgwEndpoint object array.
     * @param array<string, mixed>             $client   The ZgwClient object array.
     * @param array<int, array<string, mixed>> $kanalen  The kanalen + filters to subscribe.
     *
     * @return array<string, mixed> The persisted NrcAbonnement object array.
     *
     * @throws NrcSubscriptionFailedException When the NRC rejects the registration.
     */
    public function registerAbonnement(array $endpoint, array $client, array $kanalen): array
    {
        $nrcUrl      = (string) ($endpoint['componenten']['nrc'] ?? '');
        $callbackUrl = $this->callbackUrl();
        if ($nrcUrl === '' || $callbackUrl === '') {
            throw new NrcSubscriptionFailedException(
                message: 'NRC-URL of pipelinq callback-URL ontbreekt; kan abonnement niet registreren.'
            );
        }

        $endpointId = (string) ($endpoint['id'] ?? '');
        $bearer     = $this->secureRandom->generate(48, ISecureRandom::CHAR_ALPHANUMERIC);
        $bearerRef  = 'vault://zgw/'.$endpointId.'/nrc-callback-bearer';
        $this->secretResolver->store(reference: $bearerRef, secret: $bearer);

        $body = [
            'callbackUrl' => $callbackUrl,
            'auth'        => 'Bearer '.$bearer,
            'kanalen'     => $kanalen,
        ];

        try {
            $response = $this->apiClient->callComponent($nrcUrl, 'POST', '/abonnement', $client, $body);
        } catch (\Throwable $e) {
            $this->logger->error(
                'NrcSubscriptionService: registerAbonnement failed',
                ['nrcUrl' => $nrcUrl, 'exception' => $e->getMessage()]
            );
            throw new NrcSubscriptionFailedException(
                message: 'NRC-abonnement registreren mislukt: '.$e->getMessage(),
                code: 0,
                previous: $e
            );
        }

        $abonnementUrl = (string) ($response['body']['url'] ?? ($response['headers']['Location'][0] ?? ($response['headers']['location'][0] ?? '')));

        return $this->repository->save(
            'nrcAbonnement',
            [
                'endpointId'           => $endpointId,
                'abonnementUrl'        => $abonnementUrl,
                'callbackUrl'          => $callbackUrl,
                'callbackAuthKluisRef' => $bearerRef,
                'kanalen'              => $kanalen,
                'actief'               => true,
            ]
        );
    }//end registerAbonnement()

    /**
     * Sync an abonnement's kanalen by re-registering when they differ.
     *
     * @param array<string, mixed>             $endpoint   The ZgwEndpoint object array.
     * @param array<string, mixed>             $client     The ZgwClient object array.
     * @param array<string, mixed>             $abonnement The current NrcAbonnement object array.
     * @param array<int, array<string, mixed>> $newKanalen The desired kanalen.
     *
     * @return array<string, mixed> The (possibly re-registered) NrcAbonnement object array.
     *
     * @throws NrcSubscriptionFailedException When re-registration fails.
     */
    public function syncAbonnement(array $endpoint, array $client, array $abonnement, array $newKanalen): array
    {
        if (($abonnement['kanalen'] ?? []) === $newKanalen) {
            return $abonnement;
        }

        $this->unregisterAbonnement(client: $client, endpoint: $endpoint, abonnement: $abonnement);

        return $this->registerAbonnement(endpoint: $endpoint, client: $client, kanalen: $newKanalen);
    }//end syncAbonnement()

    /**
     * Unregister an abonnement from the NRC and mark it inactive.
     *
     * @param array<string, mixed> $endpoint   The ZgwEndpoint object array.
     * @param array<string, mixed> $client     The ZgwClient object array.
     * @param array<string, mixed> $abonnement The NrcAbonnement object array.
     *
     * @return void
     *
     * @throws NrcSubscriptionFailedException When the NRC delete fails.
     */
    public function unregisterAbonnement(array $endpoint, array $client, array $abonnement): void
    {
        $abonnementUrl = (string) ($abonnement['abonnementUrl'] ?? '');
        $nrcUrl        = (string) ($endpoint['componenten']['nrc'] ?? '');

        if ($abonnementUrl !== '') {
            try {
                $this->apiClient->callComponent($nrcUrl, 'DELETE', $abonnementUrl, $client);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'NrcSubscriptionService: unregisterAbonnement failed',
                    ['abonnementUrl' => $abonnementUrl, 'exception' => $e->getMessage()]
                );
                throw new NrcSubscriptionFailedException(
                    message: 'NRC-abonnement verwijderen mislukt: '.$e->getMessage(),
                    code: 0,
                    previous: $e
                );
            }
        }

        $uuid = (string) ($abonnement['@self']['uuid'] ?? ($abonnement['id'] ?? ''));
        if ($uuid !== '') {
            $data = $abonnement;
            unset($data['@self']);
            $data['actief'] = false;
            $this->repository->save(entity: 'nrcAbonnement', data: $data, uuid: $uuid);
        }
    }//end unregisterAbonnement()
}//end class
