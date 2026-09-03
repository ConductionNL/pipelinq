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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-007
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Gift card lifecycle.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Cohesive gift-card lifecycle; splitting would fragment a single domain.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006
 */
class GiftCardService {
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
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006-01
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
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006-03
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
		$status = (string)($card['status'] ?? '');
		if ($status === 'active') {
			return $card;
		}

		if ($status !== 'issued') {
			throw new RuntimeException('Gift card cannot be activated from status "' . $status . '".');
		}

		$card['status'] = 'active';
		$saved = $this->persistCard(payload: $card, uuid: $giftCardId);

		// No new ledger entry required for activation per REQ-LOY-006-03; the
		// initial 'issue' entry already records balansNa.
		$this->logger->info(
			'Pipelinq: gift card activated',
			['giftCardId' => $giftCardId, 'posTransactionId' => $posTransactionId]
		);

		return $saved;
	}//end activateGiftCard()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential validation guards; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Sequential validation guards; extraction adds no clarity.
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-007-01
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-007-02
	 */
	public function redeemGiftCard(
		string $giftCardId,
		string $pin,
		float $amount,
		?string $posTransactionId = null,
	): array {
		if ($amount <= 0) {
			throw new RuntimeException('Redemption amount must be positive.');
		}

		$card = $this->getCard(giftCardId: $giftCardId);
		if ($card === null) {
			throw new RuntimeException('Gift card not found.');
		}

		$status = (string)($card['status'] ?? '');
		if ($status === 'blocked') {
			throw new RuntimeException('Gift card is blocked.');
		}

		if (in_array($status, ['active'], true) === false) {
			throw new RuntimeException('Gift card is not active.');
		}

		$expiresOn = (string)($card['expiresOn'] ?? '');
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		if ($expiresOn !== '' && $expiresOn < $now) {
			throw new RuntimeException('Gift card has expired.');
		}

		if ($this->verifyPin(plaintext: $pin, hash: (string)($card['pin'] ?? '')) === false) {
			throw new RuntimeException('Invalid PIN.');
		}

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
			giftCardId: $giftCardId,
			posTransactionId: $posTransactionId
		);
		if ($replay !== null) {
			return $replay;
		}

		$balance = (float)($card['currentBalance'] ?? 0);
		$applied = min($amount, $balance);
		$change = max(0.0, $amount - $applied);
		$after = round($balance - $applied, 2);

		$card['currentBalance'] = $after;
		$type = 'partial_redeem';
		if ($after <= 0.0) {
			$after = 0.0;
			$card['currentBalance'] = 0.0;
			$card['status'] = 'depleted';
			// When the requested amount equals the balance and consumes everything → type "redeem".
			if (abs($applied - $balance) < 0.005 && $change === 0.0) {
				$type = 'redeem';
			}
		}

		$this->persistCard(payload: $card, uuid: $giftCardId);

		$this->logTransaction(
			giftCardId: $giftCardId,
			type: $type,
			amount: $applied,
			balanceAfter: $after,
			posTransactionId: $posTransactionId,
			processedBy: 'gift-card-service'
		);

		return [
			'amountApplied' => $applied,
			'balanceAfter' => $after,
			'changeAmount' => $change,
			'status' => (string)($card['status'] ?? 'active'),
		];
	}//end redeemGiftCard()

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
		if ($posTransactionId === null || $posTransactionId === '') {
			return null;
		}

		[$register, $schema] = $this->config(schemaKey: 'giftCardTransaction_schema');
		if ($register === '' || $schema === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'giftCardId' => $giftCardId,
						'posTransactionId' => $posTransactionId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		foreach ((array)$rows as $row) {
			$replay = $this->replayFromTransactionRow(row: $row);
			if ($replay !== null) {
				return $replay;
			}
		}

		return null;
	}//end findRedemptionByPosTransaction()

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
		$array = $row;
		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$array = $row->jsonSerialize();
		}

		if (is_array($array) === false) {
			return null;
		}

		$type = (string)($array['type'] ?? '');
		if ($type !== 'redeem' && $type !== 'partial_redeem') {
			return null;
		}

		$balanceAfter = (float)($array['balanceAfter'] ?? 0);
		$status = 'active';
		if ($balanceAfter <= 0.0) {
			$status = 'depleted';
		}

		return [
			'amountApplied' => (float)($array['amount'] ?? 0),
			'balanceAfter' => $balanceAfter,
			'changeAmount' => 0.0,
			'status' => $status,
		];
	}//end replayFromTransactionRow()

	/**
	 * Refund an amount onto a card.
	 *
	 * @param string $giftCardId The card UUID.
	 * @param float $amount Amount to refund (positive).
	 * @param ?string $posTransactionId Optional POS transaction id.
	 *
	 * @return array<string, mixed> The updated card.
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006
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
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006
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
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-006
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
			return ['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => 'Not found'];
		}

		$status = (string)($card['status'] ?? '');
		if (in_array($status, ['blocked'], true) === true) {
			return [
				'valid' => false,
				'balance' => (float)($card['currentBalance'] ?? 0),
				'expiryDate' => ($card['expiresOn'] ?? null),
				'giftCardId' => $this->extractUuid(object: $card),
				'reason' => 'Blocked',
			];
		}

		$expiresOn = (string)($card['expiresOn'] ?? '');
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		if ($expiresOn !== '' && $expiresOn < $now) {
			return [
				'valid' => false,
				'balance' => (float)($card['currentBalance'] ?? 0),
				'expiryDate' => $expiresOn,
				'giftCardId' => $this->extractUuid(object: $card),
				'reason' => 'Expired',
			];
		}

		if ($this->verifyPin(plaintext: $pin, hash: (string)($card['pin'] ?? '')) === false) {
			return ['valid' => false, 'balance' => 0.0, 'expiryDate' => null, 'giftCardId' => null, 'reason' => 'Invalid PIN'];
		}

		$expiryDate = null;
		if ($expiresOn !== '') {
			$expiryDate = $expiresOn;
		}

		return [
			'valid' => true,
			'balance' => (float)($card['currentBalance'] ?? 0),
			'expiryDate' => $expiryDate,
			'giftCardId' => $this->extractUuid(object: $card),
			'reason' => null,
		];
	}//end validateBySerial()

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

		return $this->toArray(object: $object);
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
		[$register, $schema] = $this->config(schemaKey: 'giftCard_schema');
		if ($register === '' || $schema === '' || $serial === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'serial' => $serial,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		if (is_array($rows) === false) {
			$rows = [];
		}

		$rows = array_values($rows);
		if ($rows === []) {
			return null;
		}

		return $this->toArray(object: reset($rows));
	}//end findBySerial()

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

		return $this->toArray(object: $saved);
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
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');
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
	 * Normalise OR entity/array to array.
	 *
	 * @param mixed $object The OR entity or array.
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $object): array {
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		if (is_object($object) === true && method_exists($object, 'getObject') === true) {
			$decoded = $object->getObject();
			if (is_array($decoded) === true) {
				return $decoded;
			}
		}

		return [];
	}//end toArray()

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
