<?php

/**
 * Tests for LeadRelatedSummary.
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
 * Board PqLead "Gerelateerd": "2 eerdere deals, allebei gewonnen", "Uren op
 * deze lead: 14,5".
 *
 * @spec openspec/specs/lead-management/spec.md#requirement-the-related-card-names-earlier-deals-and-hours
 */
class LeadRelatedSummaryTest extends TestCase {
	/**
	 * A summary over fixed leads and time entries.
	 *
	 * @param array<int, array<string, mixed>> $leads   The client's leads.
	 * @param array<int, array<string, mixed>> $entries The lead's time entries.
	 *
	 * @return LeadRelatedSummary The summary.
	 */
	private function summary(array $leads, array $entries): LeadRelatedSummary {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(static fn (string $one, string $many, int $count): string => str_replace('%n', (string)$count, ($count === 1 ? $one : $many)));
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static fn (array $config = []): array => ($config['filters']['schema'] === 'lead_schema' ? $leads : $entries)
		);
		return new LeadRelatedSummary(appConfig: $config, l10n: $l10n, objectService: $objects);
	}//end summary()

	/**
	 * The client's other closed deals and the hours on the lead.
	 *
	 * @return void
	 */
	public function testNamesEarlierDealsAndHours(): void {
		$summary = $this->summary(
			[['id' => 'l1', 'status' => 'open'], ['id' => 'l2', 'status' => 'won'], ['id' => 'l3', 'status' => 'won'], ['id' => 'l4', 'status' => 'open']],
			[['hours' => 8], ['hours' => 6.5]]
		);

		self::assertSame(['earlier' => '2 earlier deals, both won', 'hours' => '14.5'], $summary->forLead(lead: ['id' => 'l1', 'client' => 'c1']));
	}//end testNamesEarlierDealsAndHours()

	/**
	 * Mixed outcomes are counted, and nothing is said without a client or hours.
	 *
	 * @return void
	 */
	public function testMixedOutcomesAndNothingToSay(): void {
		$mixed = $this->summary([['id' => 'l2', 'status' => 'won'], ['id' => 'l3', 'status' => 'lost']], []);
		self::assertSame('2 earlier deals: 1 won, 1 lost', $mixed->forLead(lead: ['id' => 'l1', 'client' => 'c1'])['earlier']);
		self::assertSame(['earlier' => '', 'hours' => ''], $mixed->forLead(lead: ['id' => 'l1']));
	}//end testMixedOutcomesAndNothingToSay()
	/**
	 * Every earlier-deal sentence: the lead itself never counts, open deals
	 * never count, one deal, all lost, more than two of one outcome, and
	 * nothing to say without closed deals.
	 *
	 * @return void
	 */
	public function testEveryEarlierDealSentence(): void {
		$lead = ['id' => 'l1', 'client' => 'c1'];
		$cases = [
			'1 earlier deal, won' => [['id' => 'l1', 'status' => 'lost'], ['id' => 'l2', 'status' => 'won']],
			'3 earlier deals, all lost' => [['id' => 'l2', 'status' => 'lost'], ['id' => 'l3', 'status' => 'lost'], ['id' => 'l4', 'status' => 'lost'], ['status' => 'open']],
			'4 earlier deals, all won' => [['id' => 'l2', 'status' => 'won'], ['id' => 'l3', 'status' => 'won'], ['id' => 'l4', 'status' => 'won'], ['id' => 'l5', 'status' => 'won']],
			'3 earlier deals: 2 won, 1 lost' => [['id' => 'l2', 'status' => 'won'], ['id' => 'l3', 'status' => 'lost'], ['id' => 'l4', 'status' => 'won']],
			'' => [['id' => 'l1', 'status' => 'won'], ['id' => 'l2', 'status' => 'open']],
		];
		foreach ($cases as $sentence => $leads) {
			self::assertSame($sentence, $this->summary($leads, [])->forLead(lead: $lead)['earlier'], $sentence);
		}
	}//end testEveryEarlierDealSentence()
}//end class
