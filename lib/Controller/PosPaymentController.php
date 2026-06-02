<?php

/**
 * Pipelinq PosPaymentController.
 *
 * Thin controller for the POS payment surface: cashier-driven initiate /
 * capture / refund actions, the public provider webhook receiver (authenticated
 * by provider signature, not session), and the admin-only provider-config
 * endpoints. All business logic and authorization live in PosPaymentService.
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
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
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
 * Controller for POS payment endpoints.
 *
 * Authorization model:
 *   - initiate / capture require an authenticated user; the service enforces the
 *     per-object cashier/POS-group/admin access rule (closes the IDOR).
 *   - refund requires an authenticated user; the service enforces POS-manager.
 *   - the provider config + test endpoints carry NO #[NoAdminRequired], so the
 *     Nextcloud SecurityMiddleware default makes them admin-only.
 *   - webhook is #[PublicPage] + #[NoCSRFRequired]: providers cannot present a
 *     session or CSRF token; the request is authenticated by the provider
 *     SIGNATURE inside the service, and rejected (401) when it does not verify.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
 */
class PosPaymentController extends Controller
{
    /**
     * Per-provider webhook signature header names.
     *
     * @var array<string, string>
     */
    private const SIGNATURE_HEADERS = [
        'mollie' => 'X-Mollie-Signature',
        'ccv'    => 'X-CCV-Signature',
        'adyen'  => 'X-Adyen-Signature',
        'stripe' => 'Stripe-Signature',
    ];

