<?php

/**
 * Pipelinq RedemptionService.
 *
 * Owns the Redemption lifecycle (gereserveerd → gebruikt | vervallen | geannuleerd).
 * Atomically debits points via PointsLedgerService and generates a unique
 * rewardCode. Cancellation refunds points.
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
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004
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
 * Redemption lifecycle service.
 *
 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) orchestrates the full
 *  redemption lifecycle (reserve/use/cancel/expire) across account + ledger
 *  services.
 */
class RedemptionService {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param LoyaltyAccountService $accountService The account service.
	 * @param PointsLedgerService $ledgerService The ledger service.
	 * @param LoggerInterface $logger The logger.
	 * @param ObjectServiceInterface $objectService The OpenRegister object service.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoyaltyAccountService $accountService,
		private PointsLedgerService $ledgerService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Initiate a redemption — reserve points + create a Redemption with status "reserved".
	 *
	 * @param string $accountId The account UUID.
	 * @param string $optionId The RedemptionOption UUID.
	 *
	 * @return array<string, mixed> The Redemption object.
	 *
	 * @throws RuntimeException On insufficient balance, expired option, per-customer limit reached.
	 *
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004-01
	 */
	public function initiateRedemption(string $accountId, string $optionId): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		if ($account === null) {
			throw new RuntimeException('Account not found.');
		}

		$option = $this->getOption(optionId: $optionId);
		if ($option === null) {
			throw new RuntimeException('Redemption option not found.');
		}

		$cost = $this->assertRedemptionEligible(account: $account, option: $option, accountId: $accountId, optionId: $optionId);

		$code = $this->generateBeloningCode();
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$redemption = [
			'accountId' => $accountId,
			'customerId' => $account['customerId'] ?? null,
			'optionId' => $optionId,
			'programmeId' => $option['programmeId'] ?? null,
			'costInPoints' => $cost,
			'rewardCode' => $code,
			'status' => 'reserved',
			'initiatedOn' => $now,
			'validTo' => $this->codeExpiryDefault(),
		];

		$saved = $this->persist(payload: $redemption, uuid: null);

		// Debit AFTER the redemption record exists so the brondocument points at the redemption.
		$redemptionUuid = $this->extractUuid(object: $saved);
		try {
			$this->ledgerService->debitPoints(
				accountId: $accountId,
				amount: $cost,
				redemptionId: (string)$redemptionUuid,
				sourceDocument: ['optionId' => $optionId, 'rewardCode' => $code],
				processedBy: 'redemption-service'
			);
		} catch (\Throwable $e) {
			// Roll back the Redemption record on debit failure.
			$this->logger->warning(
				'Pipelinq: redemption debit failed; rolling back Redemption',
				['accountId' => $accountId, 'optionId' => $optionId, 'exception' => $e->getMessage()]
			);
			if ($redemptionUuid !== null) {
				$this->deleteRedemption(redemptionId: (string)$redemptionUuid);
			}

			throw new RuntimeException('Debit failed: ' . $e->getMessage(), 0, $e);
		}

