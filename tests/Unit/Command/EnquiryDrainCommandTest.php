<?php

/**
 * Unit tests for EnquiryDrainCommand.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Command
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Command;

use OCA\Pipelinq\Command\EnquiryDrainCommand;
use OCA\Pipelinq\Service\WebForm\HeldObjectDrain;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command is read-only by default, prints the report, and exits 0 only at zero pending.
 */
class EnquiryDrainCommandTest extends TestCase {

	/**
	 * The drain of the last built command.
	 *
	 * @var HeldObjectDrain&MockObject
	 */
	private HeldObjectDrain $drain;

	/**
	 * A tester over the command, with the given app config.
	 *
	 * @param array<string, string> $config Key to value.
	 *
	 * @return CommandTester The tester.
	 */
	private function tester(array $config = ['register' => '20', 'enquiry_schema' => '26']): CommandTester {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$this->drain = $this->createMock(HeldObjectDrain::class);

		return new CommandTester(new EnquiryDrainCommand($this->drain, $appConfig));
	}//end tester()

	/**
	 * The default run asks the drain for a report only, on the open enquiries, and exits 1 while anything is held.
	 *
	 * @return void
	 */
	public function testDefaultRunIsReadOnlyAndReports(): void {
		$tester = $this->tester();
		$this->drain->expects($this->once())->method('drain')
			->with('20', '26', 'source', ['status' => 'new'], false)
			->willReturn(
				[
					'delivered' => [['id' => 'e-1', 'key' => 'website-support', 'destination' => 'ticket', 'reference' => '']],
					'refused' => [],
					'unmapped' => [['id' => 'e-3', 'key' => 'website-careers']],
					'pending' => 2,
				]
			);

		$exit = $tester->execute([]);

		$this->assertSame(1, $exit);
		$display = $tester->getDisplay();
		$this->assertStringContainsString('enquiry: 1 would be delivered, 0 refused, 1 unmapped.', $display);
		$this->assertStringContainsString('unmapped e-3: no published form for source "website-careers"', $display);
		$this->assertStringContainsString('Pending after this run: 2.', $display);
	}//end testDefaultRunIsReadOnlyAndReports()

	/**
	 * With apply and nothing left the command exits 0: the schema may be removed.
	 *
	 * @return void
	 */
	public function testApplyAtZeroPendingExitsZero(): void {
		$tester = $this->tester();
		$this->drain->expects($this->once())->method('drain')
			->with('20', '26', 'source', ['status' => 'new'], true)
			->willReturn(['delivered' => [['id' => 'e-1', 'key' => 'website-support', 'destination' => 'ticket', 'reference' => 'T-1']], 'refused' => [], 'unmapped' => [], 'pending' => 0]);

		$this->assertSame(0, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString('delivered e-1 (website-support) into ticket T-1', $tester->getDisplay());
	}//end testApplyAtZeroPendingExitsZero()

	/**
	 * Legacy intake submissions are drained only on request, by their source.
	 *
	 * @return void
	 */
	public function testIncludeIntakeSubmissions(): void {
		$tester = $this->tester();
		$empty = ['delivered' => [], 'refused' => [], 'unmapped' => [], 'pending' => 0];
		$this->drain->expects($this->exactly(2))->method('drain')->willReturnCallback(
			static function (string $register, string $schema, string $key, array $filter, bool $apply) use ($empty): array {
				return $empty;
			}
		);

		$this->assertSame(0, $tester->execute(['--include-intake-submissions' => true]));
		$this->assertStringContainsString('intakeSubmission: 0 would be delivered', $tester->getDisplay());
	}//end testIncludeIntakeSubmissions()

	/**
	 * Without a register the command refuses; without an enquiry schema there is nothing to drain.
	 *
	 * @return void
	 */
	public function testUnconfiguredInstances(): void {
		$this->assertSame(1, $this->tester(config: [])->execute([]));

		$tester = $this->tester(config: ['register' => '20']);
		$this->drain->expects($this->never())->method('drain');
		$this->assertSame(0, $tester->execute([]));
		$this->assertStringContainsString('nothing to drain', $tester->getDisplay());
	}//end testUnconfiguredInstances()
}//end class
