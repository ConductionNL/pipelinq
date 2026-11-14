<?php

/**
 * Pipelinq CostEstimationService.
 *
 * Static price-table lookup for messaging providers that do NOT
 * expose per-message cost in the delivery webhook (Meta Cloud API in
 * particular). Maps (vendor, channel, category, country) tuples to
 * an indicative EUR cost so BudgetService can still track spend.
 *
 * The price table is intentionally small and conservative — operators
 * can override it via app-config `messaging.price_table_json` (a JSON
 * blob of the same shape).
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

use OCA\Pipelinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * CostEstimationService — price-table fallback for cost capture.
 *
 * Public entry points:
 * - estimate(vendor, channel, category, country) — EUR estimate
 *   or `0.0` when the table has no entry.
 *
 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
 */
class CostEstimationService {
	/**
	 * Built-in price table (EUR per message). Operators override via
	 * `messaging.price_table_json` app-config.
	 *
	 * @var array<string, array<string, array<string, array<string, float>>>>
	 */
	private const DEFAULT_PRICE_TABLE = [
		'meta' => [
			'whatsapp' => [
				'utility' => [
					'NL' => 0.012,
					'BE' => 0.012,
					'DE' => 0.015,
					'default' => 0.020,
				],
				'marketing' => [
					'NL' => 0.060,
					'BE' => 0.060,
					'DE' => 0.070,
					'default' => 0.080,
				],
				'authentication' => [
					'NL' => 0.015,
					'BE' => 0.015,
					'DE' => 0.018,
					'default' => 0.022,
				],
			],
		],
		'360dialog' => [
			'whatsapp' => [
				'utility' => ['default' => 0.025],
				'marketing' => ['default' => 0.090],
				'authentication' => ['default' => 0.025],
			],
		],
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
	 * Estimate the EUR cost of one message for the given dimensions.
	 *
	 * Falls through the table from most-specific to least-specific:
	 * country → `default` country → vendor `default` → `0.0`.
	 *
	 * @param string $vendor Vendor (meta, twilio, ...).
	 * @param string $channel Channel (whatsapp, sms).
	 * @param string $category Template category (utility, marketing, authentication).
	 * @param string $country ISO 3166-1 alpha-2 country code (NL, BE, ...).
	 *
	 * @return float Estimated EUR cost (0.0 when nothing matches).
	 *
	 * @spec openspec/specs/whatsapp-sms-channel-adapter/spec.md
	 */
	public function estimate(
		string $vendor,
		string $channel,
		string $category = 'utility',
		string $country = 'NL',
	): float {
		$table = $this->loadTable();

		$catRow = $this->categoryRow(table: $table, vendor: $vendor, channel: $channel, category: $category);
		if (is_array($catRow) === false) {
			if (is_numeric($catRow) === true) {
				return (float)$catRow;
			}

			return 0.0;
		}

		return $this->countryPrice(catRow: $catRow, country: $country);
	}//end estimate()

	/**
	 * The table entry for a category (falling back to the channel default), or null when the vendor or channel is missing.
	 *
	 * @param array<string, mixed> $table    The price table.
	 * @param string               $vendor   Vendor.
	 * @param string               $channel  Channel.
	 * @param string               $category Template category.
	 *
	 * @return mixed
	 */
	private function categoryRow(array $table, string $vendor, string $channel, string $category): mixed {
		$channelRow = $this->channelRow(table: $table, vendor: $vendor, channel: $channel);
		if ($channelRow === null) {
			return null;
		}

		return ($channelRow[$category] ?? ($channelRow['default'] ?? null));
	}//end categoryRow()

	/**
	 * The table entry for a vendor's channel, or null when either is missing.
	 *
	 * @param array<string, mixed> $table   The price table.
	 * @param string               $vendor  Vendor.
	 * @param string               $channel Channel.
	 *
	 * @return array<string, mixed>|null
	 */
	private function channelRow(array $table, string $vendor, string $channel): array|null {
		$vendorRow = ($table[$vendor] ?? null);
		if (is_array($vendorRow) === false) {
			return null;
		}

		$channelRow = ($vendorRow[$channel] ?? null);
		if (is_array($channelRow) === false) {
			return null;
		}

		return $channelRow;
	}//end channelRow()

	/**
	 * A category's price for a country, else its default, else 0.0.
	 *
	 * @param array<string, mixed> $catRow  The category entry.
	 * @param string               $country ISO 3166-1 alpha-2 country code.
	 *
	 * @return float
	 */
	private function countryPrice(array $catRow, string $country): float {
		if (isset($catRow[$country]) === true && is_numeric($catRow[$country]) === true) {
			return (float)$catRow[$country];
		}

		if (isset($catRow['default']) === true && is_numeric($catRow['default']) === true) {
			return (float)$catRow['default'];
		}

		return 0.0;
	}//end countryPrice()

	/**
	 * Load the effective price table (built-in merged with app-config override).
	 *
	 * @return array<string, mixed> Price table.
	 */
	private function loadTable(): array {
		$override = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'messaging.price_table_json',
			''
		);

		if ($override === '') {
			return self::DEFAULT_PRICE_TABLE;
		}

		$decoded = $this->decodeOverride(override: $override);
		if ($decoded === null) {
			return self::DEFAULT_PRICE_TABLE;
		}

		return $this->mergedTable(decoded: $decoded);
	}//end loadTable()

	/**
	 * The override's decoded table, or null (an invalid one logged) when it is not a JSON object.
	 *
	 * @param string $override The configured price_table_json.
	 *
	 * @return array<mixed>|null
	 */
	private function decodeOverride(string $override): array|null {
		try {
			$decoded = json_decode($override, true, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CostEstimationService.loadTable: invalid price_table_json — using defaults',
				['exception' => $e->getMessage()]
			);
			return null;
		}

		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end decodeOverride()

	/**
	 * The default table with the override's vendors replacing their defaults.
	 *
	 * @param array<mixed> $decoded The decoded override.
	 *
	 * @return array<string, mixed>
	 */
	private function mergedTable(array $decoded): array {
		// Shallow-merge per vendor so operators can override one
		// vendor without losing the defaults for the others.
		$merged = self::DEFAULT_PRICE_TABLE;
		foreach ($decoded as $vendor => $value) {
			if (is_string($vendor) === true) {
				$merged[$vendor] = $value;
			}
		}

		return $merged;
	}//end mergedTable()
}//end class
