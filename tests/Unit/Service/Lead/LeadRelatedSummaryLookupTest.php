<?php

/**
 * Tests for how LeadRelatedSummary queries OpenRegister and reads its rows.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Lead
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Lead;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\Lead\LeadRelatedSummary;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The lookups behind the related card: which filters they send, when they are
 * skipped, and how entity rows and stray results are read.
 *
 * @covers \OCA\Pipelinq\Service\Lead\LeadRelatedSummary
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
 */
class LeadRelatedSummaryLookupTest extends TestCase {

	/**
	 * The findAll() configs the summary sent.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * A summary over the given app config and rows per schema.
	 *
	 * @param array<string, string>    $config   App-config values by key.
	 * @param array<string, list<mixed>> $bySchema Rows answered per schema id.
	 *
	 * @return LeadRelatedSummary The summary.
	 */
	private function summary(array $config, array $bySchema): LeadRelatedSummary {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf(format: $text, values: $parameters));
		$l10n->method('n')->willReturnCallback(
			static function (string $one, string $many, int $count): string {
				$form = $many;
				if ($count === 1) {
					$form = $one;
				}

				return str_replace(search: '%n', replace: (string) $count, subject: $form);
			}
		);
		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $query = []) use ($bySchema): array {
				$this->queries[] = $query;
				return ($bySchema[$query['filters']['schema']] ?? []);
			}
		);

		return new LeadRelatedSummary(appConfig: $appConfig, l10n: $l10n, objectService: $objects);
	}//end summary()

	/**
	 * An object row as OpenRegister's entities give it.
	 *
	 * @param array<string, mixed> $data The serialised row.
	 *
	 * @return \JsonSerializable The entity.
	 */
	private static function entity(array $data): \JsonSerializable {
		return new class ($data) implements \JsonSerializable {
			/**
			 * Constructor.
			 *
			 * @param array<string, mixed> $data The row.
			 */
			public function __construct(private array $data) {
			}

			/**
			 * The row.
			 *
			 * @return array<string, mixed>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end entity()

	/**
	 * Both lookups are scoped to the configured register and schema, filtered on the client and the lead, and capped.
	 *
	 * @return void
	 */
	public function testTheLookupsSendTheConfiguredScopeAndTheCap(): void {
		$summary = $this->summary(
			config: ['register' => '4', 'lead_schema' => '11', 'timeEntry_schema' => '12'],
			bySchema: ['11' => [['id' => 'l2', 'status' => 'won']], '12' => [['hours' => 2]]]
		);

		$this->assertSame(
			expected: ['earlier' => '1 earlier deal, won', 'hours' => '2'],
			actual: $summary->forLead(lead: ['id' => 'l1', 'client' => 'c1'])
		);
		$this->assertSame(
			expected: [
				['filters' => ['register' => '4', 'schema' => '11', 'client' => 'c1'], 'limit' => 500],
				['filters' => ['register' => '4', 'schema' => '12', 'lead' => 'l1'], 'limit' => 500],
			],
			actual: $this->queries
		);
	}//end testTheLookupsSendTheConfiguredScopeAndTheCap()

	/**
	 * A lead without an id has no hours to look up, and none are asked for.
	 *
	 * @return void
	 */
	public function testALeadWithoutAnIdAsksForNoHours(): void {
		$summary = $this->summary(
			config: ['register' => '4', 'lead_schema' => '11', 'timeEntry_schema' => '12'],
			bySchema: ['11' => [['id' => 'l2', 'status' => 'lost']], '12' => [['hours' => 3]]]
		);

		$this->assertSame(
			expected: ['earlier' => '1 earlier deal, lost', 'hours' => ''],
			actual: $summary->forLead(lead: ['client' => 'c1'])
		);
		$this->assertCount(expectedCount: 1, haystack: $this->queries);
		$this->assertSame(expected: '11', actual: $this->queries[0]['filters']['schema']);
	}//end testALeadWithoutAnIdAsksForNoHours()

	/**
	 * Without a register, or without a schema, that lookup is skipped and says nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredLookupIsSkipped(): void {
		$rows = ['11' => [['id' => 'l2', 'status' => 'won']], '12' => [['hours' => 3]]];

		$noRegister = $this->summary(config: ['lead_schema' => '11', 'timeEntry_schema' => '12'], bySchema: $rows);
		$this->assertSame(
			expected: ['earlier' => '', 'hours' => ''],
			actual: $noRegister->forLead(lead: ['id' => 'l1', 'client' => 'c1'])
		);
		$this->assertSame(expected: [], actual: $this->queries);

		$noTimeSchema = $this->summary(config: ['register' => '4', 'lead_schema' => '11'], bySchema: $rows);
		$this->assertSame(
			expected: ['earlier' => '1 earlier deal, won', 'hours' => ''],
			actual: $noTimeSchema->forLead(lead: ['id' => 'l1', 'client' => 'c1'])
		);
		$this->assertCount(expectedCount: 1, haystack: $this->queries);
	}//end testAnUnconfiguredLookupIsSkipped()

	/**
	 * Entity rows are read through jsonSerialize(), and results that are neither rows nor entities are ignored.
	 *
	 * @return void
	 */
	public function testEntityRowsAreReadAndStrayResultsIgnored(): void {
		$summary = $this->summary(
			config: ['register' => '4', 'lead_schema' => '11', 'timeEntry_schema' => '12'],
			bySchema: [
				'11' => [self::entity(data: ['id' => 'l2', 'status' => 'won']), 'not a row', new \stdClass(), ['id' => 'l3', 'status' => 'lost']],
				'12' => [self::entity(data: ['hours' => '1.25']), 42, ['hours' => 1]],
			]
		);

		$this->assertSame(
			expected: ['earlier' => '2 earlier deals: 1 won, 1 lost', 'hours' => '2.25'],
			actual: $summary->forLead(lead: ['id' => 'l1', 'client' => 'c1'])
		);
	}//end testEntityRowsAreReadAndStrayResultsIgnored()

	/**
	 * Hours are written without trailing zeros, and a total of zero or less says nothing.
	 *
	 * @return void
	 */
	public function testHoursAreTrimmedAndANonPositiveTotalSaysNothing(): void {
		$config = ['register' => '4', 'timeEntry_schema' => '12'];
		$cases  = [
			'8'   => [['hours' => 5.5], ['hours' => 2.5]],
			'2.1' => [['hours' => 2.1]],
			'0.5' => [['hours' => 0.5], ['note' => 'no hours']],
			''    => [['hours' => 2], ['hours' => -2]],
		];
		foreach ($cases as $expected => $entries) {
			$hours = $this->summary(config: $config, bySchema: ['12' => $entries])->forLead(lead: ['id' => 'l1'])['hours'];
			$this->assertSame(expected: (string) $expected, actual: $hours, message: 'hours for case '.$expected);
		}
	}//end testHoursAreTrimmedAndANonPositiveTotalSaysNothing()
}//end class
