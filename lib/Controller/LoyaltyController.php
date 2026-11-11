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
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-004
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\CrmPrivilegeCheck;
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
use Throwable;

/**
 * Loyalty REST controller.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class went over the
 * threshold when each endpoint gained its CRM authorization guard. Every one
 * of those is a single early-return `if` on the same policy call — the added
 * complexity is uniform and shallow, not tangled logic, and the alternative
 * (hiding the guards behind a helper) is the arrangement gate-7 explicitly
 * does not accept, because a helper that returns null is not a guard.
 * @spec                                           openspec/specs/loyalty-program/spec.md#REQ-LOY-004
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
	 * @param CrmPrivilegeCheck $privilege The session user and the CRM privilege check.
	 * @param IL10N $l10n The localiser.
	 */
	public function __construct(
		IRequest $request,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private RedemptionService $redemptionService,
		private GiftCardService $giftCardService,
		private LoyaltyProgrammeService $programmeService,
		private CrmPrivilegeCheck $privilege,
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
	 */
	#[NoAdminRequired]
	public function getAccount(string $accountId): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-002
	 */
	#[NoAdminRequired]
	public function getAccountHistory(string $accountId): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-004
	 */
	#[NoAdminRequired]
	public function getRedemptionOptions(string $programmeId, string $accountId): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-004-01
	 */
	#[NoAdminRequired]
	public function initiateRedemption(string $accountId, string $optionId): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-004-03
	 */
	#[NoAdminRequired]
	public function lookupRedemptionCode(string $code): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
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
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$redemption = $this->usableRedemption(code: $code);
		if ($redemption instanceof JSONResponse) {
			return $redemption;
		}

		$redemptionId = $this->redemptionIdOf(redemption: $redemption);
		return $this->answerOrBadRequest(
			answer: fn (): array => $this->redemptionService->markRedemptionUsed(
				redemptionId: $redemptionId,
				posTransactionId: $this->posTransactionRef()
			)
		);
	}//end useRedemptionCode()

	/**
	 * Validate a gift card by serial+pin (POS-facing).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007
	 */
	#[NoAdminRequired]
	public function lookupGiftCard(): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
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
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-01
	 */
	#[NoAdminRequired]
	public function redeemGiftCard(): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$giftCardId = (string)$this->request->getParam('giftCardId', '');
		$pin = (string)$this->request->getParam('pin', '');
		$amount = (float)$this->request->getParam('amount', 0);
		$posTransactionRef = $this->posTransactionRef();
		if ($giftCardId === '' || $pin === '' || $amount <= 0) {
			return new JSONResponse(
				['error' => 'giftCardId, pin and amount (>0) are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return $this->answerOrBadRequest(
			answer: fn (): array => $this->giftCardService->redeemGiftCard(
				giftCardId: $giftCardId,
				pin: $pin,
				amount: $amount,
				posTransactionId: $posTransactionRef
			)
		);
	}//end redeemGiftCard()

	/**
	 * Activate a gift card after a successful POS transaction.
	 *
	 * @param string $giftCardId The card UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-03
	 */
	#[NoAdminRequired]
	public function activateGiftCard(string $giftCardId): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$posTransactionRef = $this->posTransactionRef();

		try {
			$card = $this->giftCardService->activateGiftCard(
				giftCardId: $giftCardId,
				posTransactionId: $posTransactionRef
			);
		} catch (Throwable $e) {
			// A card that cannot be activated from its current status (blocked,
			// depleted, expired) is a refusal the caller must see, not a 200
			// carrying the unchanged card. Same mapping redeemGiftCard() uses.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

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
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$initialBalance = (float)$this->request->getParam('initialBalance', 0);
		if ($initialBalance <= 0) {
			return new JSONResponse(
				['error' => $this->l10n->t('initialBalance must be a positive amount')],
				Http::STATUS_BAD_REQUEST
			);
		}

		$programmeId = $this->nonEmptyStringParam(key: 'programmeId');
		$expiryDays = $this->expiryDays();
		$channel = (string)$this->request->getParam('channel', 'purchased');
		$issuedIn = $this->nonEmptyStringParam(key: 'issuedIn');
		return $this->answerOrBadRequest(
			answer: fn (): array => $this->giftCardService->issueGiftCard(
				programmeId: $programmeId,
				initialBalance: $initialBalance,
				expiryDays: $expiryDays,
				channel: $channel,
				issuedIn: $issuedIn
			)
		);
	}//end issueGiftCard()

	/**
	 * Activate a loyalty programme (admin-only — relies on NC SecurityMiddleware default).
	 *
	 * @param string $programmeId The programme UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-001-01
	 */
	#[AuthorizedAdminSetting(Application::APP_ID)]
	public function activateProgramme(string $programmeId): JSONResponse {
		// Authentication is not authorization. Loyalty accounts, ledgers and
		// redemption codes are a CRM capability, not something every account on
		// the instance holds by being logged in. Admins bypass; see
		// ObjectOwnerAccessPolicy for why this is a group check and not an ownership
		// one (23 of 27 schemas carry no owner field).
		$refusal = $this->privilege->translatedRefusal($this->l10n);
		if ($refusal !== null) {
			return $refusal;
		}

		$uid = $this->privilege->actingUserId();

		try {
			$programme = $this->programmeService->activate(
				programmeId: $programmeId,
				activatedBy: (string)$uid
			);
			return new JSONResponse($programme);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end activateProgramme()

	/**
	 * The redemption a valid code names, or the 400 that refuses the code.
	 *
	 * @param string $code The beloningCode.
	 *
	 * @return array<string, mixed>|JSONResponse The redemption, or the refusal.
	 */
	private function usableRedemption(string $code): array|JSONResponse {
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

		return $redemption;
	}//end usableRedemption()

	/**
	 * A redemption's id: its `@self.id`, else its `uuid`, else its `id`.
	 *
	 * @param array<string, mixed> $redemption The redemption.
	 *
	 * @return string The id, or ''.
	 */
	private function redemptionIdOf(array $redemption): string {
		$self = $redemption['@self'] ?? [];
		$selfId = '';
		if (is_array($self) === true) {
			$selfId = ($self['id'] ?? '');
		}

		$redemptionId = (string)$selfId;
		if ($redemptionId === '') {
			$redemptionId = (string)($redemption['uuid'] ?? $redemption['id'] ?? '');
		}

		return $redemptionId;
	}//end redemptionIdOf()

	/**
	 * The `posTransactionId` parameter when it is a string, else null.
	 *
	 * @return string|null The POS transaction reference.
	 */
	private function posTransactionRef(): ?string {
		$posTransactionId = $this->request->getParam('posTransactionId');
		if (is_string($posTransactionId) === true) {
			return $posTransactionId;
		}

		return null;
	}//end posTransactionRef()

	/**
	 * A parameter when it is a non-empty string, else null.
	 *
	 * @param string $key The parameter.
	 *
	 * @return string|null The value.
	 */
	private function nonEmptyStringParam(string $key): ?string {
		$value = $this->request->getParam($key);
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end nonEmptyStringParam()

	/**
	 * The requested gift card validity in days; 365 when absent or not positive.
	 *
	 * @return int The validity.
	 */
	private function expiryDays(): int {
		$expiryDays = (int)$this->request->getParam('expiryDays', 365);
		if ($expiryDays <= 0) {
			return 365;
		}

		return $expiryDays;
	}//end expiryDays()

	/**
	 * Answer what the service returns, or a 400 carrying its exception message.
	 *
	 * @param callable(): array<string, mixed> $answer Calls the service.
	 *
	 * @return JSONResponse The answer, or the refusal.
	 */
	private function answerOrBadRequest(callable $answer): JSONResponse {
		try {
			return new JSONResponse($answer());
		} catch (Throwable $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end answerOrBadRequest()
}//end class
