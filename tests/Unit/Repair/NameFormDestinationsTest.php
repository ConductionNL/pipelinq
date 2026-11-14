<?php

/**
 * Unit tests for the NameFormDestinations repair step.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\NameFormDestinations;
use OCA\Pipelinq\Service\Marketing\FormDestinationBackfill;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step reports what the backfill did, and is registered to run on upgrade.
 */
class NameFormDestinationsTest extends TestCase {

	/**
	 * Run the step over a backfill result and collect what it said.
	 *
	 * @param array{status: string, named: int, skipped: int, failed: int} $result What the backfill answers.
	 *
	 * @return array{info: array<int, string>, warning: array<int, string>} The output.
	 */
	private function run_(array $result): array {
		$backfill = $this->createMock(FormDestinationBackfill::class);
		$backfill->expects($this->once())->method('run')->willReturn($result);
		$said = ['info' => [], 'warning' => []];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$said): void {
				$said['info'][] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			static function (string $message) use (&$said): void {
				$said['warning'][] = $message;
			}
		);

		(new NameFormDestinations($backfill))->run($output);

		return $said;
	}//end run_()

	/**
	 * The counts are reported; nothing warns when every form was saved.
	 */
	public function testItReportsWhatChanged(): void {
		$said = $this->run_(result: ['status' => 'done', 'named' => 3, 'skipped' => 2, 'failed' => 0]);

		$this->assertSame(['NameFormDestinations: 3 form(s) now submit into a lead, 2 already named a destination, 0 could not be saved.'], $said['info']);
		$this->assertSame([], $said['warning']);
	}//end testItReportsWhatChanged()

	/**
	 * A form that could not be saved cannot be submitted, so the step warns.
	 */
	public function testAFailedFormIsAWarning(): void {
		$said = $this->run_(result: ['status' => 'done', 'named' => 0, 'skipped' => 0, 'failed' => 1]);

		$this->assertCount(1, $said['warning']);
		$this->assertStringContainsString('cannot be submitted', $said['warning'][0]);
	}//end testAFailedFormIsAWarning()

	/**
	 * No OpenRegister or no hosted forms is said plainly and is not a warning.
	 */
	public function testNothingToDoIsSaidPlainly(): void {
		foreach (['no-openregister' => 'OpenRegister is not available', 'read-failed' => 'no hosted forms to read'] as $status => $text) {
			$said = $this->run_(result: ['status' => $status, 'named' => 0, 'skipped' => 0, 'failed' => 0]);

			$this->assertCount(1, $said['info']);
			$this->assertStringContainsString($text, $said['info'][0]);
			$this->assertSame([], $said['warning']);
		}
	}//end testNothingToDoIsSaidPlainly()

	/**
	 * The step is wired: info.xml runs it after the register import, on upgrade only.
	 *
	 * A repair step no info.xml names never runs, and its tests stay green.
	 */
	public function testTheStepIsRegisteredAfterTheRegisterImport(): void {
		// Read as text, then parse: inside a Nextcloud server simplexml_load_file()
		// returns false, because the server's entity loader refuses the path.
		$xml = simplexml_load_string((string)file_get_contents(dirname(__DIR__, 3) . '/appinfo/info.xml'));
		$this->assertNotFalse($xml);
		$steps = array_map('strval', iterator_to_array($xml->{'repair-steps'}->{'post-migration'}->step, false));

		$position = array_search(NameFormDestinations::class, $steps, true);
		$this->assertNotFalse($position, 'NameFormDestinations is not a post-migration step.');
		$this->assertGreaterThan(array_search('OCA\Pipelinq\Repair\InitializeSettings', $steps, true), $position);
		$this->assertNotSame('', (new NameFormDestinations($this->createMock(FormDestinationBackfill::class)))->getName());
	}//end testTheStepIsRegisteredAfterTheRegisterImport()
}//end class
