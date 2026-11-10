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
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

/**
 * CostCaptureService — webhook-cost extraction with EUR conversion.
 *
 * Public entry points:
 * - capture(webhookPayload, DeliveredMessage) —
 *   returns ['costEur' => float, 'metadata' => array<string, mixed>]
 *   describing what to merge onto the message row.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class CostCaptureService {
	/**
	 * Constructor.
	 *
	 * @param MessagingExchangeRateService $exchangeRate Currency conversion.
	 * @param CostEstimationService $estimator Static price table.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
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
	 * @param DeliveredMessage     $message The message the event reports on.
	 *
	 * @return array{costEur: float, metadata: array<string, mixed>} Cost
	 *                                                               + metadata bundle to merge onto the message row.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function capture(array $payload, DeliveredMessage $message): array {
		// Twilio: { "Price": "-0.0075", "PriceUnit": "USD" }.
		if (isset($payload['Price']) === true && isset($payload['PriceUnit']) === true) {
			return $this->convertedVendorPrice(payload: $payload);
		}

		// Vendors that pre-compute EUR (some BSPs).
		if (isset($payload['costEur']) === true && is_numeric($payload['costEur']) === true) {
			return $this->precomputedEurCost(costEur: (float)$payload['costEur']);
		}

		// Fallback: static price-table estimate (Meta has no cost in webhook).
		return $this->priceTableEstimate(message: $message);
	}//end capture()

	/**
	 * The vendor's own price converted to EUR, or zero pending conversion when no rate is available.
	 *
	 * @param array<string, mixed> $payload Webhook event payload carrying `Price` and `PriceUnit`.
	 *
	 * @return array{costEur: float, metadata: array<string, mixed>} Cost and metadata.
	 */
	private function convertedVendorPrice(array $payload): array {
		$rawPrice = abs((float)$payload['Price']);
		$currency = strtoupper((string)$payload['PriceUnit']);
		$eur = $this->exchangeRate->toEur(amount: $rawPrice, currency: $currency);
		$costEur = 0.0;
		if ($eur !== null) {
			$costEur = round($eur, 6);
		}

		return [
			'costEur' => $costEur,
			'metadata' => [
				'costSource' => 'webhook',
				'costCurrency' => $currency,
				'costSourceAmount' => $rawPrice,
				'costCurrencyPending' => $eur === null,
				'costEstimated' => false,
			],
		];
	}//end convertedVendorPrice()

	/**
	 * The EUR cost a vendor computed itself.
	 *
	 * @param float $costEur The vendor's EUR cost.
	 *
	 * @return array{costEur: float, metadata: array<string, mixed>} Cost and metadata.
	 */
	private function precomputedEurCost(float $costEur): array {
		return [
			'costEur' => $costEur,
			'metadata' => [
				'costSource' => 'webhook',
				'costCurrency' => 'EUR',
				'costEstimated' => false,
			],
		];
	}//end precomputedEurCost()

	/**
	 * The static price-table estimate for the delivered message.
	 *
	 * @param DeliveredMessage $message The delivered message.
	 *
	 * @return array{costEur: float, metadata: array<string, mixed>} Cost and metadata.
	 */
	private function priceTableEstimate(DeliveredMessage $message): array {
		$estimate = $this->estimator->estimate(
			vendor: $message->route->vendor,
			channel: $message->route->channel,
			category: $message->tariff->category,
			country: $message->tariff->country,
		);

		return [
			'costEur' => $estimate,
			'metadata' => [
				'costSource' => 'estimate',
				'costCurrency' => 'EUR',
				'costEstimated' => true,
			],
		];
	}//end priceTableEstimate()
}//end class
