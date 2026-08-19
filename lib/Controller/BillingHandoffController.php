<?php

/**
 * Pipelinq BillingHandoffController.
 *
 * HTTP surface for the manager-facing "Send to billing" action
 * (time-billing-handoff-emit): an availability check (so the frontend can
 * hide the button and fall back to the existing deep-link) and the trigger
 * itself, which posts a client's approved, un-billed time entries for a
 * period to shillinq's time-intake as one idempotent batch.
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
 * @spec openspec/specs/time-approval-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\BillingHandoffAccessPolicy;
use OCA\Pipelinq\Service\TimeBillingHandoffService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * "Send to billing" availability + trigger endpoints.
 *
 * Both endpoints are `#[NoAdminRequired]` + guarded by
 * {@see BillingHandoffAccessPolicy::isManager()} (manager group or NC admin)
 * — closes the IDOR that a plain `#[NoAdminRequired]` alone would leave open
 * (any authenticated user could otherwise bill any client's hours).
 *
 * @spec openspec/specs/time-approval-workflow/spec.md
 */
class BillingHandoffController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                   $request        The request.
     * @param TimeBillingHandoffService  $handoffService The billing handoff service.
     * @param BillingHandoffAccessPolicy $accessPolicy   The manager access policy.
     * @param IAppConfig                 $appConfig      The app configuration.
     * @param IUserSession               $userSession    The user session.
     */
    public function __construct(
        IRequest $request,
        private TimeBillingHandoffService $handoffService,
        private BillingHandoffAccessPolicy $accessPolicy,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Whether the "Send to billing" action is available.
     *
     * The frontend uses this to decide between the real trigger and the
     * existing deep-link fallback (`shillinq_app_url`) — unchanged when
     * shillinq is absent/disabled or the flag is off.
     *
     * @return JSONResponse {available, deepLinkUrl, isManager}.
     *
     * @spec openspec/specs/time-approval-workflow/spec.md
     */
    #[NoAdminRequired]
    public function availability(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(
            [
                'available'   => $this->handoffService->handoffAvailable(),
                'deepLinkUrl' => $this->appConfig->getValueString(Application::APP_ID, 'shillinq_app_url', ''),
                'isManager'   => $this->accessPolicy->isManager(userId: $user->getUID()),
            ]
        );
    }//end availability()

    /**
     * Trigger "Send to billing" for a client's approved, un-billed entries.
     *
     * @param string $clientId    The client UUID (route parameter).
     * @param string $periodStart The period start date (ISO 8601, inclusive).
     * @param string $periodEnd   The period end date (ISO 8601, inclusive).
     *
     * @return JSONResponse The handoff outcome.
     *
     * @spec openspec/specs/time-approval-workflow/spec.md
     */
    #[NoAdminRequired]
    public function trigger(string $clientId, string $periodStart='', string $periodEnd=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['status' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        if ($this->accessPolicy->isManager(userId: $user->getUID()) === false) {
            return new JSONResponse(['status' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        if ($clientId === '' || $periodStart === '' || $periodEnd === '') {
            return new JSONResponse(
                ['status' => 'invalid-request', 'message' => 'clientId, periodStart and periodEnd are required.'],
                Http::STATUS_BAD_REQUEST
            );
        }

        $result = $this->handoffService->sendToBilling(
            clientId: $clientId,
            periodStart: $periodStart,
            periodEnd: $periodEnd
        );

        return new JSONResponse($result, $this->statusFor(status: (string) ($result['status'] ?? 'failed')));
    }//end trigger()

    /**
     * Map a handoff outcome status to an HTTP status code.
     *
     * @param string $status The outcome status.
     *
     * @return int The HTTP status code.
     */
    private function statusFor(string $status): int
    {
        return match ($status) {
            'synced' => Http::STATUS_OK,
            'empty' => Http::STATUS_OK,
            'not-available' => Http::STATUS_CONFLICT,
            'conflict' => Http::STATUS_CONFLICT,
            'unmapped' => Http::STATUS_UNPROCESSABLE_ENTITY,
            default => Http::STATUS_BAD_GATEWAY,
        };
    }//end statusFor()
}//end class
