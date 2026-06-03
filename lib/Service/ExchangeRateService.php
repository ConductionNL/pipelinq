<?php

/**
 * Pipelinq ExchangeRateService.
 *
 * Fetches and caches ECB daily reference rates for currency conversion to EUR.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Pipelinq\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Converts provider-reported costs into EUR using ECB daily reference rates.
 *
 * Rates are fetched once per day from the ECB daily reference XML feed and
 * cached in app config. A null return signals the rate is currently
 * unavailable, so the caller persists the cost in source currency for later
 * reconciliation (REQ-007).
 *
 * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
 */
class ExchangeRateService
{
    /**
     * The ECB daily reference rates feed URL.
     *
     * @var string
     */
    private const ECB_FEED = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /**
     * App-config key under which the cached rate map (JSON) is stored.
     *
     * @var string
     */
    private const CACHE_KEY = 'ecb_rate_cache';

    /**
     * App-config key under which the cache date (Y-m-d) is stored.
     *
     * @var string
     */
    private const CACHE_DATE_KEY = 'ecb_rate_cache_date';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService The HTTP client service.
     * @param IAppConfig      $appConfig     The app config (rate cache).
     * @param LoggerInterface $logger        The logger.
     */
    public function __construct(
        private IClientService $clientService,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Convert an amount in a source currency to EUR.
     *
     * @param float  $amount   The amount in the source currency.
     * @param string $currency The ISO 4217 source currency.
     *
     * @return float|null The EUR amount, or null when no rate is available.
     *
     * @spec openspec/changes/whatsapp-sms-channel-adapter/tasks.md#task-5.5
     */
    public function toEur(float $amount, string $currency): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'EUR') {
            return round($amount, 6);
        }

        $rate = $this->rateForCurrency(currency: $currency);
        if ($rate === null || $rate <= 0.0) {
            return null;
        }

        return round(($amount / $rate), 6);
    }//end toEur()

    /**
     * The ECB rate (units of $currency per 1 EUR) for a currency.
     *
     * @param string $currency The ISO 4217 currency.
     *
     * @return float|null The rate, or null when unavailable.
     */
    public function rateForCurrency(string $currency): ?float
    {
        $rates = $this->rates();

        return ($rates[strtoupper($currency)] ?? null);
    }//end rateForCurrency()

    /**
     * The current rate map (currency => units-per-EUR), cached daily.
     *
     * @return array<string, float> The rate map (empty when unavailable).
     */
    public function rates(): array
    {
        $today  = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $cached = $this->appConfig->getValueString(Application::APP_ID, self::CACHE_DATE_KEY, '');
        if ($cached === $today) {
            $decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::CACHE_KEY, '{}'), true);
            if (is_array($decoded) === true) {
                return $this->floatMap(map: $decoded);
            }
        }

        $rates = $this->fetchRates();
        if ($rates === []) {
            // Fall back to the last cached map even if stale.
            $decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::CACHE_KEY, '{}'), true);
            if (is_array($decoded) === true) {
                return $this->floatMap(map: $decoded);
            }

            return [];
        }

        $this->appConfig->setValueString(Application::APP_ID, self::CACHE_KEY, json_encode($rates));
        $this->appConfig->setValueString(Application::APP_ID, self::CACHE_DATE_KEY, $today);

        return $rates;
    }//end rates()

    /**
     * Fetch and parse the ECB daily reference rates feed.
     *
     * @return array<string, float> The parsed rate map (empty on failure).
     */
    private function fetchRates(): array
    {
        try {
            $client   = $this->clientService->newClient();
            $response = $client->get(self::ECB_FEED, ['timeout' => 15]);
            $xml      = simplexml_load_string((string) $response->getBody());
            if ($xml === false) {
                return [];
            }
        } catch (Throwable $e) {
            $this->logger->warning('ECB rate fetch failed', ['exception' => $e->getMessage()]);
            return [];
        }

        $xml->registerXPathNamespace('ecb', 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref');
        $cubes = $xml->xpath('//ecb:Cube[@currency]');
        if (is_array($cubes) === false) {
            return [];
        }

        $rates = [];
        foreach ($cubes as $cube) {
            $currency = (string) $cube['currency'];
            $rate     = (float) $cube['rate'];
            if ($currency !== '' && $rate > 0.0) {
                $rates[strtoupper($currency)] = $rate;
            }
        }

        return $rates;
    }//end fetchRates()

    /**
     * Coerce a decoded map to a float-valued rate map.
     *
     * @param array<mixed, mixed> $map The decoded map.
     *
     * @return array<string, float> The float-valued rate map.
     */
    private function floatMap(array $map): array
    {
        $rates = [];
        foreach ($map as $key => $value) {
            if (is_string($key) === false) {
                continue;
            }

            $isNumericString = (is_string($value) === true && is_numeric($value) === true);
            if (is_int($value) === true || is_float($value) === true || $isNumericString === true) {
                $rates[$key] = (float) $value;
            }
        }

        return $rates;
    }//end floatMap()
}//end class
