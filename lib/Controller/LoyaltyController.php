<?php

/**
 * Pipelinq LoyaltyController.
 *
 * Exposes the loyalty-program REST API for both authenticated app users (account
 * + redemption lookup) and POS terminals (gift-card validate/redeem + redemption
 * validate/use). Endpoints follow the auth posture documented per method.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\ObjectOwnerAccessPolicy;
use OCA\Pipelinq\Service\GiftCardService;
use OCA\Pipelinq\Service\LoyaltyAccountService;
use OCA\Pipelinq\Service\LoyaltyProgrammeService;
use OCA\Pipelinq\Service\PointsLedgerService;
use OCA\Pipelinq\Service\RedemptionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * Loyalty REST controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @spec                                           openspec/changes/loyalty-program/specs.md#REQ-LOY-004
 */
class LoyaltyController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param LoyaltyAccountService $accountService The account service.
	 * @param PointsLedgerService $ledgerService The ledger service.
	 * @param RedemptionService $redemptionService The redemption service.
	 * @param GiftCardService $giftCardService The gift card service.
	 * @param LoyaltyProgrammeService $programmeService The programme service.
	 * @param IUserSession $userSession The user session.
	 * @param IL10N $l10n The localiser.
	 */
	public function __construct(
		IRequest $request,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private RedemptionService $redemptionService,
		private GiftCardService $giftCardService,
		private LoyaltyProgrammeService $programmeService,
		private ObjectOwnerAccessPolicy $policy,
		private IUserSession $userSession,
		private IL10N $l10n,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get an account by its UUID (the caller MUST own the underlying klantId).
	 *
	 * @param string $accountId The account UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
	 */
	#[NoAdminRequired]
	public function getAccount(string $accountId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Account not found')],
				Http::STATUS_NOT_FOUND
			);
		}

		// Strip the hashed PIN equivalent / opt-in legal text from the response.
		unset($account['optInTermsVersion']);

		return new JSONResponse($account);
	}//end getAccount()

	/**
	 * Get the ledger history for an account.
	 *
	 * @param string $accountId The account UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-002
	 */
	#[NoAdminRequired]
	public function getAccountHistory(string $accountId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$entries = $this->ledgerService->getLedgerHistory(accountId: $accountId);
		return new JSONResponse(['entries' => $entries]);
	}//end getAccountHistory()

	/**
	 * List redemption options the account can currently afford for a programme.
	 *
	 * @param string $programmeId The programme UUID.
	 * @param string $accountId The account UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004
	 */
	#[NoAdminRequired]
	public function getRedemptionOptions(string $programmeId, string $accountId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$options = $this->redemptionService->getValidRedemptionOptions(
			accountId: $accountId,
			programmeId: $programmeId
		);

		return new JSONResponse(['options' => $options]);
	}//end getRedemptionOptions()

	/**
	 * Initiate a redemption — reserve points + return code.
	 *
	 * @param string $accountId The account UUID.
	 * @param string $optionId The option UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004-01
	 */
	#[NoAdminRequired]
	public function initiateRedemption(string $accountId, string $optionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$redemption = $this->redemptionService->initiateRedemption(
				accountId: $accountId,
				optionId: $optionId
			);
			return new JSONResponse($redemption, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		}
	}//end initiateRedemption()

	/**
	 * Validate a redemption code (POS-facing — auth required as authenticated user).
	 *
	 * @param string $code The beloningCode.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004-03
	 */
	#[NoAdminRequired]
	public function lookupRedemptionCode(string $code): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$result = $this->redemptionService->validateCode(code: $code);
		return new JSONResponse($result);
	}//end lookupRedemptionCode()

	/**
	 * Mark a redemption code as used (POS settlement).
	 *
	 * @param string $code The beloningCode.
	 *
	 * @return JSONResponse
	 *
	 * @spec exclude mechanical phpmd cleanup — local variable renamed only, behaviour unchanged
	 */
	#[NoAdminRequired]
	public function useRedemptionCode(string $code): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$validation = $this->redemptionService->validateCode(code: $code);
		if ($validation['valid'] === false) {
			return new JSONResponse(
				['error' => $validation['reason'] ?? 'Invalid code'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$redemption = $validation['redemption'] ?? null;
		if ($redemption === null) {
			return new JSONResponse(['error' => 'Redemption missing'], Http::STATUS_BAD_REQUEST);
		}

		$self = $redemption['@self'] ?? [];
		$selfId = '';
		if (is_array($self) === true) {
			$selfId = ($self['id'] ?? '');
		}

		$redemptionId = (string)$selfId;
		if ($redemptionId === '') {
			$redemptionId = (string)($redemption['uuid'] ?? $redemption['id'] ?? '');
		}

		try {
			$posTransactionId = $this->request->getParam('posTransactionId');
			$posTransactionRef = null;
			if (is_string($posTransactionId) === true) {
				$posTransactionRef = $posTransactionId;
			}

			$updated = $this->redemptionService->markRedemptionUsed(
				redemptionId: $redemptionId,
				posTransactionId: $posTransactionRef
			);
			return new JSONResponse($updated);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end useRedemptionCode()

	/**
	 * Validate a gift card by serial+pin (POS-facing).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-007
	 */
	#[NoAdminRequired]
	public function lookupGiftCard(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$serial = (string)$this->request->getParam('serial', '');
		$pin = (string)$this->request->getParam('pin', '');
		if ($serial === '' || $pin === '') {
			return new JSONResponse(
				['error' => 'serial and pin are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$result = $this->giftCardService->validateBySerial(serial: $serial, pin: $pin);
		return new JSONResponse($result);
	}//end lookupGiftCard()

	/**
	 * Redeem (debit) an amount from a gift card.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-007-01
	 */
	#[NoAdminRequired]
	public function redeemGiftCard(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$giftCardId = (string)$this->request->getParam('giftCardId', '');
		$pin = (string)$this->request->getParam('pin', '');
		$amount = (float)$this->request->getParam('amount', 0);
		$posTransactionId = $this->request->getParam('posTransactionId');
		if ($giftCardId === '' || $pin === '' || $amount <= 0) {
			return new JSONResponse(
				['error' => 'giftCardId, pin and amount (>0) are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$posTransactionRef = null;
		if (is_string($posTransactionId) === true) {
			$posTransactionRef = $posTransactionId;
		}

		try {
			$result = $this->giftCardService->redeemGiftCard(
				giftCardId: $giftCardId,
				pin: $pin,
				amount: $amount,
				posTransactionId: $posTransactionRef
			);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end redeemGiftCard()

	/**
	 * Activate a gift card after a successful POS transaction.
	 *
	 * @param string $giftCardId The card UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006-03
	 */
	#[NoAdminRequired]
	public function activateGiftCard(string $giftCardId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$posTransactionId = $this->request->getParam('posTransactionId');
		$posTransactionRef = null;
		if (is_string($posTransactionId) === true) {
			$posTransactionRef = $posTransactionId;
		}

		$card = $this->giftCardService->activateGiftCard(
			giftCardId: $giftCardId,
			posTransactionId: $posTransactionRef
		);
		if ($card === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Gift card not found')],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse($card);
	}//end activateGiftCard()

	/**
	 * Issue a new gift card (back-office / staff issuance).
	 *
	 * Mints a card in `issued` status with a bcrypt-hashed PIN and records the
	 * opening `issue` ledger entry. The plaintext PIN is returned exactly once
	 * in the response body and is never stored or logged (PCI-DSS). Restricted
	 * to app administrators because issuance creates monetary value; the POS
	 * activate/redeem endpoints then operate on the issued population.
	 *
	 * Body: `initialBalance` (required, > 0), optional `programmeId`,
	 * `expiryDays` (default 365), `kanaal` (default `purchased`),
	 * `uitgegevenAan`.
	 *
	 * @return JSONResponse The created card + one-time PIN (200), or 400 on invalid input.
	 *
	 * @spec exclude Reinstates loyalty gift-card issuance (money-and-bridge-fixes); loyalty-program canonical spec archived 2026-06-14.
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function issueGiftCard(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		$initialBalance = (float)$this->request->getParam('initialBalance', 0);
		if ($initialBalance <= 0) {
			return new JSONResponse(
				['error' => $this->l10n->t('initialBalance must be a positive amount')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$programmeIdRaw = $this->request->getParam('programmeId');
		$programmeId = null;
		if (is_string($programmeIdRaw) === true && $programmeIdRaw !== '') {
			$programmeId = $programmeIdRaw;
		}

		$expiryDays = (int)$this->request->getParam('expiryDays', 365);
		if ($expiryDays <= 0) {
			$expiryDays = 365;
		}

		$channel = (string)$this->request->getParam('channel', 'purchased');
		$uitgegevenInRaw = $this->request->getParam('uitgegevenIn');
		$uitgegevenIn = null;
		if (is_string($uitgegevenInRaw) === true && $uitgegevenInRaw !== '') {
			$uitgegevenIn = $uitgegevenInRaw;
		}

		try {
			$result = $this->giftCardService->issueGiftCard(
				programmeId: $programmeId,
				initialBalance: $initialBalance,
				expiryDays: $expiryDays,
				channel: $channel,
				uitgegevenIn: $uitgegevenIn
			);
			return new JSONResponse($result);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end issueGiftCard()

	/**
	 * Activate a loyalty programme (admin-only — relies on NC SecurityMiddleware default).
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-001-01
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function activateProgramme(string $programmeId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		if ($this->policy->isPrivileged(uid: $user->getUID()) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('Forbidden')],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$programme = $this->programmeService->activate(
				programmeId: $programmeId,
				activatedBy: (string)$user->getUID()
			);
			return new JSONResponse($programme);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end activateProgramme()
}//end class
