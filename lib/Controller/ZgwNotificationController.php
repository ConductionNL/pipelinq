<?php

/**
 * Pipelinq ZgwNotificationController.
 *
 * Inbound NRC (Notificaties) callback endpoint. Although declared a public page
 * (the NRC is an unauthenticated external caller in the NC session sense), every
 * request MUST carry a Bearer token that matches the per-abonnement callback
 * secret stored encrypted in the vault; unmatched tokens get a 401 and no body
 * is processed. On a valid token the notification is dispatched and a 202 is
 * returned immediately (REQ-ZGW-007, ADR-005).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
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

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\NrcNotificationHandler;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use OCA\Pipelinq\Service\ZgwSecretResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Inbound NRC notification callback controller.
 *
 * @spec openspec/changes/zgw-api-bridge/tasks.md#3.1
 */
class ZgwNotificationController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request        The request.
     * @param ZgwObjectRepository    $repository     The ZGW object persistence helper.
     * @param ZgwSecretResolver      $secretResolver The encrypted-secret store.
     * @param NrcNotificationHandler $handler        The per-kanaal notification dispatcher.
     * @param LoggerInterface        $logger         The logger.
     */
    public function __construct(
        IRequest $request,
        private ZgwObjectRepository $repository,
        private ZgwSecretResolver $secretResolver,
        private NrcNotificationHandler $handler,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Receive an NRC notification, authenticate by Bearer token, dispatch async.
     *
     * @return JSONResponse 202 on accept, 401 on unknown/invalid bearer.
     *
     * @NoCSRFRequired
     * @PublicPage
     *
     * @spec openspec/changes/zgw-api-bridge/specs/zgw-api-bridge/spec.md#REQ-ZGW-007
     */
    public function inbox(): JSONResponse
    {
        $bearer = $this->extractBearer();
        if ($bearer === null) {
            return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $abonnement = $this->matchAbonnement(bearer: $bearer);
        if ($abonnement === null) {
            return new JSONResponse(['error' => 'Unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $body = $this->decodeBody();

        try {
            $this->handler->handle(abonnement: $abonnement, notification: $body);
        } catch (\Throwable $e) {
            // Handler is internally resilient; this is a last-resort guard so a
            // crash never leaks a stack trace to the external NRC caller.
            $this->logger->error('ZgwNotificationController: dispatch error', ['exception' => $e->getMessage()]);
        }

        return new JSONResponse(['status' => 'accepted'], Http::STATUS_ACCEPTED);
    }//end inbox()

    /**
     * Extract the Bearer token from the Authorization header.
     *
     * @return string|null The token, or null when absent/malformed.
     */
    private function extractBearer(): ?string
    {
        $header = (string) $this->request->getHeader('Authorization');
        if (stripos($header, 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return null;
        }

        return $token;
    }//end extractBearer()

    /**
     * Find the active NrcAbonnement whose stored callback secret equals the bearer.
     *
     * Uses a constant-time comparison against each candidate's decrypted secret.
     *
     * @param string $bearer The presented bearer token.
     *
     * @return array<string, mixed>|null The matching abonnement, or null.
     */
    private function matchAbonnement(string $bearer): ?array
    {
        $abonnementen = $this->repository->findBy(entity: 'nrcAbonnement', filters: ['actief' => true]);

        foreach ($abonnementen as $abonnement) {
            $ref    = (string) ($abonnement['callbackAuthKluisRef'] ?? '');
            $secret = $this->secretResolver->resolve(reference: $ref);
            if ($secret !== null && hash_equals($secret, $bearer) === true) {
                return $abonnement;
            }
        }

        return null;
    }//end matchAbonnement()

    /**
     * Decode the JSON request body into an array.
     *
     * @return array<string, mixed> The decoded body (empty on parse failure).
     */
    private function decodeBody(): array
    {
        $raw     = (string) file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end decodeBody()
}//end class
