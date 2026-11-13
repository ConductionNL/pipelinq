<?php

/**
 * Pipelinq GiftCardService.
 *
 * Owns the GiftCard lifecycle: issuance (with bcrypt-hashed PIN; PCI-DSS — PIN
 * NEVER stored or logged plaintext), activation on POS settlement, full or
 * partial redemption, refund, and blocking. All balance movements are recorded
 * in immutable GiftCardTransaction ledger entries.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Util\PayloadToArray;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Gift card lifecycle.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Cohesive gift-card lifecycle; splitting would fragment a single domain.
 *
 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
 */
class GiftCardService {
	use PayloadToArray;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service (ADR-084).
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Issue a new gift card (status "issued", balance = initial, PIN hashed).
	 *
	 * @param ?string $programmeId Optional programme UUID.
	 * @param float $initialBalance Initial card balance.
	 * @param int $expiryDays Days until expiry (default 365).
	 * @param string $channel Issuance channel.
	 * @param ?string $issuedIn Recipient label.
	 *
	 * @return array{card: array<string, mixed>, pin: string} Card + plaintext PIN (returned ONCE).
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-01
	 */
	public function issueGiftCard(
		?string $programmeId,
		float $initialBalance,
		int $expiryDays = 365,
		string $channel = 'purchased',
		?string $issuedIn = null,
	): array {
		if ($initialBalance <= 0) {
			throw new RuntimeException('Initial balance must be positive.');
		}

		$serial = $this->generateUniqueSerial();
		$pin = $this->generatePin();
		$hash = password_hash($pin, PASSWORD_BCRYPT, ['cost' => 10]);

		$tz = new DateTimeZone('UTC');
		$now = (new DateTimeImmutable('now', $tz))->format('c');
		$exp = (new DateTimeImmutable("+{$expiryDays} days", $tz))->format('c');

		$card = [
			'programmeId' => $programmeId,
			'serial' => $serial,
			'pin' => $hash,
			'initialBalance' => $initialBalance,
			'currentBalance' => $initialBalance,
			'currency' => 'EUR',
			'status' => 'issued',
			'issuedOn' => $now,
			'issuedIn' => $issuedIn,
			'expiresOn' => $exp,
			'channel' => $channel,
		];

		$saved = $this->persistCard(payload: $card, uuid: null);

		$this->logTransaction(
			giftCardId: (string)$this->extractUuid(object: $saved),
			type: 'issue',
			amount: $initialBalance,
			balanceAfter: $initialBalance,
			posTransactionId: null,
			processedBy: 'gift-card-service'
		);

		return ['card' => $saved, 'pin' => $pin];
	}//end issueGiftCard()

	/**
	 * Activate a card (issued → active) after the issuing POS transaction completes.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param ?string $posTransactionId The POS transaction id.
	 *
	 * @return ?array<string, mixed>
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006-03
	 */
	public function activateGiftCard(string $giftCardId, ?string $posTransactionId = null): ?array {
		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			return null;
		}

		// 🔴 "NOT ISSUED" IS NOT ONE OUTCOME.
		//
		// This returned the card unchanged for ANY non-issued status — the
		// comment beside it said "already active or beyond", and treated both
		// halves of that the same. So activating a BLOCKED card answered 200
		// with the card and was indistinguishable from a successful
		// activation. At a till that reads as "activated"; the card is still
		// blocked.
		//
		// Already-active stays idempotent: re-scanning an activated card is a
		// normal thing to do and answering the card is the right reply. Every
		// other status is a refusal the caller has to see.
		if ($this->needsActivation(card: $card) === false) {
			return $card;
		}

		$card['status'] = 'active';
		$saved = $this->persistCard(payload: $card, uuid: $giftCardId);

		// No new ledger entry required for activation per REQ-LOY-006-03; the
		// initial 'issue' entry already records balansNa.
		$this->logActivation(giftCardId: $giftCardId, posTransactionId: $posTransactionId);

