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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.3
 */
class CostReconciliationService {
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
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.3
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
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#7.3
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential per-row guard clauses
	 *  over the pending-cost scan; extraction adds no clarity.
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Same flat per-row guards; path count is
	 *  a product of independent skip conditions, not nesting.
	 */
	public function reconcile(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['scanned' => 0, 'reconciled' => 0];
		}

		try {
			$rows = $objectService->findAll(
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

			try {
				$rows = $objectService->findAll(
					config: [
						'filters' => [
							'register' => $this->getRegisterSlug(),
							'schema' => $this->getSchemaSlug(),
						],
					]
				);
			} catch (Throwable $e2) {
				return ['scanned' => 0, 'reconciled' => 0];
			}
		}//end try

		$scanned = 0;
		$reconciled = 0;
		foreach (($rows ?? []) as $raw) {
			$arr = $this->toArray(value: $raw);
			$meta = (array)($arr['metadata'] ?? []);
			$pending = (bool)($meta['costCurrencyPending'] ?? false);
			if ($pending === false) {
				continue;
			}

			$scanned++;
			$currency = (string)($meta['costCurrency'] ?? '');
			$amount = (float)($meta['costSourceAmount'] ?? 0.0);
			if ($currency === '' || $amount <= 0.0) {
				continue;
			}

			$eur = $this->exchangeRate->toEur(amount: $amount, currency: $currency);
			if ($eur === null) {
				continue;
			}

			$arr['costEur'] = round($eur, 6);
			$meta['costCurrencyPending'] = false;
			$meta['costReconciledAt'] = $this->nowIso();
			$arr['metadata'] = $meta;

			$id = $this->extractId(payload: $arr);
			try {
				$uuid = $id;
				if ($id === '') {
					$uuid = null;
				}

				$objectService->saveObject(
					object: $arr,
					register: $this->getRegisterSlug(),
					schema: $this->getSchemaSlug(),
					uuid: $uuid,
				);
				$reconciled++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'CostReconciliationService.reconcile: save failed',
					['id' => $id, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return ['scanned' => $scanned, 'reconciled' => $reconciled];
	}//end reconcile()

	/**
	 * Resolve OpenRegister ObjectService.
	 *
	 * @return object|null Service or null.
	 */
	private function getObjectService(): ?object {
		try {
			return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$this->logger->warning(
				'CostReconciliationService.getObjectService: OpenRegister unavailable',
				['exception' => $e->getMessage()]
			);
			return null;
		}
	}//end getObjectService()

	/**
	 * Normalise an OR entity to an array.
	 *
	 * @param mixed $value Entity or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$serialised = $value->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($value) === true && method_exists($value, 'getObject') === true) {
			$payload = $value->getObject();
			if (is_array($payload) === true) {
				return $payload;
			}
		}

		return [];
	}//end toArray()

	/**
	 * Extract a UUID / id / slug from a payload.
	 *
	 * @param array<string, mixed> $payload Payload.
	 *
	 * @return string Id or empty.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Flat fallback chain over candidate
	 *  id keys; each branch is an independent early return.
	 */
	private function extractId(array $payload): string {
		foreach (['uuid', 'id', 'slug'] as $key) {
			if (isset($payload[$key]) === true && is_scalar($payload[$key]) === true && (string)$payload[$key] !== '') {
				return (string)$payload[$key];
			}
		}

		if (isset($payload['@self']) === true && is_array($payload['@self']) === true) {
			foreach (['uuid', 'id', 'slug'] as $key) {
				$value = ($payload['@self'][$key] ?? null);
				if (is_scalar($value) === true && (string)$value !== '') {
					return (string)$value;
				}
			}
		}

		return '';
	}//end extractId()

	/**
	 * Register slug.
	 *
	 * @return string Slug.
	 */
	private function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		if ($slug !== '') {
			return $slug;
		}

		return self::DEFAULT_REGISTER_SLUG;
	}//end getRegisterSlug()

	/**
	 * Schema slug.
	 *
	 * @return string Slug.
	 */
	private function getSchemaSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'message_schema', '');
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
