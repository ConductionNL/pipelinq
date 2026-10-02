<?php

/**
 * The lead schema declares every deal field the forecast roll-up reads.
 *
 * ForecastRollupService::bucketDeals() converted `$deal['currency']` at a
 * rate, but the lead schema had no `currency`, so no deal could carry one and
 * a USD 10,000 deal counted as EUR 10,000 (pipelinq#2040). OpenRegister drops
 * a property the schema does not declare, so the conversion could never run.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ForecastRollupService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Deal fields read by the roll-up against the merged lead schema.
 */
class LeadCurrencySchemaTest extends TestCase {
	/**
	 * Every `$deal['<field>']` the roll-up reads is a lead property.
	 *
	 * @return void
	 */
	public function testEveryDealFieldTheRollupReadsIsDeclared(): void {
		$source = (string)file_get_contents((string)(new ReflectionClass(ForecastRollupService::class))->getFileName());
		preg_match_all("/\\\$deal\['([A-Za-z_]+)'\]/", $source, $matches);
		$read = array_unique($matches[1]);

		// Positive control: the regex must see the fields we know are read.
		$this->assertContains('value', $read);
		$this->assertContains('currency', $read);

		$properties = (self::mergedLeadSchema()['properties'] ?? []);
		foreach ($read as $field) {
			if ($field === 'id' || $field === 'uuid') {
				continue;
			}

			$this->assertArrayHasKey($field, $properties, "the forecast reads deal '{$field}', which the lead schema does not declare");
		}

		$this->assertSame('string', $properties['currency']['type']);
		$this->assertSame('^[A-Z]{3}$', $properties['currency']['pattern']);
	}//end testEveryDealFieldTheRollupReadsIsDeclared()

	/**
	 * The lead schema as the install imports it: the monolith with every
	 * register.d fragment deep-merged on top, in filename order.
	 *
	 * @return array<string, mixed> The merged lead schema.
	 */
	private static function mergedLeadSchema(): array {
		$settings = dirname(__DIR__, 3) . '/lib/Settings';
		$files = array_merge([$settings . '/pipelinq_register.json'], glob($settings . '/register.d/*.json'));
		$merged = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$fragment = ($data['components']['schemas']['lead'] ?? null);
			if (is_array($fragment) === true) {
				$merged = array_replace_recursive($merged, $fragment);
			}
		}

		return $merged;
	}//end mergedLeadSchema()
}//end class
