<?php

/**
 * Pipelinq AvgBundleController.
 *
 * REST controller for AVG export bundles: generating + signing a bundle,
 * reading bundle metadata, the one-time secure download (validated by a hashed
 * token), and the DPO-gated AP-escalation dossier export. The raw download token
 * is returned only once at generation; the download endpoint authenticates by
 * the token alone (secure-link delivery to the data subject) and never leaks
 * another subject's data.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\AvgAccessService;
use OCA\Pipelinq\Service\Avg\AvgRepository;
use OCA\Pipelinq\Service\Avg\AvgRequestService;
use OCA\Pipelinq\Service\Avg\BundleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for AVG export-bundle endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.3
 */
class AvgBundleController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request     The request.
     * @param AvgRequestService $requests    The request lifecycle service.
     * @param BundleService     $bundles     The bundle service.
     * @param AvgRepository     $repository  The AVG OR repository.
     * @param AvgAccessService  $access      The access-control service.
     * @param IUserSession      $userSession The user session.
     * @param IL10N             $l10n        The localization service.
     * @param LoggerInterface   $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private AvgRequestService $requests,
        private BundleService $bundles,
        private AvgRepository $repository,
        private AvgAccessService $access,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Generate and sign the export bundle for a request.
     *
     * Returns the bundle metadata and the one-time download token (shown once,
     * to be delivered to the data subject via a secure channel).
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The bundle + one-time token.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.3
     */
    #[NoAdminRequired]
    public function generate(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $request = $this->requests->get(id: $id, userId: $uid);
                if ($this->access->canEdit(request: $request, userId: $uid) === false) {
                    throw new OCSForbiddenException('Geen rechten om een bundle te genereren.');
                }

                $result = $this->bundles->generate(request: $request, userId: $uid);
                return ['bundle' => $result['bundle'], 'downloadToken' => $result['downloadToken']];
            },
            label: 'generate'
        );
    }//end generate()

    /**
     * Fetch bundle metadata (never the content; content is delivered via the
     * secure one-time download link).
     *
     * @param string $bundleId The bundle UUID.
     *
     * @return JSONResponse The bundle metadata (with the token hash stripped).
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.3
     */
    #[NoAdminRequired]
    public function show(string $bundleId): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($bundleId, $uid): array {
                $bundle  = $this->repository->find(schemaKey: AvgRepository::SCHEMA_EXPORT_BUNDLE, id: $bundleId);
                $request = $this->requests->get(id: (string) ($bundle['verzoekId'] ?? ''), userId: $uid);
                unset($bundle['downloadCodeHash']);
                return ['bundle' => $bundle, 'verzoek' => $request['kenmerk'] ?? ''];
            },
            label: 'show'
        );
    }//end show()

    /**
     * Consume the one-time secure download link.
     *
     * Public: the data subject is authenticated by possession of the one-time
     * token alone (delivered out-of-band). All access control lives in
     * BundleService::consumeDownload, which validates the token against the
     * stored hash in constant time, enforces the link expiry server-side and
     * raises a 403 for an empty, invalid or expired token without leaking any
     * data. The controller therefore performs no body auth branch — the token
     * is the sole authenticator, consistent with #[PublicPage].
     *
     * @param string $bundleId The bundle UUID.
     *
     * @return JSONResponse The bundle metadata after consumption.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.3
     */
    #[PublicPage]
    public function download(string $bundleId): JSONResponse
    {
        $token = (string) $this->request->getParam('token', '');

        return $this->run(
            action: function () use ($bundleId, $token): array {
                $bundle = $this->bundles->consumeDownload(bundleId: $bundleId, token: $token);
                unset($bundle['downloadCodeHash']);
                return ['bundle' => $bundle];
            },
            label: 'download'
        );
    }//end download()

    /**
     * Export the complete AP-escalation dossier for a request (FG/DPO only).
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The dossier index.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.8
     */
    #[NoAdminRequired]
    public function escalate(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                if ($this->access->isDpo(userId: $uid) === false) {
                    throw new OCSForbiddenException('Alleen de FG / DPO mag een AP-dossier exporteren.');
                }

                $request = $this->repository->find(schemaKey: AvgRepository::SCHEMA_VERZOEK, id: $id);
                return ['dossier' => $this->bundles->assembleDossier(request: $request)];
            },
            label: 'escalate'
        );
    }//end escalate()

    /**
     * Require an authenticated user, returning their UID or a 401.
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

    /**
     * Run an action with shared OCS-to-HTTP error mapping.
     *
     * @param callable $action The action to run.
     * @param string   $label  A short label for log context.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label): JSONResponse
    {
        try {
            return new JSONResponse($action());
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('AvgBundleController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
