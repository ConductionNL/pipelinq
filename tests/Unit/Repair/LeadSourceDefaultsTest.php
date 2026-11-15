<?php

/**
 * The default lead sources cover every source the shipped data uses.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-tender-is-a-default-lead-source-req-raf-012
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\InitializeSettings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Lead sources are a managed list; the demo seed must not use one outside it.
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/lead-management/spec.md#requirement-tender-is-a-default-lead-source-req-raf-012
 */
class LeadSourceDefaultsTest extends TestCase {
	/**
	 * Every lead source in the demo seed is a default lead source.
	 *
	 * Fails on the old list, which had no `tender` while the demo lead
	 * "Intranet migratie Zonnedael" uses it.
	 *
	 * @return void
	 */
	public function testEveryDemoLeadSourceIsADefault(): void {
		$defaults = (new ReflectionClass(InitializeSettings::class))->getConstant('DEFAULT_LEAD_SOURCES');
		$this->assertIsArray($defaults);

		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/demo_seed_data.json'), true);
		$sources = array_values(array_unique(array_filter(array_map(
			static fn (array $lead): string => (string)($lead['data']['source'] ?? ''),
			$seed['leads']
		))));

		$this->assertContains('tender', $sources);
		foreach ($sources as $source) {
			$this->assertContains($source, $defaults, "Lead source '$source' is used by the demo seed but is not a default.");
		}
	}//end testEveryDemoLeadSourceIsADefault()
}//end class
