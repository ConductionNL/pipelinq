<?php

/**
 * The state of the last full export.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Export;

use DateTime;
use OCA\Pipelinq\Service\Export\FullExportState;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Pipelinq\Service\Export\FullExportState
 */
class FullExportStateTest extends TestCase {

	/**
	 * The stored app config values.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * A state over an in-memory app config and a fixed clock.
	 *
	 * @return FullExportState
	 */
	private function state(): FullExportState {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->stored[$app . '/' . $key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$app . '/' . $key] = $value;
				return true;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));

		return new FullExportState(config: $config, time: $time);
	}//end state()

	public function testWithoutAnExportTheStateIsNone(): void {
		$this->assertSame(['state' => 'none'], $this->state()->read());
	}//end testWithoutAnExportTheStateIsNone()

	public function testEachStepIsRecordedWithWhenAndForWhom(): void {
		$state = $this->state();

		$state->queued(userId: 'admin');
		$this->assertSame(['state' => 'queued', 'userId' => 'admin', 'at' => '2026-11-14T10:00:00+00:00'], $state->read());

		$state->ready(userId: 'admin', name: 'pipelinq-export-2026-11-14.zip', fileId: 42);
		$this->assertSame(
			['state' => 'ready', 'userId' => 'admin', 'name' => 'pipelinq-export-2026-11-14.zip', 'fileId' => '42', 'at' => '2026-11-14T10:00:00+00:00'],
			$state->read()
		);

		$state->failed(userId: 'admin');
		$this->assertSame(['state' => 'failed', 'userId' => 'admin', 'at' => '2026-11-14T10:00:00+00:00'], $state->read());
		$this->assertArrayHasKey('pipelinq/' . FullExportState::KEY, $this->stored);
	}//end testEachStepIsRecordedWithWhenAndForWhom()

	public function testAnUnreadableRecordReadsAsNone(): void {
		$this->stored['pipelinq/' . FullExportState::KEY] = '{"state":"exploded"}';
		$this->assertSame(['state' => 'none'], $this->state()->read());

		$this->stored['pipelinq/' . FullExportState::KEY] = 'not json';
		$this->assertSame(['state' => 'none'], $this->state()->read());
	}//end testAnUnreadableRecordReadsAsNone()
}//end class