		return $saved;
	}//end activateGiftCard()

	/**
	 * Log a card activation.
	 *
	 * @param string      $giftCardId       The card UUID.
	 * @param string|null $posTransactionId The POS transaction id.
	 *
	 * @return void
	 */
	private function logActivation(string $giftCardId, ?string $posTransactionId): void {
		$this->logger->info(
			'Pipelinq: gift card activated',
			['giftCardId' => $giftCardId, 'posTransactionId' => $posTransactionId]
		);
	}//end logActivation()

	/**
	 * Whether a card still has to be activated.
	 *
	 * @param array<string, mixed> $card The card.
	 *
	 * @return bool True for an issued card, false for one already active.
	 *
	 * @throws RuntimeException For any other status: activating it is refused.
	 */
	private function needsActivation(array $card): bool {
		$status = (string)($card['status'] ?? '');
		if ($status === 'active') {
			return false;
		}

		if ($status !== 'issued') {
			throw new RuntimeException('Gift card cannot be activated from status "' . $status . '".');
		}

		return true;
	}//end needsActivation()

	/**
	 * Redeem (debit) an amount from a gift card. Supports partial redemption.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param string $pin The plaintext PIN (verified against hash).
	 * @param float $amount Amount to redeem (positive).
	 * @param ?string $posTransactionId Optional POS transaction id.
	 *
	 * @return array{amountApplied: float, balanceAfter: float, changeAmount: float, status: string}
	 *
	 * @throws RuntimeException On invalid PIN, expired, blocked, or non-active card.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-01
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-02
	 */
	public function redeemGiftCard(
		string $giftCardId,
		string $pin,
		float $amount,
		?string $posTransactionId = null,
	): array {
		return $this->redeem(
			redemption: new GiftCardRedemption(giftCardId: $giftCardId, pin: $pin, amount: $amount, posTransactionId: $posTransactionId)
		);
	}//end redeemGiftCard()

	/**
	 * Redeem (debit) an amount from a gift card, described as one redemption.
	 *
	 * The refusals run in a fixed order: amount, card, status, expiry, PIN.
	 * Only then is the POS transaction checked for a recorded redemption.
	 *
	 * @param GiftCardRedemption $redemption The card, PIN, amount and POS transaction.
	 *
	 * @return array{amountApplied: float, balanceAfter: float, changeAmount: float, status: string}
	 *
	 * @throws RuntimeException On invalid PIN, expired, blocked, or non-active card.
	 *
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-01
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-007-02
	 */
	public function redeem(GiftCardRedemption $redemption): array {
		if ($redemption->amount <= 0) {
			throw new RuntimeException('Redemption amount must be positive.');
		}

		$card = $this->loadRedeemableCard(redemption: $redemption);

		// 🔴 A POS RETRY MUST NOT DEBIT THE CARD TWICE.
		//
		// posTransactionId arrived, was written onto the transaction record, and
		// was never READ — so a POS that retried a redemption (a timeout, a lost
		// response, an operator pressing again) debited the card once per
		// attempt. Real money, silently, with a successful-looking answer each
		// time.
		//
		// The transaction log already carries the id, so it is the idempotency
		// key: a redemption already recorded against this card and this POS
		// transaction replays its result instead of applying a second debit.
		$replay = $this->findRedemptionByPosTransaction(
			giftCardId: $redemption->giftCardId,
			posTransactionId: $redemption->posTransactionId
		);
		if ($replay !== null) {
			return $replay;
		}

		return $this->applyDebit(card: $card, redemption: $redemption);
	}//end redeem()

	/**
	 * Load the card of a redemption and refuse it unless it may be debited.
	 *
	 * @param GiftCardRedemption $redemption The redemption.
	 *
	 * @return array<string, mixed> The card.
	 *
	 * @throws RuntimeException When the card is missing, unusable or the PIN is wrong.
	 */
	private function loadRedeemableCard(GiftCardRedemption $redemption): array {
		$card = $this->getCard(giftCardId: $redemption->giftCardId);
		if ($card === null) {
			throw new RuntimeException('Gift card not found.');
		}

		$this->assertRedeemableStatus(card: $card);

		if ($this->verifyPin(plaintext: $redemption->pin, hash: (string)($card['pin'] ?? '')) === false) {
			throw new RuntimeException('Invalid PIN.');
		}

		return $card;
	}//end loadRedeemableCard()

	/**
	 * Refuse a blocked, inactive or expired card.
	 *
	 * @param array<string, mixed> $card The card.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the card may not be debited.
	 */
	private function assertRedeemableStatus(array $card): void {
		$status = (string)($card['status'] ?? '');
		if ($status === 'blocked') {
			throw new RuntimeException('Gift card is blocked.');
		}

		if (in_array($status, ['active'], true) === false) {
			throw new RuntimeException('Gift card is not active.');
		}

		if ($this->isExpired(card: $card) === true) {
			throw new RuntimeException('Gift card has expired.');
		}
	}//end assertRedeemableStatus()

	/**
	 * Whether a card's expiry moment lies in the past.
	 *
	 * @param array<string, mixed> $card The card.
	 *
	 * @return bool True when the card carries an expiry before now.
	 */
	private function isExpired(array $card): bool {
		$expiresOn = (string)($card['expiresOn'] ?? '');
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

		return $expiresOn !== '' && $expiresOn < $now;
	}//end isExpired()

	/**
	 * Debit the card, record the ledger entry and answer the redemption result.
	 *
	 * @param array<string, mixed> $card       The card to debit.
	 * @param GiftCardRedemption   $redemption The redemption.
	 *
	 * @return array{amountApplied: float, balanceAfter: float, changeAmount: float, status: string}
	 */
	private function applyDebit(array $card, GiftCardRedemption $redemption): array {
		$debit = GiftCardDebit::fromBalance(balance: (float)($card['currentBalance'] ?? 0), amount: $redemption->amount);

		$card['currentBalance'] = $debit->balanceAfter;
		if ($debit->depleted === true) {
			$card['status'] = 'depleted';
		}

		$this->persistCard(payload: $card, uuid: $redemption->giftCardId);
		$this->recordDebit(redemption: $redemption, debit: $debit);

		return [
			'amountApplied' => $debit->applied,
			'balanceAfter' => $debit->balanceAfter,
			'changeAmount' => $debit->change,
			'status' => (string)($card['status'] ?? 'active'),
		];
	}//end applyDebit()

	/**
	 * Write the ledger entry of a debit.
	 *
	 * @param GiftCardRedemption $redemption The redemption.
	 * @param GiftCardDebit      $debit      The debit applied.
	 *
	 * @return void
	 */
	private function recordDebit(GiftCardRedemption $redemption, GiftCardDebit $debit): void {
		$this->logTransaction(
			giftCardId: $redemption->giftCardId,
			type: $debit->type,
			amount: $debit->applied,
			balanceAfter: $debit->balanceAfter,
			posTransactionId: $redemption->posTransactionId,
			processedBy: 'gift-card-service'
		);
	}//end recordDebit()

	/**
	 * A redemption already recorded for this card and POS transaction.
	 *
	 * Returns the same shape redeemGiftCard() answers, rebuilt from the stored
	 * transaction, so a retry sees exactly what the first attempt returned.
	 *
	 * Returns null when there is no POS transaction id (nothing to be
	 * idempotent ON), when no prior redemption exists, or when the lookup
	 * fails. The last case deliberately falls through to a normal redemption:
	 * refusing the sale because the idempotency CHECK broke would be a worse
	 * failure at a till than the double debit it guards against, and the
	 * transaction log still records both attempts for reconciliation.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param string|null $posTransactionId The POS transaction id, when supplied.
	 *
	 * @return array<string, mixed>|null The previous result, or null to proceed.
	 */
	private function findRedemptionByPosTransaction(string $giftCardId, ?string $posTransactionId): ?array {
		if ((string)$posTransactionId === '') {
			return null;
		}

		$filters = $this->scopedFilters(
			schemaKey: 'giftCardTransaction_schema',
			filters: ['giftCardId' => $giftCardId, 'posTransactionId' => $posTransactionId]
		);
		if ($filters === null) {
			return null;
		}

		return $this->firstReplay(rows: $this->findFirstRows(filters: $filters));
	}//end findRedemptionByPosTransaction()

	/**
	 * The replay of the first stored row that is a redemption.
	 *
	 * @param array<int|string, mixed> $rows Stored giftCardTransaction rows.
	 *
	 * @return array<string, mixed>|null The result to replay, or null when none is a redemption.
	 */
	private function firstReplay(array $rows): ?array {
		foreach ($rows as $row) {
			$replay = $this->replayFromTransactionRow(row: $row);
			if ($replay !== null) {
				return $replay;
			}
		}

		return null;
	}//end firstReplay()

	/**
	 * Rebuild a redemption result from a stored transaction row.
	 *
	 * Split from {@see findRedemptionByPosTransaction()} to keep that method
	 * inside the complexity limits: the lookup decides WHETHER to replay, this
	 * decides what the replay says.
	 *
	 * @param mixed $row A stored giftCardTransaction row.
	 *
	 * @return array<string, mixed>|null The result to replay, or null when the
	 *                                   row is not a redemption.
	 */
	private function replayFromTransactionRow(mixed $row): ?array {
		$array = $this->transactionRowToArray(row: $row);
		if ($array === null || in_array((string)($array['type'] ?? ''), ['redeem', 'partial_redeem'], true) === false) {
			return null;
		}

		$balanceAfter = (float)($array['balanceAfter'] ?? 0);

		return [
			'amountApplied' => (float)($array['amount'] ?? 0),
			'balanceAfter' => $balanceAfter,
			'changeAmount' => 0.0,
			'status' => $this->balanceStatus(balanceAfter: $balanceAfter),
		];
	}//end replayFromTransactionRow()

	/**
	 * The card status a remaining balance implies.
	 *
	 * @param float $balanceAfter The balance left on the card.
	 *
	 * @return string 'depleted' when nothing is left, otherwise 'active'.
	 */
	private function balanceStatus(float $balanceAfter): string {
		if ($balanceAfter <= 0.0) {
			return 'depleted';
		}

		return 'active';
	}//end balanceStatus()

	/**
	 * A stored transaction row as an array, or null when it is neither an
	 * array nor a serialisable entity.
	 *
	 * @param mixed $row A stored giftCardTransaction row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function transactionRowToArray(mixed $row): ?array {
		$array = $row;
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$array = $row->jsonSerialize();
		}

		if (is_array($array) === false) {
			return null;
		}

		return $array;
	}//end transactionRowToArray()

	/**
	 * Refund an amount onto a card.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param float $amount Amount to refund (positive).
	 * @param ?string $posTransactionId Optional POS transaction id.
	 *
	 * @return array<string, mixed> The updated card.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
	 */
	public function refundGiftCard(
		string $giftCardId,
		float $amount,
		?string $posTransactionId = null,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Refund amount must be positive.');
		}

		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			throw new RuntimeException('Gift card not found.');
		}

		$balance = (float)($card['currentBalance'] ?? 0);
		$after = round($balance + $amount, 2);
		$card['currentBalance'] = $after;

		if ((string)($card['status'] ?? '') === 'depleted' && $after > 0) {
			$card['status'] = 'active';
		}

		$saved = $this->persistCard(payload: $card, uuid: $giftCardId);

		$this->logTransaction(
			giftCardId: $giftCardId,
			type: 'refund',
			amount: $amount,
			balanceAfter: $after,
			posTransactionId: $posTransactionId,
			processedBy: 'gift-card-service'
		);

		return $saved;
	}//end refundGiftCard()

	/**
	 * Block a card (e.g. fraud / dispute).
	 *
	 * @param string $giftCardId The card UUID.
	 * @param string $reason Free-text reason.
	 *
	 * @return ?array<string, mixed>
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
	 */
	public function blockGiftCard(string $giftCardId, string $reason): ?array {
		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			return null;
		}

		$card['status'] = 'blocked';
		$saved = $this->persistCard(payload: $card, uuid: $giftCardId);

		$this->logTransaction(
			giftCardId: $giftCardId,
			type: 'block',
			amount: 0.0,
			balanceAfter: (float)($card['currentBalance'] ?? 0),
			posTransactionId: null,
			processedBy: 'gift-card-service'
		);

		$this->logger->info(
			'Pipelinq: gift card blocked',
			['giftCardId' => $giftCardId, 'reason' => $reason]
		);

		return $saved;
	}//end blockGiftCard()

	/**
	 * Return card details to a holder who proves possession of the PIN.
	 *
	 * Never returns the hashed PIN.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param string $pin The plaintext PIN.
	 *
	 * @return array{balance: float, status: string, expiryDate: ?string}
	 *
	 * @throws RuntimeException On invalid PIN.
	 * @spec openspec/specs/loyalty-program/spec.md#REQ-LOY-006
	 */
	public function getCardDetails(string $giftCardId, string $pin): array {
		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			throw new RuntimeException('Gift card not found.');
		}

		if ($this->verifyPin(plaintext: $pin, hash: (string)($card['pin'] ?? '')) === false) {
			throw new RuntimeException('Invalid PIN.');
		}

		return [
			'balance' => (float)($card['currentBalance'] ?? 0),
			'status' => (string)($card['status'] ?? 'issued'),
			'expiryDate' => $card['expiresOn'] ?? null,
		];
	}//end getCardDetails()

	/**
	 * Validate a card by serial + PIN (used at POS).
	 *
	 * @param string $serial The serial.
	 * @param string $pin The plaintext PIN.
	 *
	 * @return array{valid: bool, balance: float, expiryDate: ?string, giftCardId: ?string, reason: ?string}
	 *
	 * @spec exclude mechanical phpmd cleanup — no behaviour change
	 */
	public function validateBySerial(string $serial, string $pin): array {
		$card = $this->findBySerial(serial: $serial);
		if ($card === null) {
			return $this->refusedValidation(reason: 'Not found');
		}

		$reason = $this->validationRefusal(card: $card);
		if ($reason !== null) {
			return $this->cardValidation(card: $card, reason: $reason);
		}

		if ($this->verifyPin(plaintext: $pin, hash: (string)($card['pin'] ?? '')) === false) {
			return $this->refusedValidation(reason: 'Invalid PIN');
		}

		return $this->cardValidation(card: $card, reason: null);
	}//end validateBySerial()

	/**
	 * Why a found card fails validation before its PIN is checked.
	 *
	 * @param array<string, mixed> $card The card.
	 *
	 * @return string|null 'Blocked', 'Expired', or null when the card may be validated.
	 */
	private function validationRefusal(array $card): ?string {
		if (in_array((string)($card['status'] ?? ''), ['blocked'], true) === true) {
			return 'Blocked';
		}

		if ($this->isExpired(card: $card) === true) {
			return 'Expired';
		}

		return null;
	}//end validationRefusal()

	/**
	 * A validation answer that discloses nothing about the card.
	 *
	 * @param string $reason The refusal reason.
	 *
	 * @return array{valid: bool, balance: float, expiryDate: ?string, giftCardId: ?string, reason: ?string}
	 */
	private function refusedValidation(string $reason): array {
		return ['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => $reason];
	}//end refusedValidation()

	/**
	 * A validation answer carrying the card's balance, expiry and id.
	 *
	 * A blocked card answers its stored expiry as is; any other card answers
	 * it as a string, or null when it has none.
	 *
	 * @param array<string, mixed> $card   The card.
	 * @param string|null          $reason The refusal reason, or null when valid.
	 *
	 * @return array{valid: bool, balance: float, expiryDate: ?string, giftCardId: ?string, reason: ?string}
	 */
	private function cardValidation(array $card, ?string $reason): array {
		$expiryDate = $this->storedExpiry(card: $card);
		if ($reason === 'Blocked') {
			$expiryDate = ($card['expiresOn'] ?? null);
		}

		return [
			'valid' => $reason === null,
			'balance' => (float)($card['currentBalance'] ?? 0),
			'expiryDate' => $expiryDate,
			'giftCardId' => $this->extractUuid(object: $card),
			'reason' => $reason,
		];
	}//end cardValidation()

	/**
	 * The card's expiry as a string, or null when it has none.
	 *
	 * @param array<string, mixed> $card The card.
	 *
	 * @return string|null
	 */
	private function storedExpiry(array $card): ?string {
		$expiresOn = (string)($card['expiresOn'] ?? '');
		if ($expiresOn === '') {
			return null;
		}

		return $expiresOn;
	}//end storedExpiry()

	/**
	 * Get a card by UUID.
	 *
	 * @param string $giftCardId The card UUID.
	 *
	 * @return array<string, mixed>|null
	 * @spec exclude loyalty has no spec at all; the change that specified it was archived
	 *   and nothing inherited it
	 */
	public function getCard(string $giftCardId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '' || $giftCardId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $giftCardId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(value: $object);
	}//end getCard()

	/**
	 * Find a card by serial.
	 *
	 * @param string $serial The serial.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude mechanical phpmd cleanup — no behaviour change
	 */
	public function findBySerial(string $serial): ?array {
		$filters = $this->scopedFilters(schemaKey: 'giftCard_schema', filters: ['serial' => $serial]);
		if ($filters === null || $serial === '') {
			return null;
		}

		$rows = $this->findFirstRows(filters: $filters);
		if ($rows === []) {
			return null;
		}

		return $this->toArray(value: reset($rows));
	}//end findBySerial()

	/**
	 * Filters scoped to the configured register and a schema.
	 *
	 * @param string               $schemaKey The schema config key.
	 * @param array<string, mixed> $filters   The record filters.
	 *
	 * @return array<string, mixed>|null The filters plus register and schema, or
	 *                                   null when either is unconfigured.
	 */
	private function scopedFilters(string $schemaKey, array $filters): ?array {
		[$register, $schema] = $this->config(schemaKey: $schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return array_merge($filters, ['register' => $register, 'schema' => $schema]);
	}//end scopedFilters()

	/**
	 * The first matching row, as a list; empty when none matches or the read fails.
	 *
	 * @param array<string, mixed> $filters The scoped filters.
	 *
	 * @return array<int, mixed>
	 */
	private function findFirstRows(array $filters): array {
		try {
			$rows = $this->getObjectService()->findAll(config: ['filters' => $filters, 'limit' => 1]);
		} catch (\Throwable $e) {
			return [];
		}

		return array_values($rows);
	}//end findFirstRows()

	/**
	 * List gift cards owned by a klantId (used by GDPR export).
	 *
	 * @param string $customerId The klantId.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec exclude mechanical phpmd cleanup — no behaviour change
	 */
	public function listForKlant(string $customerId): array {
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'customerId' => $customerId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		return array_map([$this, 'toArray'], $rowList);
	}//end listForKlant()

	/**
	 * Verify a plaintext PIN against a bcrypt hash.
	 *
	 * @param string $plaintext The plaintext PIN.
	 * @param string $hash The bcrypt hash.
	 *
	 * @return bool
	 */
	private function verifyPin(string $plaintext, string $hash): bool {
		if ($hash === '') {
			return false;
		}

		return password_verify($plaintext, $hash);
	}//end verifyPin()

	/**
	 * Append a GiftCardTransaction ledger entry (immutable).
	 *
	 * @param string $giftCardId The card UUID.
	 * @param string $type The transaction type.
	 * @param float $amount The movement amount.
	 * @param float $balanceAfter The balance after the movement.
	 * @param ?string $posTransactionId The POS transaction id.
	 * @param string $processedBy Processor identifier.
	 *
	 * @return void
	 */
	private function logTransaction(
		string $giftCardId,
		string $type,
		float $amount,
		float $balanceAfter,
		?string $posTransactionId,
		string $processedBy,
	): void {
		[$register, $schema] = $this->config(schemaKey: 'giftCardTransaction_schema');
		if ($register === '' || $schema === '') {
			return;
		}

		$payload = [
			'giftCardId' => $giftCardId,
			'type' => $type,
			'amount' => $amount,
			'balanceAfter' => $balanceAfter,
			'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			'posTransactionId' => $posTransactionId,
			'processedBy' => $processedBy,
		];

		try {
			$this->getObjectService()->saveObject(
				object: $payload,
				extend: [],
				register: $register,
				schema: $schema,
				uuid: null
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: gift card transaction log failed',
				['giftCardId' => $giftCardId, 'type' => $type, 'exception' => $e->getMessage()]
			);
		}
	}//end logTransaction()

	/**
	 * Generate a globally-unique serial (GC-XXXXXXXX). Retries on collision.
	 *
	 * @return string
	 */
	private function generateUniqueSerial(): string {
		for ($i = 0; $i < 10; $i++) {
			$candidate = 'GC-' . str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
			if ($this->findBySerial(serial: $candidate) === null) {
				return $candidate;
			}
		}

		throw new RuntimeException('Failed to generate unique gift card serial after 10 retries.');
	}//end generateUniqueSerial()

	/**
	 * Generate a random 6-digit PIN.
	 *
	 * @return string
	 */
	private function generatePin(): string {
		return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
	}//end generatePin()

	/**
	 * Persist a card.
	 *
	 * @param array<string, mixed> $payload The card data.
	 * @param ?string $uuid Update target.
	 *
	 * @return array<string, mixed>
	 */
	private function persistCard(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('GiftCard schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(value: $saved);
	}//end persistCard()

	/**
	 * Resolve register + schema id.
	 *
	 * Fails closed: '' on either id means "unconfigured", and every caller
	 * refuses the OpenRegister call on it. An empty id must never be handed to
	 * OpenRegister — ObjectService skips setRegister()/setSchema() for an empty
	 * value, so the query silently inherits whatever context an earlier call in
	 * the same request left on the shared service instance. The empty case is
	 * logged so an unprovisioned instance is visible rather than silent.
	 *
	 * @param string $schemaKey The schema config key.
	 *
	 * @return array{0: string, 1: string} The [register, schema] ids, each ''
	 *                                     when unconfigured.
	 */
	private function config(string $schemaKey): array {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, $schemaKey, '');
		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning(
				'Pipelinq: register/schema not configured; OpenRegister calls are refused, not run unscoped',
				['schemaKey' => $schemaKey]
			);
		}

		return [$registerId, $schemaId];
	}//end config()

	/**
	 * Extract UUID from an OR entity array.
	 *
	 * @param array<string, mixed> $object The OR object.
	 *
	 * @return ?string
	 */
	private function extractUuid(array $object): ?string {
		$self = $object['@self'] ?? [];
		if (is_array($self) === true && isset($self['id']) === true) {
			return (string)$self['id'];
		}

		return $object['giftCardId'] ?? $object['uuid'] ?? $object['id'] ?? null;
	}//end extractUuid()

	/**
	 * Get the ObjectService.
	 *
	 * @return object
	 */
	private function getObjectService(): object {
		// Injected (ADR-083): a property read throws nothing, so the old
		// catch was unreachable — phpstan reports it as a dead catch.
		return $this->objectService;
	}//end getObjectService()
}//end class