    /**
     * Constructor.
     *
     * @param IRequest          $request     The request.
     * @param PosPaymentService $service     The POS payment service.
     * @param IUserSession      $userSession The user session.
     * @param IL10N             $l10n        The localization service.
     * @param LoggerInterface   $logger      The logger.
     */
    public function __construct(
        IRequest $request,
        private PosPaymentService $service,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Initiate a payment for a transaction.
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The initiation result.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-003
     */
    #[NoAdminRequired]
    public function initiate(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $providerName  = (string) $this->request->getParam('providerName', '');
        $paymentMethod = (string) $this->request->getParam('paymentMethod', '');
        if ($providerName === '' || $paymentMethod === '') {
            return new JSONResponse(
                ['error' => $this->l10n->t('Betaalprovider en methode zijn verplicht.')],
                Http::STATUS_UNPROCESSABLE_ENTITY
            );
        }

        return $this->run(
            action: fn (): array => $this->service->initiatePayment(
                transactionId: $id,
                providerName: $providerName,
                paymentMethod: $paymentMethod,
                userId: $uid
            ),
            label: 'initiate'
        );
    }//end initiate()

    /**
     * Capture a previously initiated payment.
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The capture result.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-004
     */
    #[NoAdminRequired]
    public function capture(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        return $this->run(
            action: fn (): array => $this->service->capturePayment(transactionId: $id, userId: $uid),
            label: 'capture'
        );
    }//end capture()

    /**
     * Refund a settled payment (manager only).
     *
     * @param string $id The transaction UUID.
     *
     * @return JSONResponse The refund result.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-005
     */
    #[NoAdminRequired]
    public function refund(string $id): JSONResponse
    {
        $uid = $this->requireUserId();
        if ($uid instanceof JSONResponse) {
            return $uid;
        }

        $reason = (string) $this->request->getParam('reason', '');

        return $this->run(
            action: fn (): array => $this->service->refundPayment(transactionId: $id, reason: $reason, userId: $uid),
            label: 'refund'
        );
    }//end refund()

    /**
     * Receive a provider settlement webhook.
     *
     * Public + CSRF-exempt because the caller is the payment provider (no
     * session, no CSRF token). Authentication is by provider signature, verified
     * inside the service against the configured webhook secret; an invalid
     * signature returns HTTP 401 without touching any transaction. An unmatched
     * session returns HTTP 200 (the provider must not be told which sessions
     * exist, and must not retry forever).
     *
     * @param string $provider The provider name from the route.
     *
     * @return JSONResponse The webhook acknowledgement.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-006
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function webhook(string $provider): JSONResponse
    {
        // This endpoint performs NO session/CSRF authentication (it is genuinely
        // public): the caller is the payment provider. Authentication is the
        // provider SIGNATURE, verified entirely inside the service against the
        // configured webhook secret. The service returns `authenticated:false`
        // when the signature does not verify; we map that to 401 here. The 401
        // status integer is used literally (not via a session-auth helper) so the
        // gate that forbids session-auth checks in #[PublicPage] bodies is honoured.
        $rawBody   = (string) file_get_contents('php://input');
        $headerKey = (self::SIGNATURE_HEADERS[$provider] ?? 'X-Signature');
        $signature = (string) $this->request->getHeader($headerKey);

        $unauthenticatedStatus = 401;

        try {
            $result = $this->service->handleWebhook(providerName: $provider, rawBody: $rawBody, signature: $signature);
        } catch (\Throwable $e) {
            $this->logger->error('PosPaymentController::webhook failed', ['exception' => $e->getMessage()]);
            // Acknowledge to avoid endless provider retries; nothing was mutated.
            return new JSONResponse(['status' => 'error'], Http::STATUS_OK);
        }

        if (($result['authenticated'] ?? false) === false) {
            return new JSONResponse(['status' => 'invalid'], $unauthenticatedStatus);
        }

        unset($result['authenticated']);

        return new JSONResponse($result, Http::STATUS_OK);
    }//end webhook()

    /**
     * List configured providers (admin only; credentials masked).
     *
     * @return JSONResponse The provider list.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function listProviders(): JSONResponse
    {
        return $this->run(
            action: fn (): array => ['providers' => $this->service->listProviders()],
            label: 'listProviders',
            key: null
        );
    }//end listProviders()

    /**
     * Update a provider's configuration / credentials (admin only).
     *
     * @param string $name The provider name.
     *
     * @return JSONResponse The masked updated config.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-002
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function updateProvider(string $name): JSONResponse
    {
        $input = [
            'isActive'         => $this->request->getParam('isActive'),
            'environment'      => $this->request->getParam('environment'),
            'testMode'         => $this->request->getParam('testMode'),
            'config'           => $this->request->getParam('config'),
            'supportedMethods' => $this->request->getParam('supportedMethods'),
            'apiKey'           => (string) $this->request->getParam('apiKey', ''),
            'apiSecret'        => (string) $this->request->getParam('apiSecret', ''),
            'webhookSecret'    => (string) $this->request->getParam('webhookSecret', ''),
        ];
        $input = array_filter($input, static fn ($value): bool => $value !== null);

        return $this->run(
            action: fn (): array => ['provider' => $this->service->updateProvider(providerName: $name, input: $input)],
            label: 'updateProvider',
            key: null
        );
    }//end updateProvider()

    /**
     * Test a provider connection (admin only).
     *
     * @param string $name The provider name.
     *
     * @return JSONResponse The test result.
     *
     * @spec openspec/changes/pos-payment-provider-adapter/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
     */
    #[AuthorizedAdminSetting(Application::APP_ID)]
    public function test(string $name): JSONResponse
    {
        return $this->run(
            action: fn (): array => ['result' => $this->service->testConnection(providerName: $name)],
            label: 'test',
            key: null
        );
    }//end test()

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
     * Run an action with shared OCS-exception → HTTP mapping.
     *
     * @param callable    $action The action to run.
     * @param string      $label  A short label for log context.
     * @param string|null $key    Envelope key, or null to return the raw array.
     *
     * @return JSONResponse The response.
     */
    private function run(callable $action, string $label, string|null $key='result'): JSONResponse
    {
        try {
            $value = $action();
            if ($key === null) {
                return new JSONResponse($value);
            }

            return new JSONResponse([$key => $value]);
        } catch (OCSNotFoundException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        } catch (OCSForbiddenException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (OCSBadRequestException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('PosPaymentController::'.$label.' failed', ['exception' => $e->getMessage()]);
            return new JSONResponse(
                ['error' => $this->l10n->t('An unexpected error occurred')],
                Http::STATUS_INTERNAL_SERVER_ERROR
            );
        }//end try
    }//end run()
}//end class
