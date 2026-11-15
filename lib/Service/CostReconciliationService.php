<?php

/**
 * Pipelinq CostReconciliationService.
 *
 * Daily-job-facing service that finds every `message` row flagged
 * `metadata.costCurrencyPending: true` and re-attempts EUR conversion
 * using the now-available exchange rate. Clears the pending flag on
 * success.
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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CostReconciliationServiceInterface;
use OCA\Pipelinq\Util\ConfiguredRegisterSlug;
use OCA\Pipelinq\Util\ContainerObjectService;
use OCA\Pipelinq\Util\PayloadToArray;
use OCA\Pipelinq\Util\SavedPayloadId;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CostReconciliationService — retry deferred EUR conversion.
 *
 * Public entry points:
 * - reconcile() — sweep pending-conversion rows; returns a count
 *   summary.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Bridges OR +
 * exchange-rate + logger.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class CostReconciliationService implements CostReconciliationServiceInterface {
	use PayloadToArray;
	use ConfiguredRegisterSlug;
	use SavedPayloadId;
	use ContainerObjectService;

	/**
	 * Default register slug.
	 */
	private const DEFAULT_REGISTER_SLUG = 'pipelinq';

	/**
	 * Default message schema slug.
	 */
	private const DEFAULT_SCHEMA_SLUG = 'channelMessage';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container.
	 * @param IAppConfig $appConfig App config.
	 * @param MessagingExchangeRateService $exchangeRate Rate service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private MessagingExchangeRateService $exchangeRate,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Reconcile every pending-cost message row.
	 *
	 * @return array{scanned: int, reconciled: int} Summary.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function reconcile(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['scanned' => 0, 'reconciled' => 0];
		}

		$rows = $this->findCandidateRows(objectService: $objectService);
		if ($rows === null) {
			return ['scanned' => 0, 'reconciled' => 0];
		}

		return $this->reconcileRows(objectService: $objectService, rows: $rows);
	}//end reconcile()

	/**
	 * Reconcile each pending-cost row and count the outcomes.
	 *
	 * @param object          $objectService The OpenRegister ObjectService.
	 * @param iterable<mixed> $rows          The candidate rows.
	 *
	 * @return array{scanned: int, reconciled: int} Summary.
	 */
	private function reconcileRows(object $objectService, iterable $rows): array {
		$scanned = 0;
		$reconciled = 0;
		foreach ($rows as $raw) {
			$outcome = $this->reconcileRow(objectService: $objectService, raw: $raw);
			if ($outcome !== null) {
				$scanned++;
			}

			if ($outcome === 'reconciled') {
				$reconciled++;
			}
		}

		return ['scanned' => $scanned, 'reconciled' => $reconciled];
	}//end reconcileRows()

	/**
	 * Reconcile one row when its cost is pending.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param mixed  $raw           The row.
	 *
	 * @return string|null 'reconciled', 'scanned' when it stays pending, null when it was not pending.
	 */
	private function reconcileRow(object $objectService, mixed $raw): string|null {
		$arr = $this->toArray(value: $raw);
		if ($this->isCostPending(row: $arr) === false) {
			return null;
		}

		$converted = $this->convertedRow(row: $arr);
		if ($converted === null) {
			return 'scanned';
		}

		if ($this->saveReconciled(objectService: $objectService, row: $converted) === true) {
			return 'reconciled';
		}

		return 'scanned';
	}//end reconcileRow()

	/**
	 * Whether a message row's cost still waits for currency conversion.
	 *
	 * @param array<string, mixed> $row The message row.
	 *
	 * @return bool
	 */
	private function isCostPending(array $row): bool {
		$meta = (array)($row['metadata'] ?? []);
		return (bool)($meta['costCurrencyPending'] ?? false);
	}//end isCostPending()

	/**
	 * The rows to reconcile: those flagged pending, or every message row
	 * when the metadata filter cannot be queried.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 *
	 * @return mixed The rows, or null when neither query succeeds.
	 */
	private function findCandidateRows(object $objectService): mixed {
		try {
			return $objectService->findAll(
				config: [
					'filters' => [
						'metadata.costCurrencyPending' => true,
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CostReconciliationService.reconcile: findAll failed — falling back to full scan',
				['exception' => $e->getMessage()]
			);
		}

		try {
			return $objectService->findAll(
				config: [
					'filters' => [
						'register' => $this->getRegisterSlug(),
						'schema' => $this->getSchemaSlug(),
					],
				]
			);
		} catch (Throwable $e2) {
			return null;
		}
	}//end findCandidateRows()

	/**
	 * A pending row with its cost converted to EUR, or null when it cannot be.
	 *
	 * @param array<string, mixed> $row The pending message row.
	 *
	 * @return array<string, mixed>|null The reconciled row, or null without a currency, amount or rate.
	 */
	private function convertedRow(array $row): ?array {
		$meta = (array)($row['metadata'] ?? []);
		$currency = (string)($meta['costCurrency'] ?? '');
		$amount = (float)($meta['costSourceAmount'] ?? 0.0);
		if ($currency === '' || $amount <= 0.0) {
			return null;
		}

		$eur = $this->exchangeRate->toEur(amount: $amount, currency: $currency);
		if ($eur === null) {
			return null;
		}

		$row['costEur'] = round($eur, 6);
		$meta['costCurrencyPending'] = false;
		$meta['costReconciledAt'] = $this->nowIso();
		$row['metadata'] = $meta;

		return $row;
	}//end convertedRow()

	/**
	 * Save a reconciled row; a failed save is logged.
	 *
	 * @param object               $objectService OpenRegister's ObjectService.
	 * @param array<string, mixed> $row           The reconciled row.
	 *
	 * @return bool True when the row was saved.
	 */
	private function saveReconciled(object $objectService, array $row): bool {
		$id = $this->extractId(payload: $row);
		try {
			$uuid = $id;
			if ($id === '') {
				$uuid = null;
			}

			$objectService->saveObject(
				object: $row,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $this->getRegisterSlug(), schema: $this->getSchemaSlug()),
					uuid: $uuid
				)
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'CostReconciliationService.reconcile: save failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end saveReconciled()

	/**
	 * Schema slug.
	 *
	 * @return string Slug.
	 */
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'message_schema', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_SCHEMA_SLUG;
	}//end getSchemaSlug()

	/**
	 * Current ISO 8601 UTC timestamp.
	 *
	 * @return string Timestamp.
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}//end nowIso()
}//end class
