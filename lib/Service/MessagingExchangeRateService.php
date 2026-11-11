<?php

/**
 * Pipelinq MessagingExchangeRateService.
 *
 * Thin wrapper that returns a EUR exchange rate for a source
 * currency (e.g. USD → EUR conversion for Twilio Price). Returns
 * null when no rate is currently available so CostCaptureService
 * can persist the source currency and let the daily
 * CostReconciliationJob retry later.
 *
 * Separate from {@see ExchangeRateService} (forecast roll-ups) so the
 * forecast and messaging cost pipelines remain decoupled.
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

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MessagingExchangeRateService — currency-to-EUR conversion.
 *
 * Public entry points:
 * - toEur(amount, currency) — convert; returns null when no rate
 *   is currently available.
 * - getRate(currency) — current rate or null.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class MessagingExchangeRateService {
	/**
	 * Conservative fallback rates used when no app-config override
	 * exists. Operators override via
	 * `messaging.exchange_rates_json` (a JSON {CCY: rate} map; rate
	 * is "EUR per 1 unit of CCY").
	 *
	 * @var array<string, float>
	 */
	private const FALLBACK_RATES = [
		'EUR' => 1.0,
		'USD' => 0.92,
		'GBP' => 1.17,
		'CHF' => 1.04,
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Convert an amount to EUR using the current rate.
	 *
	 * Returns null when no rate is available; callers persist the
	 * source currency and flag the message for later reconciliation.
	 *
	 * @param float $amount Source amount.
	 * @param string $currency ISO 4217 currency code.
	 *
	 * @return float|null EUR amount or null.
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function toEur(float $amount, string $currency): ?float {
		$currency = strtoupper(trim($currency));
		if ($currency === '' || $currency === 'EUR') {
			return $amount;
		}

		$rate = $this->getRate(currency: $currency);
		if ($rate === null) {
			return null;
		}

		return ($amount * $rate);
	}//end toEur()

	/**
	 * Current EUR exchange rate for a source currency.
	 *
	 * @param string $currency ISO 4217 currency code.
	 *
	 * @return float|null Rate or null.
	 * @spec openspec/specs/outbound-messaging/spec.md#REQ-OM-004
	 */
	public function getRate(string $currency): ?float {
		$currency = strtoupper(trim($currency));
		if ($currency === '') {
			return null;
		}

		return ($this->configuredRate(currency: $currency) ?? (self::FALLBACK_RATES[$currency] ?? null));
	}//end getRate()

	/**
	 * The rate the admin configured for the currency; null when none is
	 * configured, it is not numeric, or the JSON does not parse (logged).
	 *
	 * @param string $currency The upper-cased currency.
	 *
	 * @return float|null
	 */
	private function configuredRate(string $currency): ?float {
		$override = $this->appConfig->getValueString(Application::APP_ID, 'messaging.exchange_rates_json', '');
		if ($override === '') {
			return null;
		}

		try {
			$decoded = json_decode($override, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->warning('MessagingExchangeRateService.getRate: invalid exchange_rates_json', ['exception' => $e->getMessage()]);
			return null;
		}

		return $this->rateIn(rates: $decoded, currency: $currency);
	}//end configuredRate()

	/**
	 * The currency's numeric rate in a decoded rates object; null otherwise.
	 *
	 * @param mixed  $rates    The decoded JSON.
	 * @param string $currency The upper-cased currency.
	 *
	 * @return float|null
	 */
	private function rateIn($rates, string $currency): ?float {
		if (is_array($rates) === true && isset($rates[$currency]) === true && is_numeric($rates[$currency]) === true) {
			return (float)$rates[$currency];
		}

		return null;
	}//end rateIn()
}//end class
