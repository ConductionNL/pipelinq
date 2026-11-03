<?php

/**
 * Golden test for ProviderSubjectHandler::applySubjectText().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Activity
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
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Activity;

use OCA\Pipelinq\Activity\ProviderSubjectHandler;
use OCP\Activity\IEvent;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every setter call (translation key and parameters included) for every
 * subject with full, empty and non-string parameters, plus an unknown
 * subject, compared with ProviderSubjectGolden.json. Set
 * PROVIDER_SUBJECT_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @covers \OCA\Pipelinq\Activity\ProviderSubjectHandler
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */
class ProviderSubjectGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/ProviderSubjectGolden.json';

	private const SUBJECTS = [
		'lead_created', 'lead_assigned', 'request_created', 'lead_lost', 'lead_stage_changed',
		'request_status_changed', 'lead_won', 'note_added', 'something_else',
	];

	/**
	 * Calls on the event in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$sets = [
			'full' => ['title' => 'Acme', 'stage' => 'Proposal', 'status' => 'Closed', 'value' => '1250', 'entityType' => 'lead'],
			'empty' => [],
			'numbers' => ['title' => 'Acme', 'stage' => 3, 'status' => 4, 'value' => 1250, 'entityType' => 5],
		];
		$actual = [];
		foreach (self::SUBJECTS as $subject) {
			foreach ($sets as $kind => $params) {
				$actual[$subject . ' / ' . $kind] = $this->apply(subject: $subject, params: $params);
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('PROVIDER_SUBJECT_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testGolden()

	/**
	 * Apply one subject and return the setter calls, or the error.
	 *
	 * @param string               $subject The subject.
	 * @param array<string, mixed> $params  The subject parameters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function apply(string $subject, array $params): array {
		$this->calls = [];
		$event = $this->createMock(originalClassName: IEvent::class);
		$event->method('getSubject')->willReturn($subject);
		$event->method('getObjectId')->willReturn(17);
		foreach (['setParsedSubject', 'setRichSubject'] as $setter) {
			$event->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $event): IEvent {
					$this->calls[] = [$setter => $args];
					return $event;
				}
			);
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters = []): string => 'T[' . $text . ']' . json_encode($parameters)
		);

		try {
			(new ProviderSubjectHandler())->applySubjectText(event: $event, l: $l10n, params: $params);
		} catch (Throwable $e) {
			$this->calls[] = ['threw' => get_class($e)];
		}

		return $this->calls;
	}//end apply()
}//end class
