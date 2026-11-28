<?php

/**
 * The repair step that creates the background service group.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\CreateBackgroundServiceGroup;
use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCA\Pipelinq\Tests\Unit\Support\FakeBackgroundAccount;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The step creates the group, never picks an account, and runs on upgrade and on install.
 *
 * @covers \OCA\Pipelinq\Repair\CreateBackgroundServiceGroup
 * @covers \OCA\Pipelinq\Repair\CreateServiceGroup
 * @uses   \OCA\Pipelinq\Service\BackgroundServiceAccount
 * @uses   \OCA\Pipelinq\Service\ServiceAccount
 */
class CreateBackgroundServiceGroupTest extends TestCase {
	/**
	 * Run the step and collect what it said.
	 *
	 * @param FakeBackgroundAccount $world The world.
	 *
	 * @return array{info: array<int, string>, warning: array<int, string>} The output.
	 */
	private function runStep(FakeBackgroundAccount $world): array {
		$said = ['info' => [], 'warning' => []];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			function (string $message) use (&$said): void {
				$said['info'][] = $message;
			}
		);
		$output->method('warning')->willReturnCallback(
			function (string $message) use (&$said): void {
				$said['warning'][] = $message;
			}
		);

		$step = new CreateBackgroundServiceGroup($world->account);
		$this->assertSame('Create the group Pipelinq background work writes with', $step->getName());
		$step->run($output);

		return $said;
	}//end runStep()

	/**
	 * info.xml runs the step after an upgrade and on a fresh install.
	 *
	 * @return void
	 */
	public function testInfoXmlRunsTheStepOnUpgradeAndOnInstall(): void {
		$info = simplexml_load_string((string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml'));
		$this->assertNotFalse($info);

		foreach (['post-migration', 'install'] as $phase) {
			$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{$phase}->step, false));
			$this->assertContains(CreateBackgroundServiceGroup::class, $steps, $phase);
		}
	}//end testInfoXmlRunsTheStepOnUpgradeAndOnInstall()

	/**
	 * With no account picked the group exists, nobody is in it, and the output says what is missing.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheGroupIsMadeAndNobodyIsPicked(): void {
		$world = new FakeBackgroundAccount($this);

		$said = $this->runStep($world);

		$this->assertSame([], $world->members);
		$this->assertArrayNotHasKey(BackgroundServiceAccount::CONFIG_KEY, $world->config);
		$this->assertCount(1, $said['warning']);
		$this->assertStringContainsString('incoming calls are not logged', $said['warning'][0]);
	}//end testWithoutAnAccountTheGroupIsMadeAndNobodyIsPicked()

	/**
	 * A picked account that fell out of the group is put back.
	 *
	 * @return void
	 */
	public function testAPickedAccountIsPutInTheGroup(): void {
		$world = (new FakeBackgroundAccount($this))->usable();
		$world->members = [];

		$said = $this->runStep($world);

		$this->assertSame([FakeBackgroundAccount::UID], $world->members);
		$this->assertSame([], $said['warning']);
	}//end testAPickedAccountIsPutInTheGroup()
}//end class
