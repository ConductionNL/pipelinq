<?php

/**
 * Golden test for JourneyFlowCompiler::documentFor: whole documents per journey shape.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\JourneyFlowCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Compiles every trigger kind with and without a wait, a condition (each operator)
 * and a guard, scheduled and object-triggered, active and draft, and compares the
 * whole flow documents with a recorded golden file.
 */
class JourneyFlowDocumentGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/JourneyFlowDocumentGolden.json';

	/**
	 * Compile every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$compiler = new JourneyFlowCompiler();
		$actual = [];
		$kinds = ['leadStageChanged', 'contractRenewalWindow', 'listConfirmed', 'addressChanged', 'ticketClosed', 'shillinqSignal', '', 'unknownKind'];
		$conditions = [
			'none' => [],
			'blank field' => ['field' => '  ', 'value' => 'x'],
			'equals' => ['field' => 'stage', 'value' => 'won'],
			'notEquals' => ['field' => 'stage', 'operator' => 'notEquals', 'value' => 'lost'],
			'isNull' => ['field' => 'email', 'operator' => 'isNull'],
			'isNotNull' => ['field' => 'email', 'operator' => 'isNotNull'],
			'odd operator' => ['field' => 'stage', 'operator' => 'contains', 'value' => 'w'],
		];
		foreach ($kinds as $kind) {
			foreach ($conditions as $conditionName => $condition) {
				foreach (['no wait' => '', 'wait' => ' P2D '] as $waitName => $wait) {
					$journey = [
						'name' => 'Welcome ' . $kind,
						'description' => 'About ' . $kind,
						'status' => 'Active',
						'trigger' => ['kind' => $kind, 'cron' => '15 6 * * 1'],
						'waitFor' => $wait,
						'condition' => $condition,
					];
					$actual[$kind . ', ' . $conditionName . ', ' . $waitName] = $compiler->documentFor($journey, 'j-1', 'runner');
				}
			}
		}

		$actual['bare journey'] = $compiler->documentFor([], 'j-2');
		$actual['draft, bad cron, unnamed'] = $compiler->documentFor(['name' => ' ', 'status' => 'draft', 'trigger' => ['kind' => 'shillinqSignal', 'cron' => '* *']], 'j-3', 'admin');
		$actual['trigger not an array'] = $compiler->documentFor(['trigger' => 'x', 'condition' => 'y', 'status' => 'active'], 'j-4');

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('JOURNEY_FLOW_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame(expected: json_encode($want, $flags), actual: json_encode(($actual[$name] ?? null), $flags), message: $name);
		}

		$this->assertSame(expected: array_keys($expected), actual: array_keys($actual));
	}//end testGolden()
}//end class
