<?php

/**
 * Pipelinq AvgRedactionController.
 *
 * REST controller for AVG redaction operations: applying a field-level redaction
 * (with the own-data guard), reading the before/after redaction summary for
 * 4-eyes review, and approving the redaction set so the bundle may be generated.
 * Access is scoped through AvgRequestService (IDOR-safe).
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
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Avg\AvgAccessService;
use OCA\Pipelinq\Service\Avg\AvgRequestService;
use OCA\Pipelinq\Service\Avg\RedactionService;
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
 * Controller for AVG redaction endpoints.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.4
 */
class AvgRedactionController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest          $request     The request.
     * @param AvgRequestService $requests    The request lifecycle service.
     * @param RedactionService  $redaction   The redaction service.
     * @param AvgAccessService  $access      The access-control service.
     * @param IUserSession      $userSession The user session.
     * @param IL10N             $l10n        The localization service.
     * @param LoggerInterface   $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private AvgRequestService $requests,
        private RedactionService $redaction,
        private AvgAccessService $access,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Apply a field-level redaction to an evidence item.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The updated evidence item + redaction action.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.4
     */
    #[NoAdminRequired]
    public function redact(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $bewijsItemId = (string) $this->request->getParam('bewijsItemId', '');
        $veldpad      = (string) $this->request->getParam('veldpad', '');
        $grond        = (string) $this->request->getParam('grond', 'bescherming-rechten-derden');
        $naWaarde     = (string) $this->request->getParam('naWaarde', '');

        return $this->run(
            action: function () use ($id, $uid, $bewijsItemId, $veldpad, $grond, $naWaarde): array {
                $request = $this->requests->get(id: $id, userId: $uid);
                if ($this->access->canEdit(request: $request, userId: $uid) === false) {
                    throw new OCSForbiddenException('Geen rechten om te redigeren.');
                }

                if ($bewijsItemId === '' || $veldpad === '') {
                    throw new OCSBadRequestException('bewijsItemId en veldpad zijn verplicht.');
                }

                return $this->redaction->applyRedaction(
                    request: $request,
                    bewijsItemId: $bewijsItemId,
                    fieldPath: $veldpad,
                    ground: $grond,
                    replacement: $naWaarde,
                    userId: $uid
                );
            },
            label: 'redact'
        );
    }//end redact()

    /**
     * Return the before/after redaction summary for 4-eyes review.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The redaction actions.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.4
     */
    #[NoAdminRequired]
    public function summary(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $this->requests->get(id: $id, userId: $uid);
                return ['redacties' => $this->redaction->summary(verzoekId: $id)];
            },
            label: 'summary'
        );
    }//end summary()

    /**
     * Approve the redaction set, advancing the request to bundle generation.
     *
     * @param string $id The request UUID.
     *
     * @return JSONResponse The updated request.
     *
     * @spec openspec/changes/avg-verzoeken-workflow/tasks.md#2.4
     */
    #[NoAdminRequired]
    public function approve(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: function () use ($id, $uid): array {
                $request = $this->requests->get(id: $id, userId: $uid);
                if ($this->access->canEdit(request: $request, userId: $uid) === false) {
                    throw new OCSForbiddenException('Geen rechten om redacties goed te keuren.');
                }

                return [
                    'verzoek' => $this->requests->update(
                        id: $id,
                        patch: ['status' => 'bundle-genereren'],
                        userId: $uid
                    ),
                ];
            },
            label: 'approve'
        );
    }//end approve()

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
            $this->logger->error('AvgRedactionController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
