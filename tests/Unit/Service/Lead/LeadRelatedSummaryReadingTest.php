<?php

/**
 * Unit tests for LeadRelatedSummary: how it reads the register and formats hours.
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The queries it sends, the rows it accepts and the hour formatting.
 */
#[CoversClass(LeadRelatedSummary::class)]
class LeadRelatedSummaryReadingTest extends TestCase {

	/**
	 * A translator that substitutes its parameters.
	 *
	 * @return IL10N
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $one : $many)));
		return $l10n;
	}//end l10n()

	/**
	 * A config that knows the given keys only.
	 *
	 * @param array $values Key to value.
	 *
	 * @return IAppConfig
	 */
	private function config(array $values): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);
		return $config;
	}//end config()

	/**
	 * Without a configured register nothing is queried and both lines are empty.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterQueriesNothing(): void {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->never())->method('findAll');

		$summary = new LeadRelatedSummary(
			appConfig: $this->config(values: ['lead_schema' => 'leads', 'timeEntry_schema' => 'hours']),
			l10n: $this->l10n(),
			objectService: $objects
		);

		self::assertSame(['earlier' => '', 'hours' => ''], $summary->forLead(lead: ['id' => 'l1', 'client' => 'c1']));
	}//end testAnUnconfiguredRegisterQueriesNothing()

	/**
	 * The queries filter on register, schema and the client or lead, capped at 500 rows.
	 *
	 * @return void
	 */
	public function testTheQueriesFilterOnTheClientAndTheLead(): void {
		$configs = [];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			static function (array $config = []) use (&$configs): array {
				$configs[] = $config;
				return [];
			}
		);

		$summary = new LeadRelatedSummary(
			appConfig: $this->config(values: ['register' => 'r1', 'lead_schema' => 's-lead', 'timeEntry_schema' => 's-time']),
			l10n: $this->l10n(),
			objectService: $objects
		);
		$summary->forLead(lead: ['id' => 'l1', 'client' => 'c1']);

		self::assertSame(
			[
				['filters' => ['register' => 'r1', 'schema' => 's-lead', 'client' => 'c1'], 'limit' => 500],
				['filters' => ['register' => 'r1', 'schema' => 's-time', 'lead' => 'l1'], 'limit' => 500],
			],
			$configs
		);
	}//end testTheQueriesFilterOnTheClientAndTheLead()

	/**
	 * Serialisable objects are read like arrays; scalars are ignored.
	 *
	 * @return void
	 */
	public function testSerialisableRowsAreReadAndScalarsIgnored(): void {
		$entry = new class implements \JsonSerializable {
			/**
			 * A time entry as stored.
			 *
			 * @return array
			 */
			public function jsonSerialize(): array {
				return ['hours' => 1.25];
			}
		};
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn([$entry, ['hours' => 2], 'junk', 7]);

		$summary = new LeadRelatedSummary(
			appConfig: $this->config(values: ['register' => 'r1', 'lead_schema' => 's-lead', 'timeEntry_schema' => 's-time']),
			l10n: $this->l10n(),
			objectService: $objects
		);

		self::assertSame('3.25', $summary->forLead(lead: ['id' => 'l1'])['hours']);
	}//end testSerialisableRowsAreReadAndScalarsIgnored()

	/**
	 * Hours drop trailing zeros, round to two decimals, and vanish at zero or below.
	 *
	 * @return void
	 */
	public function testHourFormatting(): void {
		$cases = [
			'8' => [['hours' => 8.0]],
			'2.5' => [['hours' => '2.50']],
			'0.33' => [['hours' => 0.333]],
			'10' => [['hours' => 9.999]],
			'' => [['hours' => 0], ['note' => 'no hours']],
		];
		foreach ($cases as $expected => $entries) {
			$objects = $this->createMock(ObjectServiceInterface::class);
			$objects->method('findAll')->willReturn($entries);
			$summary = new LeadRelatedSummary(
				appConfig: $this->config(values: ['register' => 'r1', 'timeEntry_schema' => 's-time']),
				l10n: $this->l10n(),
				objectService: $objects
			);
			self::assertSame((string)$expected, $summary->forLead(lead: ['id' => 'l1'])['hours'], 'case '.$expected);
		}

		$negative = $this->createMock(ObjectServiceInterface::class);
		$negative->method('findAll')->willReturn([['hours' => 2], ['hours' => -3]]);
		$summary = new LeadRelatedSummary(
			appConfig: $this->config(values: ['register' => 'r1', 'timeEntry_schema' => 's-time']),
			l10n: $this->l10n(),
			objectService: $negative
		);
		self::assertSame('', $summary->forLead(lead: ['id' => 'l1'])['hours']);
	}//end testHourFormatting()
}//end class
