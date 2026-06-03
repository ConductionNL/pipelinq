<?php

/**
 * Pipelinq PosCustomerController.
 *
 * Thin controller for the POS customer-link surfaces: contact lookup, customer
 * attachment to a transaction, and the at-the-register purchase-history panel.
 * All business logic and authorization live in PosCustomerLinkService.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosCustomerLinkService;
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
 * Controller for the POS customer-link endpoints.
 *
 * Authorization model: every action requires an authenticated user and is gated
 * inside the service to a POS operator (PosAccessPolicy::isPosUser). Customer
 * attachment additionally checks per-transaction access (canAccessTransaction),
 * closing the IDOR. Reads are scoped to this app's register, so a contact /
 * transaction in another app or tenant resolves to a 404 rather than leaking.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
 */
class PosCustomerController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request     The request.
     * @param PosCustomerLinkService $service     The POS customer-link service.
     * @param IUserSession           $userSession The user session.
     * @param IL10N                  $l10n        The localization service.
     * @param LoggerInterface        $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private PosCustomerLinkService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Search Pipelinq contacts for the customer-lookup modal.
     *
     * @return JSONResponse The matching contacts.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-001
     */
    #[NoAdminRequired]
    public function search(): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $query = (string) $this->request->getParam('query', '');
        $limit = (int) $this->request->getParam('limit', 20);

        return $this->run(
            action: fn (): array => $this->service->searchContacts(query: $query, limit: $limit, userId: $uid),
            label: 'search',
            key: 'contacts'
        );
    }//end search()

    /**
     * Attach (or clear) a customer on a transaction.
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The updated transaction.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-002
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-005
     */
    #[NoAdminRequired]
    public function attach(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $customer = $this->nullableString(value: $this->request->getParam('customer', null));
        $tender   = $this->nullableString(value: $this->request->getParam('tenderType', null));
        $consent  = filter_var($this->request->getParam('marketingConsent', false), FILTER_VALIDATE_BOOLEAN);

        return $this->run(
            action: fn (): array => $this->service->attachCustomer(
                transactionId: $id,
                customerId: $customer,
                marketingConsent: $consent,
                tenderType: $tender,
                userId: $uid
            ),
            label: 'attach'
        );
    }//end attach()

    /**
     * Purchase history for a customer (register panel).
     *
     * @param string $customerId The contact UUID.
     *
     * @return JSONResponse The history payload.
     *
     * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-003
     */
    #[NoAdminRequired]
    public function history(string $customerId): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $limitParam = $this->request->getParam('limit', null);
        $limit      = null;
        if ($limitParam !== null) {
            $limit = (int) $limitParam;
        }

        return $this->run(
            action: fn (): array => $this->service->purchaseHistory(
                customerId: $customerId,
                limit: $limit,
                userId: $uid
            ),
            label: 'history',
            key: 'history'
        );
    }//end history()

    /**
     * Cast a raw request param to a non-null string, or null when absent.
     *
     * @param mixed $value The raw request param value.
     *
     * @return string|null The string value, or null when the param was absent.
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }//end nullableString()

    /**
     * Require an authenticated user, returning their UID.
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
     * Run an action with shared error handling.
     *
     * Maps the service's OCS exceptions to HTTP status codes (404 not found,
     * 422 bad input, 403 forbidden) and never leaks a stack trace to the client.
     *
     * @param callable $action The action to run.
     * @param string   $label  A short label for log context.
     * @param string   $key    The response envelope key.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, string $key='transaction'): JSONResponse
    {
        try {
            return new JSONResponse([$key => $action()]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('PosCustomerController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