		return $saved;
	}//end initiateRedemption()

	/**
	 * Assert an account is eligible to redeem an option and return its cost.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param array<string, mixed> $option The redemption option.
	 * @param string $accountId The account UUID.
	 * @param string $optionId The option UUID.
	 *
	 * @return int The cost in points.
	 *
	 * @throws RuntimeException On inactive account, invalid option, insufficient balance, or limit reached.
	 */
	private function assertRedemptionEligible(array $account, array $option, string $accountId, string $optionId): int {
		if ((string)($account['status'] ?? '') !== 'active') {
			throw new RuntimeException('Account is not active.');
		}

		if ($this->isOptionValid(option: $option) === false) {
			throw new RuntimeException('Redemption option is not currently valid.');
		}

		$cost = (int)($option['costInPoints'] ?? 0);
		if ((int)($account['currentBalance'] ?? 0) < $cost) {
			throw new RuntimeException('Insufficient balance.');
		}

		$perCustomerLimit = $option['perCustomerLimit'] ?? null;
		if ($perCustomerLimit !== null && (int)$perCustomerLimit > 0) {
			$usedCount = $this->countUsedRedemptions(accountId: $accountId, optionId: $optionId);
			if ($usedCount >= (int)$perCustomerLimit) {
				throw new RuntimeException('Redemption limit reached for this option.');
			}
		}

		return $cost;
	}//end assertRedemptionEligible()

	/**
	 * Validate a redemption code (without consuming it).
	 *
	 * @param string $code The beloningCode.
	 *
	 * @return array{valid: bool, redemption: ?array<string, mixed>, reason: ?string}
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004
	 */
	public function validateCode(string $code): array {
		$redemption = $this->findByCode(code: $code);
		if ($redemption === null) {
			return ['valid' => false, 'redemption' => null, 'reason' => 'Code not found'];
		}

		$status = (string)($redemption['status'] ?? '');
		if ($status !== 'reserved') {
			return ['valid' => false, 'redemption' => $redemption, 'reason' => 'Status is ' . $status];
		}

		$validTo = (string)($redemption['validTo'] ?? '');
		$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		if ($validTo !== '' && $validTo < $now) {
			// Mark as expired in place.
			$this->markExpired(redemptionId: $this->extractUuid(object: $redemption) ?? '');
			return ['valid' => false, 'redemption' => $redemption, 'reason' => 'Redemption code expired'];
		}

		return ['valid' => true, 'redemption' => $redemption, 'reason' => null];
	}//end validateCode()

	/**
	 * Mark a redemption as used.
	 *
	 * @param string $redemptionId The Redemption UUID.
	 * @param ?string $posTransactionId Optional POS transaction id.
	 *
	 * @return array<string, mixed> The updated redemption.
	 * @spec openspec/changes/loyalty-program/specs.md#REQ-LOY-004
	 */
	public function markRedemptionUsed(string $redemptionId, ?string $posTransactionId = null): array {
		$redemption = $this->getRedemption(redemptionId: $redemptionId);
		if ($redemption === null) {
			throw new RuntimeException('Redemption not found.');
		}

		// Idempotent: if already used, return as-is.
		if ((string)($redemption['status'] ?? '') === 'used') {
			return $redemption;
		}

		$redemption['status'] = 'used';
		$redemption['usedOn'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
		$redemption['posTransactionId'] = $posTransactionId;

		return $this->persist(payload: $redemption, uuid: $redemptionId);
	}//end markRedemptionUsed()

	/*
	 * NO cancelRedemption() HERE.
	 *
	 * It reversed a reserved redemption and refunded its points through
	 * LedgerService::refundPoints(). It had no caller: `LoyaltyController`
	 * exposes getRedemptionOptions / initiateRedemption / lookupRedemptionCode
	 * / useRedemptionCode and no cancel route, and no loyalty-program
	 * requirement asks for one. Wiring it would have meant adding a new
	 * points-crediting endpoint with no authorization design behind it —
	 * a widening of the write surface, not a repair.
	 */

	/**
	 * Mark a redemption as expired (no refund — customer didn't use it).
	 *
	 * @param string $redemptionId The Redemption UUID.
	 *
	 * @return ?array<string, mixed>
	 * @spec exclude loyalty has no spec at all; the change that specified it was archived
	 *   and nothing inherited it
	 */
	public function expireRedemption(string $redemptionId): ?array {
		return $this->markExpired(redemptionId: $redemptionId);
	}//end expireRedemption()

	/**
	 * List options the account can afford and that are currently valid.
	 *
	 * @param string $accountId The account UUID.
	 * @param string $programmeId The programme UUID.
	 *
	 * @return array<int, array<string, mixed>> Valid + affordable options.
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function getValidRedemptionOptions(string $accountId, string $programmeId): array {
		$account = $this->accountService->getAccount(accountId: $accountId);
		$balance = 0;
		if ($account !== null) {
			$balance = (int)($account['currentBalance'] ?? 0);
		}

		[$register, $schema] = $this->config(schemaKey: 'redemptionOption_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'programmeId' => $programmeId,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 200,
				]
			);
		} catch (\Throwable $e) {
			return [];
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		$options = array_map([$this, 'toArray'], $rowList);

		return array_values(
			array_filter(
				$options,
				fn (array $option): bool => $this->isOptionValid(option: $option)
					&& (int)($option['costInPoints'] ?? 0) <= $balance
			)
		);
	}//end getValidRedemptionOptions()

	/**
	 * Get a Redemption by UUID.
	 *
	 * @param string $redemptionId The Redemption UUID.
	 *
	 * @return array<string, mixed>|null
	 * @spec exclude loyalty has no spec at all; the change that specified it was archived
	 *   and nothing inherited it
	 */
	public function getRedemption(string $redemptionId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '' || $redemptionId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $redemptionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end getRedemption()

	/**
	 * Find a Redemption by its beloningCode.
	 *
	 * @param string $code The code.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec exclude phpmd mechanical refactor
	 */
	public function findByCode(string $code): ?array {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '' || $code === '') {
			return null;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'rewardCode' => $code,
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1,
				]
			);
		} catch (\Throwable $e) {
			return null;
		}

		$rowList = [];
		if (is_array($rows) === true) {
			$rowList = array_values($rows);
		}

		if ($rowList === []) {
			return null;
		}

		return $this->toArray(object: reset($rowList));
	}//end findByCode()

	/**
	 * Mark a Redemption as vervallen.
	 *
	 * @param string $redemptionId The Redemption UUID.
	 *
	 * @return ?array<string, mixed>
	 */
	private function markExpired(string $redemptionId): ?array {
		$redemption = $this->getRedemption(redemptionId: $redemptionId);
		if ($redemption === null) {
			return null;
		}

		if ((string)($redemption['status'] ?? '') === 'lapsed') {
			return $redemption;
		}

		$redemption['status'] = 'lapsed';
		return $this->persist(payload: $redemption, uuid: $redemptionId);
	}//end markExpired()

	/**
	 * Count "used" redemptions for an (account, option) pair.
	 *
	 * @param string $accountId The account UUID.
	 * @param string $optionId The option UUID.
	 *
	 * @return int
	 */
	private function countUsedRedemptions(string $accountId, string $optionId): int {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '') {
			return 0;
		}

		try {
			$rows = $this->getObjectService()->findAll(
				config: [
					'filters' => [
						'accountId' => $accountId,
						'optionId' => $optionId,
						'status' => 'used',
						'register' => $register,
						'schema' => $schema,
					],
					'limit' => 1000,
				]
			);
		} catch (\Throwable $e) {
			return 0;
		}

		if (is_array($rows) === true) {
			return count($rows);
		}

		return 0;
	}//end countUsedRedemptions()

	/**
	 * Whether a RedemptionOption is currently valid (geldigVan/geldigTot).
	 *
	 * @param array<string, mixed> $option The option.
	 *
	 * @return bool
	 */
	private function isOptionValid(array $option): bool {
		$today = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
		$from = (string)($option['validFrom'] ?? '');
		$to = (string)($option['validTo'] ?? '');
		if ($from !== '' && $today < $from) {
			return false;
		}

		if ($to !== '' && $today > $to) {
			return false;
		}

		return true;
	}//end isOptionValid()

	/**
	 * Get a RedemptionOption.
	 *
	 * @param string $optionId The option UUID.
	 *
	 * @return array<string, mixed>|null
	 */
	private function getOption(string $optionId): ?array {
		[$register, $schema] = $this->config(schemaKey: 'redemptionOption_schema');
		if ($register === '' || $schema === '' || $optionId === '') {
			return null;
		}

		try {
			$object = $this->getObjectService()->find(id: $optionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			return null;
		}

		if ($object === null) {
			return null;
		}

		return $this->toArray(object: $object);
	}//end getOption()

	/**
	 * Generate a unique beloningCode (RDM-XXXXXXXX).
	 *
	 * @return string
	 */
	private function generateBeloningCode(): string {
		return 'RDM-' . strtoupper(bin2hex(random_bytes(4)));
	}//end generateBeloningCode()

	/**
	 * Default expiry for a fresh redemption code (24 hours).
	 *
	 * @return string ISO timestamp.
	 */
	private function codeExpiryDefault(): string {
		return (new DateTimeImmutable('+24 hours', new DateTimeZone('UTC')))->format('c');
	}//end codeExpiryDefault()

	/**
	 * Delete a redemption (used only on rollback).
	 *
	 * @param string $redemptionId The Redemption UUID.
	 *
	 * @return void
	 */
	private function deleteRedemption(string $redemptionId): void {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '' || $redemptionId === '') {
			return;
		}

		try {
			$this->getObjectService()->deleteObject(uuid: $redemptionId, register: $register, schema: $schema);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Pipelinq: redemption rollback delete failed',
				['redemptionId' => $redemptionId, 'exception' => $e->getMessage()]
			);
		}
	}//end deleteRedemption()

	/**
	 * Persist a redemption.
	 *
	 * @param array<string, mixed> $payload The data.
	 * @param ?string $uuid Update target.
	 *
	 * @return array<string, mixed>
	 */
	private function persist(array $payload, ?string $uuid): array {
		[$register, $schema] = $this->config(schemaKey: 'redemption_schema');
		if ($register === '' || $schema === '') {
			throw new RuntimeException('Redemption schema is not configured.');
		}

		$saved = $this->getObjectService()->saveObject(
			object: $payload,
			extend: [],
			register: $register,
			schema: $schema,
			uuid: $uuid
		);

		return $this->toArray(object: $saved);
	}//end persist()

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

		return $object['redemptionId'] ?? $object['uuid'] ?? $object['id'] ?? null;
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
			$data = $object->getObject();
			if (is_array($data) === true) {
				return $data;
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
