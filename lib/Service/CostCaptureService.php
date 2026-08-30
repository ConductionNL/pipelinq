<?php

/**
 * Pipelinq CostCaptureService.
 *
 * Extracts cost data from provider delivery webhooks and writes it
 * to the message row in EUR. Falls back to
 * {@see CostEstimationService} for vendors that do not return cost
 * (Meta Cloud API). Marks pending-conversion when the live exchange
 * rate is unavailable so the CostReconciliationJob can retry.
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
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * CostCaptureService — webhook-cost extraction with EUR conversion.
 *
 * Public entry points:
 * - capture(webhookPayload, vendor, channel, category, country) —
 *   returns ['costEur' => float, 'metadata' => array<string, mixed>]
 *   describing what to merge onto the message row.
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.5
 */
class CostCaptureService {
	/**
	 * Constructor.
	 *
	 * @param MessagingExchangeRateService $exchangeRate Currency conversion.
	 * @param CostEstimationService $estimator Static price table.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.5
	 */
	public function __construct(
		private MessagingExchangeRateService $exchangeRate,
		private CostEstimationService $estimator,
	) {
	}//end __construct()

	/**
	 * Capture the EUR cost for a webhook delivery event.
	 *
	 * Logic:
	 * 1. If `Price` and `PriceUnit` are present (Twilio), convert.
	 * 2. If provider exposed a `costEur` directly, take it.
	 * 3. Otherwise estimate via the price table.
	 *
	 * @param array<string, mixed> $payload Webhook event payload.
	 * @param string $vendor Provider vendor key.
	 * @param string $channel Channel (whatsapp / sms).
	 * @param string $category Template category.
	 * @param string $country ISO 3166-1 alpha-2.
	 *
	 * @return array{costEur: float, metadata: array<string, mixed>} Cost
	 *                                                               + metadata bundle to merge onto the message row.
	 *
	 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#5.5
	 */
	public function capture(
		array $payload,
		string $vendor,
		string $channel,
		string $category = 'utility',
		string $country = 'NL',
	): array {
		// Twilio: { "Price": "-0.0075", "PriceUnit": "USD" }.
		if (isset($payload['Price']) === true && isset($payload['PriceUnit']) === true) {
			$rawPrice = abs((float)$payload['Price']);
			$currency = strtoupper((string)$payload['PriceUnit']);
			$eur = $this->exchangeRate->toEur(amount: $rawPrice, currency: $currency);
			if ($eur === null) {
				return [
					'costEur' => 0.0,
					'metadata' => [
						'costSource' => 'webhook',
						'costCurrency' => $currency,
						'costSourceAmount' => $rawPrice,
						'costCurrencyPending' => true,
						'costEstimated' => false,
					],
				];
			}

			return [
				'costEur' => round($eur, 6),
				'metadata' => [
					'costSource' => 'webhook',
					'costCurrency' => $currency,
					'costSourceAmount' => $rawPrice,
					'costCurrencyPending' => false,
					'costEstimated' => false,
				],
			];
		}//end if

		// Vendors that pre-compute EUR (some BSPs).
		if (isset($payload['costEur']) === true && is_numeric($payload['costEur']) === true) {
			return [
				'costEur' => (float)$payload['costEur'],
				'metadata' => [
					'costSource' => 'webhook',
					'costCurrency' => 'EUR',
					'costEstimated' => false,
				],
			];
		}

		// Fallback: static price-table estimate (Meta has no cost in webhook).
		$estimate = $this->estimator->estimate(
			vendor: $vendor,
			channel: $channel,
			category: $category,
			country: $country,
		);

		return [
			'costEur' => $estimate,
			'metadata' => [
				'costSource' => 'estimate',
				'costCurrency' => 'EUR',
				'costEstimated' => true,
			],
		];
	}//end capture()
}//end class
