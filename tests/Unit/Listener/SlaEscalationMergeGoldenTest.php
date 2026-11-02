<?php

/**
 * Golden test for the listener's escalation merge.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
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

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\SlaObjectUpdatedListener;
use OCA\Pipelinq\Service\SlaEngineService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Runs SlaObjectUpdatedListener::applyEscalations() on fixed envelopes and
 * escalation results and compares the updated envelope with
 * SlaEscalationMergeGolden.json. Set SLA_MERGE_GOLDEN_RECORD=1 to rewrite it.
 *
 * @covers \OCA\Pipelinq\Listener\SlaObjectUpdatedListener
 * @covers \OCA\Pipelinq\Service\Sla\MergesBreachEvents
 */
class SlaEscalationMergeGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/SlaEscalationMergeGolden.json';

	/**
	 * Envelope and escalation result per case.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$targets = [
			['name' => 'response', 'breachEventIds' => ['e0', 'e0', 'e1']],
			['name' => 'resolve'],
			['name' => 'other', 'breachEventIds' => 'e9'],
		];

		return [
			'new events'          => [['targets' => $targets, 'currentEscalationLevel' => 1], ['level' => 2, 'eventIds' => ['e1', 'e2']]],
			'no new events'       => [['targets' => $targets, 'currentEscalationLevel' => 1], ['level' => 1, 'eventIds' => []]],
			'no level yet'        => [['targets' => $targets], ['level' => 1, 'eventIds' => ['e3']]],
			'paused'              => [['targets' => $targets, 'pausedAt' => '2026-01-01'], ['level' => 5, 'eventIds' => ['e4']]],
			'no targets'          => [['targets' => [], 'currentEscalationLevel' => 0], ['level' => 1, 'eventIds' => ['e5']]],
			'repeated event ids'  => [['targets' => $targets], ['level' => 1, 'eventIds' => ['e2', 'e2', 'e0']]],
		];
	}//end cases()

	/**
	 * The updated envelope matches the golden.
	 *
	 * @param array<string, mixed> $slaStatus The envelope.
	 * @param array<string, mixed> $result    The executeEscalations() result.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testTheMergeMatchesTheGolden(array $slaStatus, array $result): void {
		$engine = $this->createMock(originalClassName: SlaEngineService::class);
		$engine->method('executeEscalations')->willReturn($result);

		$reflection = new ReflectionClass(SlaObjectUpdatedListener::class);
		$listener   = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('engine')->setValue($listener, $engine);

		$actual = (new ReflectionMethod($listener, 'applyEscalations'))->invoke($listener, $slaStatus, 'request', 'u1', ['id' => 'P1']);
		$name   = (string) $this->dataName();

		if (getenv('SLA_MERGE_GOLDEN_RECORD') !== false) {
			$golden = [];
			if (file_exists(self::GOLDEN) === true) {
				$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
			}

			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(
				self::GOLDEN,
				json_encode($golden, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n"
			);
		}

		$golden = json_decode((string) file_get_contents(self::GOLDEN), true);
		$this->assertArrayHasKey(key: $name, array: $golden);
		$this->assertSame(expected: $golden[$name], actual: $actual);
	}//end testTheMergeMatchesTheGolden()
}//end class
