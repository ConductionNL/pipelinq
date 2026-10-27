<?php

/**
 * Pipelinq LoyaltyPaymentController.
 *
 * The cash register spends a customer's loyalty points as one part of a
 * split payment.
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
 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\LoyaltyPaymentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Loyalty points as a payment part.
 */
class LoyaltyPaymentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app name.
	 * @param IRequest                $request     The request.
	 * @param LoyaltyPaymentService   $service     The payment service.
	 * @param ObjectOwnerAccessPolicy $policy      Who may use loyalty.
	 * @param IUserSession            $userSession The user session.
	 * @param IL10N                   $l10n        The translator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private LoyaltyPaymentService $service,
		private ObjectOwnerAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Spend points from an account against a sale.
	 *
	 * @param string $accountId The loyalty account UUID.
	 *
	 * @return JSONResponse The points spent, their amount and the balance left.
	 *
	 * @spec openspec/changes/pos-sale-board-look/specs/pos-transactions/spec.md#requirement-loyalty-points-can-pay-part-of-a-sale
	 */
	#[NoAdminRequired]
	public function pay(string $accountId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$result = $this->service->payWithPoints(
				accountId: $accountId,
				points: (int)$this->request->getParam('points', 0),
				posTransactionId: (string)$this->request->getParam('posTransactionId', '')
			);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}
	}//end pay()
}//end class
