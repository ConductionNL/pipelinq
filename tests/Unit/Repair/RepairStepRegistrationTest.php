<?php

/**
 * Every repair step is named in appinfo/info.xml, and no abstract one is.
 *
 * Nextcloud runs only the steps info.xml names. A step that is written but not
 * named never runs, and nothing says so. An abstract base named there is the
 * opposite mistake: Nextcloud cannot instantiate it and the repair fails.
 * hydra gate-98 cannot tell the two apart, so an abstract base carries a
 * `hydra-gate-98 held:` marker with its reason, and this test checks all three.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/beta-hydra-gates/specs/openregister-integration/spec.md#requirement-every-repair-step-is-registered-and-no-abstract-base-is
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use PHPUnit\Framework\TestCase;

/**
 * Reads lib/Repair/ and appinfo/info.xml as text, the way Nextcloud and gate-98 do.
 *
 * @coversNothing
 */
class RepairStepRegistrationTest extends TestCase {

	/**
	 * The shortest reason gate-98 accepts on a held marker.
	 */
	private const MIN_REASON_CHARS = 30;

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * Every repair step class under lib/Repair/, with whether it is abstract.
	 *
	 * A class is a step when it implements IRepairStep or extends a step.
	 *
	 * @return array<string, bool> FQCN => abstract.
	 */
	private function steps(): array {
		$declared = [];
		foreach (glob($this->root().'/lib/Repair/*.php') as $file) {
			$source = (string) file_get_contents($file);
			$found  = preg_match(
				'/^\s*(final\s+|abstract\s+)?class\s+(\w+)(?:\s+extends\s+(\w+))?([^{]*)\{/m',
				$source,
				$match
			);
			if ($found !== 1) {
				continue;
			}

			$declared[$match[2]] = [
				'abstract'   => trim($match[1]) === 'abstract',
				'parent'     => $match[3],
				'implements' => str_contains($match[4], 'IRepairStep'),
			];
		}

		$steps = [];
		foreach ($declared as $name => $class) {
			$isStep = $class['implements'];
			$parent = $class['parent'];
			while ($isStep === false && $parent !== '' && isset($declared[$parent]) === true) {
				$isStep = $declared[$parent]['implements'];
				$parent = $declared[$parent]['parent'];
			}

			if ($isStep === true) {
				$steps['OCA\\Pipelinq\\Repair\\'.$name] = $class['abstract'];
			}
		}

		return $steps;
	}//end steps()

	/**
	 * The raw text of appinfo/info.xml.
	 *
	 * @return string The file.
	 */
	private function infoXml(): string {
		return (string) file_get_contents($this->root().'/appinfo/info.xml');
	}//end infoXml()

	/**
	 * The step names in one repair-steps block.
	 *
	 * @param string $block The block, e.g. post-migration.
	 *
	 * @return array<int, string> The FQCNs.
	 */
	private function registered(string $block): array {
		$xml   = simplexml_load_string($this->infoXml());
		$names = [];
		foreach ($xml->xpath('/info/repair-steps/'.$block.'/step') as $step) {
			$names[] = ltrim(trim((string) $step), '\\');
		}

		return $names;
	}//end registered()

	/**
	 * The finder sees the steps this test is about, so an empty scan cannot pass.
	 *
	 * @return void
	 */
	public function testTheScanFindsTheStepsItGuards(): void {
		$steps = $this->steps();

		$this->assertGreaterThan(20, count($steps));
		$this->assertFalse($steps['OCA\\Pipelinq\\Repair\\RelinkOrphanedLeads']);
		$this->assertFalse($steps['OCA\\Pipelinq\\Repair\\CreatePortalServiceGroup']);
		$this->assertTrue($steps['OCA\\Pipelinq\\Repair\\CreateServiceGroup']);
	}//end testTheScanFindsTheStepsItGuards()

	/**
	 * Every concrete step runs on an upgrade.
	 *
	 * @return void
	 */
	public function testEveryConcreteStepRunsAfterMigration(): void {
		$postMigration = $this->registered('post-migration');
		foreach ($this->steps() as $fqcn => $abstract) {
			if ($abstract === true) {
				continue;
			}

			$this->assertContains($fqcn, $postMigration, $fqcn.' is written but never runs.');
		}
	}//end testEveryConcreteStepRunsAfterMigration()

	/**
	 * No abstract base is named, in either block.
	 *
	 * @return void
	 */
	public function testNoAbstractBaseIsRegistered(): void {
		$all = array_merge($this->registered('post-migration'), $this->registered('install'));
		foreach ($this->steps() as $fqcn => $abstract) {
			if ($abstract === false) {
				continue;
			}

			$this->assertNotContains($fqcn, $all, $fqcn.' is abstract; Nextcloud cannot run it.');
		}
	}//end testNoAbstractBaseIsRegistered()

	/**
	 * Every abstract base says in info.xml why it is not named, so gate-98 reads it as held.
	 *
	 * @return void
	 */
	public function testEveryAbstractBaseCarriesAHeldMarker(): void {
		$xml = $this->infoXml();
		foreach ($this->steps() as $fqcn => $abstract) {
			if ($abstract === false) {
				continue;
			}

			$pattern = '/hydra-gate-98\s+held:\s*\\\\?'.preg_quote($fqcn, '/').'\s*(?:\x{2014}|--|:)\s*(.*?)-->/su';
			$this->assertSame(1, preg_match($pattern, $xml, $match), $fqcn.' has no hydra-gate-98 held marker.');
			$reason = preg_replace('/\s+/', ' ', trim($match[1]));
			$this->assertGreaterThanOrEqual(self::MIN_REASON_CHARS, strlen((string) $reason));
		}
	}//end testEveryAbstractBaseCarriesAHeldMarker()
}//end class
