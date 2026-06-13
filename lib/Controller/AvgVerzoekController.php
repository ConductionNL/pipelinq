<?php

/**
 * Pipelinq AvgVerzoekController.
 *
 * REST controller for the AVG (GDPR) data-subject request lifecycle: intake,
 * scoped listing, fetch, handler-field updates, manual DPIA flagging, the
 * 60-day extension, archive and the retention-guarded delete. All authorization
 * and legal invariants live in the AVG services (ADR-005); this controller only
 * resolves the acting user, dispatches, and maps OCS exceptions to HTTP codes.
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\AvgAccessService;
use OCA\Pipelinq\Service\Avg\AvgNotificationService;
use OCA\Pipelinq\Service\Avg\AvgRequestService;
use OCA\Pipelinq\Service\Avg\ExtensionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for AVG request endpoints.
 *
 * Authorization model: every endpoint requires an authenticated user. Per-object
 * scoping (a handler may only see/act on their own requests; team leads/DPOs see
 * all) is enforced server-side inside AvgRequestService / AvgAccessService,
 * preventing IDOR.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the request, the four
 *  AVG collaborators it dispatches to, plus session/l10n/logger.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Each public method is one REST
 *  endpoint in the request lifecycle.
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
 */
class AvgVerzoekController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request       The request.
     * @param AvgRequestService      $service       The request lifecycle service.
     * @param ExtensionService       $extension     The extension service.
     * @param AvgAccessService       $access        The access-control service.
     * @param AvgNotificationService $notifications The notification service.
     * @param IUserSession           $userSession   The user session.
     * @param IL10N                  $l10n          The localization service.
     * @param LoggerInterface        $logger        The logger.
     */
    public function __construct(
        IRequest $request,
        private AvgRequestService $service,
        private ExtensionService $extension,
        private AvgAccessService $access,
        private AvgNotificationService $notifications,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List AVG requests visible to the acting user, with optional filters.
     *
     * @return JSONResponse The visible requests.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $filters = [
            'status'      => (string) $this->request->getParam('status', ''),
            'artikel'     => (string) $this->request->getParam('artikel', ''),
            'behandelaar' => (string) $this->request->getParam('behandelaar', ''),
            'dpiaFlag'    => (string) $this->request->getParam('dpiaFlag', ''),
        ];

        return $this->run(
            action: fn (): array => ['verzoeken' => $this->service->list(filters: $filters, userId: $uid)],
            label: 'index'
        );
    }//end index()

    /**
     * Register a new AVG request (intake).
     *
     * @return JSONResponse The created request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    #[NoAdminRequired]
    public function create(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        if ($this->access->isHandler(userId: $uid) === false) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Only AVG handlers may register requests')],
                Http::STATUS_FORBIDDEN
            );
        }

        $input = [
            'artikel'                  => $this->request->getParam('artikel'),
            'specifiekeVraag'          => (string) $this->request->getParam('specifiekeVraag', ''),
            'scope'                    => $this->request->getParam('scope', []),
            'ingediendVia'             => (string) $this->request->getParam('ingediendVia', 'handmatig'),
            'verzoekerContact'         => (string) $this->request->getParam('verzoekerContact', ''),
            'verzoekerNaam'            => (string) $this->request->getParam('verzoekerNaam', ''),
            'verzoekerBsn'             => (string) $this->request->getParam('verzoekerBsn', ''),
            'verzoekerBsnGeverifieerd' => (bool) $this->request->getParam('verzoekerBsnGeverifieerd', false),
            'dpiaFlag'                 => (bool) $this->request->getParam('dpiaFlag', false),
        ];

        return $this->run(
            action: fn (): array => ['verzoek' => $this->service->intake(input: $input, userId: $uid)],
            label: 'create'
        );
    }//end create()

    /**
     * Fetch a single AVG request the user may view.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => ['verzoek' => $this->service->get(id: $id, userId: $uid)],
            label: 'show'
        );
    }//end show()

    /**
     * Update mutable handler fields on a request.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The updated request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    #[NoAdminRequired]
    public function update(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $patch = [];
        foreach (['behandelaar', 'status', 'scope', 'specifiekeVraag', 'verzoekerNaam', 'verzoekerContact'] as $field) {
            $value = $this->request->getParam($field, null);
            if ($value !== null) {
                $patch[$field] = $value;
            }
        }

        return $this->run(
            action: fn (): array => ['verzoek' => $this->service->update(id: $id, patch: $patch, userId: $uid)],
            label: 'update'
        );
    }//end update()

    /**
     * Manually flag a request for DPIA review.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The updated request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#3.4
     */
    #[NoAdminRequired]
    public function flagDpia(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => ['verzoek' => $this->service->flagDpia(id: $id, userId: $uid)],
            label: 'flagDpia'
        );
    }//end flagDpia()

    /**
     * Grant a 60-day extension to a request (returns the citizen email draft).
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The updated request + 4-eyes email draft.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.6
     */
    #[NoAdminRequired]
    public function extend(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $reason = (string) $this->request->getParam('verlengingsgrond', '');

        return $this->run(
            action: function () use ($id, $uid, $reason): array {
                $request = $this->service->get(id: $id, userId: $uid);
                if ($this->access->canEdit(request: $request, userId: $uid) === false) {
                    throw new OCSForbiddenException('Geen rechten om dit verzoek te verlengen.');
                }

                $updated = $this->extension->extend(
                    request: $request,
                    justification: $reason,
                    now: new DateTimeImmutable()
                );

                return [
                    'verzoek'    => $updated,
                    'emailDraft' => $this->notifications->buildExtensionDraft(request: $updated, reason: $reason),
                ];
            },
            label: 'extend'
        );
    }//end extend()

    /**
     * Archive a resolved request and stamp the 5-year retention date.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The archived request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.7
     */
    #[NoAdminRequired]
    public function archive(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => ['verzoek' => $this->service->archive(id: $id, userId: $uid)],
            label: 'archive'
        );
    }//end archive()

    /**
     * Delete a request, refusing while the 5-year retention window is active.
     *
     * Only an FG/DPO may override the retention guard; the override is derived
     * server-side from the acting user's role, never from a client flag.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse An empty success body.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.1
     */
    #[NoAdminRequired]
    public function destroy(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $this->service->delete(id: $id, userId: $uid, isDpo: $this->access->isDpo(userId: $uid));
                return ['deleted' => true];
            },
            label: 'destroy'
        );
    }//end destroy()

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
            $this->logger->error('AvgVerzoekController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
