<?php

/**
 * Pipelinq ContactmomentController.
 *
 * Controller for contactmoment API operations requiring server-side authorization.
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
 * @spec openspec/changes/contactmomenten/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ContactmomentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\NotPermittedException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for contactmoment API operations.
 *
 * @spec openspec/changes/contactmomenten/tasks.md#task-1.2
 */
class ContactmomentController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest             $request              The request.
     * @param ContactmomentService $contactmomentService The contactmoment service.
     * @param IUserSession         $userSession          The user session.
     * @param IL10N                $l10n                 The localization service.
     * @param LoggerInterface      $logger               The logger.
     */
    public function __construct(
        IRequest $request,
        private ContactmomentService $contactmomentService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Delete a contactmoment.
     *
     * Only the creating agent or an admin may delete.
     *
     * @param string $id The contactmoment ID.
     *
     * @return JSONResponse The response.
     *
     * @NoAdminRequired
     *
     * @spec openspec/changes/contactmomenten/tasks.md#task-1.2
     */
    public function destroy(string $id): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Authentication required')],
                401
            );
        }

        try {
            $this->contactmomentService->delete(
                $id,
                $user->getUID()
            );
            return new JSONResponse(['success' => true]);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('Contactmoment not found')],
                404
            );
        } catch (NotPermittedException $e) {
            return new JSONResponse(
                ['error' => $this->l10n->t('You do not have permission to delete this contactmoment')],
                403
            );
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error deleting contactmoment', ['exception' => $e]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An internal error occurred')],
                500
            );
        }//end try
    }//end destroy()
}//end class
