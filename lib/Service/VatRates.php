<?php

/**
 * The VAT rate per VAT class, as an administrator configured it.
 *
 * The product schema's `vatClass` names a class (high, low, zero, exempt);
 * the rate behind each class is a setting, because rates change by law and
 * differ by country. Stored as one JSON app-config key, `vat_rates`.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pipelinq-forms-review/specs/product-catalog/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads the configured VAT rate per class, falling back to the Dutch rates.
 *
 * @spec openspec/specs/product-catalog/spec.md
 */
class VatRates {
	/**
	 * The app-config key holding the JSON map.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'vat_rates';

	/**
	 * The Dutch rates, used for any class the setting leaves out.
	 *
	 * @var array<string,float>
	 */
	public const DEFAULTS = [
		'high' => 21.0,
		'low' => 9.0,
		'zero' => 0.0,
		'exempt' => 0.0,
	];

	/**
	 * Parse a stored `vat_rates` value over the defaults.
	 *
	 * Unknown classes, non-numbers and rates outside 0-100 are ignored, so a
	 * broken setting falls back to the default rather than to no tax.
	 *
	 * @param string $stored The stored JSON, or ''.
	 *
	 * @return array<string,float> Rate per class, every class present.
	 *
	 * @spec openspec/changes/pipelinq-forms-review/specs/product-catalog/spec.md
	 */
	public static function parse(string $stored): array {
		$rates = self::DEFAULTS;
		$decoded = json_decode($stored, true);
		if (is_array($decoded) === false) {
			return $rates;
		}

		foreach (array_keys(self::DEFAULTS) as $class) {
			$value = ($decoded[$class] ?? null);
			if (is_numeric($value) === true && (float)$value >= 0 && (float)$value <= 100) {
				$rates[$class] = (float)$value;
			}
		}

		return $rates;
	}//end parse()

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config holding `vat_rates`.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The configured rates.
	 *
	 * @return array<string,float> Rate per class.
	 *
	 * @spec openspec/changes/pipelinq-forms-review/specs/product-catalog/spec.md
	 */
	public function rates(): array {
		return self::parse(stored: $this->appConfig->getValueString(Application::APP_ID, self::CONFIG_KEY, ''));
	}//end rates()
}//end class
