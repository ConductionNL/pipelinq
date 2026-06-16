<?php

/**
 * Pipelinq AvgDenialController.
 *
 * REST controller for the AVG denial (Weigering) path: drafting / updating a
 * denial with art. 23 grounds, fetching the current denial, and finalizing it
 * (which enforces the mandatory AP complaint reference and signs it). Access is
 * scoped through AvgRequestService (IDOR-safe); the legal invariants live in
 * DenialService.
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\AvgAccessService;
use OCA\Pipelinq\Service\Avg\AvgNotificationService;
use OCA\Pipelinq\Service\Avg\AvgRequestService;
use OCA\Pipelinq\Service\Avg\DenialService;
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
 * Controller for AVG denial endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
 */
class AvgDenialController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request       The request.
     * @param AvgRequestService      $requests      The request lifecycle service.
     * @param DenialService          $denials       The denial service.
     * @param AvgNotificationService $notifications The notification service.
     * @param AvgAccessService       $access        The access-control service.
     * @param IUserSession           $userSession   The user session.
     * @param IL10N                  $l10n          The localization service.
     * @param LoggerInterface        $logger        The logger.
     */
    public function __construct(
        IRequest $request,
        private AvgRequestService $requests,
        private DenialService $denials,
        private AvgNotificationService $notifications,
        private AvgAccessService $access,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Draft or update the denial for a request.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The denial draft.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
     */
    #[NoAdminRequired]
    public function deny(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $input = [
            'weigering'                  => (string) $this->request->getParam('weigering', ''),
            'grond'                      => (string) $this->request->getParam('grond', ''),
            'toelichtingAvg23'           => (string) $this->request->getParam('toelichtingAvg23', ''),
            'verwijzingAp'               => (string) $this->request->getParam('verwijzingAp', ''),
            'geweigerdeOnderdelen'       => $this->request->getParam('geweigerdeOnderdelen', []),
            'verwijzingBezwaarProcedure' => (bool) $this->request->getParam('verwijzingBezwaarProcedure', false),
        ];

        return $this->run(
            action: function () use ($id, $uid, $input): array {
                $request = $this->requests->get(id: $id, userId: $uid);
                if ($this->access->canEdit(request: $request, userId: $uid) === false) {
                    throw new OCSForbiddenException('Geen rechten om een weigering op te stellen.');
                }

                return ['weigering' => $this->denials->createOrUpdate(verzoekId: $id, input: $input, userId: $uid)];
            },
            label: 'deny'
        );
    }//end deny()

    /**
     * Fetch the current denial for a request, if any.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The denial (or null).
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $this->requests->get(id: $id, userId: $uid);
                return ['weigering' => $this->denials->findForRequest(verzoekId: $id)];
            },
            label: 'show'
        );
    }//end show()

    /**
     * Finalize and sign the denial (enforces the mandatory AP reference).
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The finalized denial + 4-eyes letter draft.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.5
     */
    #[NoAdminRequired]
    public function finalize(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $request = $this->requests->get(id: $id, userId: $uid);
                if ($this->access->canEdit(request: $request, userId: $uid) === false) {
                    throw new OCSForbiddenException('Geen rechten om een weigering te ondertekenen.');
                }

                $denial = $this->denials->finalize(verzoekId: $id, userId: $uid);
                return [
                    'weigering' => $denial,
                    'brief'     => $this->notifications->buildDenialDraft(request: $request, denial: $denial),
                ];
            },
            label: 'finalize'
        );
    }//end finalize()

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
            $this->logger->error('AvgDenialController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
