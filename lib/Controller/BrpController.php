<?php

/**
 * Pipelinq BrpController.
 *
 * Thin controller for the BRP capability: a server-side 11-proef validation
 * endpoint, the doelbinding-gated lookup endpoint, and the inbound HaalCentraal
 * mutation webhook. All authorization, doelbinding enforcement and auditing live
 * in the services; the controller only adapts HTTP <-> service and maps OCS
 * exceptions to status codes. No BSN is ever placed in a URL or echoed in an
 * error message (ADR-005 / REQ-BSN-009).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\BrpCacheService;
use OCA\Pipelinq\Service\BrpLookupService;
use OCA\Pipelinq\Service\BsnValidationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for BSN validation, BRP lookup and the mutation webhook.
 *
 * Authorization model: validate() and lookup() require an authenticated user;
 * lookup() additionally enforces the burgerzaken/avg role inside
 * BrpLookupService (fail closed, audited). The webhook is a #[PublicPage] but is
 * authenticated by an HMAC-SHA256 signature over the body — it never trusts the
 * caller's identity and never exposes data.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the three BRP services
 *  plus the session/config/logger an HTTP adapter needs; all single-purpose.
 *
 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.1
 */
class BrpController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request     The request.
     * @param BsnValidationService $validation  The 11-proef validator.
     * @param BrpLookupService     $lookup      The lookup orchestration service.
     * @param BrpCacheService      $cache       The cache (webhook invalidation).
     * @param IUserSession         $userSession The user session.
     * @param IAppConfig           $appConfig   The app config (webhook secret).
     * @param IL10N                $l10n        The localization service.
     * @param LoggerInterface      $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private BsnValidationService $validation,
        private BrpLookupService $lookup,
        private BrpCacheService $cache,
        private IUserSession $userSession,
        private IAppConfig $appConfig,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Server-side 11-proef validation (defence-in-depth; client validates too).
     *
     * @return JSONResponse The masked validation result.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.1
     */
    #[NoAdminRequired]
    public function validate(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $bsn    = (string) $this->request->getParam('bsn', '');
        $result = $this->validation->validate($bsn);

        return new JSONResponse($result->toArray());
    }//end validate()

    /**
     * Perform a doelbinding-gated BRP lookup.
     *
     * @return JSONResponse The lookup result, or an error status.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#3.2
     */
    #[NoAdminRequired]
    public function lookup(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $params = [
            'bsn'          => (string) $this->request->getParam('bsn', ''),
            'verzoekreden' => (string) $this->request->getParam('verzoekreden', ''),
            'doelbinding'  => (string) $this->request->getParam('doelbinding', ''),
            'grondslag'    => (string) $this->request->getParam('grondslag', ''),
            'contactId'    => (string) $this->request->getParam('contactId', ''),
            'verzoekId'    => (string) $this->request->getParam('verzoekId', ''),
            'actorRol'     => (string) $this->request->getParam('actorRol', ''),
            'vogScreening' => (bool) $this->request->getParam('vogScreening', false),
            'ipAdres'      => (string) $this->request->getRemoteAddress(),
            'userAgent'    => (string) $this->request->getHeader('User-Agent'),
        ];

        try {
            return new JSONResponse($this->lookup->lookup($params, $uid));
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            // Never leak a BSN or stack trace to the client.
            $this->logger->error('BrpController::lookup failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end lookup()

    /**
     * Inbound HaalCentraal mutation webhook — invalidates the cache for a BSN.
     *
     * Authenticated solely by an HMAC-SHA256 signature over the raw body using
     * the configured webhook secret; the caller's NC identity is irrelevant.
     *
     * @return JSONResponse 200 on success, 403 on a bad/absent signature.
     *
     * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#2.6
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function mutation(): JSONResponse
    {
        $body = (string) file_get_contents('php://input');
        if ($this->verifySignature(body: $body) === false) {
            return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
        }

        $data = json_decode($body, true);
        $bsn  = '';
        if (is_array($data) === true) {
            $bsn = (string) ($data['burgerservicenummer'] ?? '');
        }

        if ($bsn !== '') {
            $this->cache->invalidate($bsn);
        }

        return new JSONResponse(['status' => 'ok']);
    }//end mutation()

    /**
     * Verify the HMAC-SHA256 webhook signature (timing-safe).
     *
     * @param string $body The raw request body.
     *
     * @return bool True when the signature matches.
     */
    private function verifySignature(string $body): bool
    {
        $secret = $this->appConfig->getValueString(Application::APP_ID, 'brp.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $provided = (string) $this->request->getHeader('X-Signature');
        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secret);
        return hash_equals($expected, $provided);
    }//end verifySignature()

    /**
     * Require an authenticated user, returning their UID or a 401 response.
     *
     * @return string|JSONResponse The acting user UID, or a 401 response.
     */
    private function requireUserId(): string|JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                Http::STATUS_UNAUTHORIZED
            );
        }

        return $user->getUID();
    }//end requireUserId()
}//end class
