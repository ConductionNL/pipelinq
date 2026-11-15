<?php

/**
 * Unit tests for the RemoveLegacyPspKeys repair step.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\RemoveLegacyPspKeys;
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Pins which retired PSP keys are deleted, in which order, and every output and log line.
 */
final class RemoveLegacyPspKeysTest extends TestCase {
	/**
	 * Run the step over the given stored keys.
	 *
	 * @param array<string, string> $stored    Config key => stored value.
	 * @param array<int, string>    $undeletable Keys whose delete fails.
	 *
	 * @return array<int, array<int, mixed>> Every delete, output and log line, in order.
	 */
	private function runStep(array $stored, array $undeletable = []): array {
		$events = [];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($stored[$key] ?? $default)
		);
		$appConfig->method('deleteKey')->willReturnCallback(
			static function (string $app, string $key) use ($undeletable, &$events): void {
				$events[] = ['delete', $app, $key];
				if (in_array($key, $undeletable, true) === true) {
					throw new RuntimeException('locked');
				}
			}
		);
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(
				static function (string $message) use ($level, &$events): void {
					$events[] = ['output.'.$level, $message];
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			static function (string $message, array $context = []) use (&$events): void {
				$events[] = ['log.error', $message, $context];
			}
		);
		(new RemoveLegacyPspKeys($appConfig, $logger))->run($output);
		return $events;
	}//end runStep()

	/**
	 * Every stored retired key is deleted in provider then field order; the summary counts them.
	 *
	 * @return void
	 */
	public function testDeletesEveryStoredRetiredKey(): void {
		$keys = [];
		foreach (PosPaymentService::PROVIDERS as $provider) {
			foreach (PosPaymentService::RETIRED_SECRET_FIELDS as $field) {
				$keys[] = [$provider, $field, PosPaymentService::CONFIG_PREFIX.$provider.'.'.$field];
			}
		}

		$this->assertGreaterThanOrEqual(2, count($keys));
		[$first, $second] = [$keys[0], $keys[count($keys) - 1]];
		$this->assertSame(
			[
				['delete', 'pipelinq', $first[2]],
				['delete', 'pipelinq', $second[2]],
				['output.warning', 'Could not remove the legacy PSP key for '.$second[0].': locked'],
				['log.error', 'Pipelinq: could not remove a legacy PSP key; it is still stored', ['provider' => $second[0], 'field' => $second[1]]],
				['output.info', 'Removed 1 legacy PSP key(s). Select a credential from the broker in the POS payment settings to re-enable each provider.'],
			],
			$this->runStep(stored: [$first[2] => 'sk_live_1', $second[2] => 'sk_live_2'], undeletable: [$second[2]])
		);
	}//end testDeletesEveryStoredRetiredKey()

	/**
	 * Nothing stored, or nothing removed, says so.
	 *
	 * @return void
	 */
	public function testNothingToRemove(): void {
		$this->assertSame([['output.info', 'No legacy PSP keys stored; nothing to remove.']], $this->runStep(stored: []));
	}//end testNothingToRemove()
}//end class
