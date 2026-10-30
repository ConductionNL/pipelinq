<?php

/**
 * Unit tests for ClientRiskPresenter: the stored risk in the viewer's words.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ClientRiskPresenter;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the churn risk presenter.
 *
 * @spec openspec/changes/clients-history-summary-and-risk/specs/client-insight/spec.md#requirement-every-client-carries-a-risk-level-with-its-reasons-req-cin-001
 */
class ClientRiskPresenterTest extends TestCase {

	/**
	 * The presenter over an IL10N that returns the English text, filled in.
	 *
	 * @return ClientRiskPresenter
	 */
	private function presenter(): ClientRiskPresenter {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, $parameters = []): string => vsprintf($text, (array)$parameters));
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count): string => str_replace('%n', (string)$count, $count === 1 ? $singular : $plural)
		);
		$l10n->method('l')->willReturnCallback(static fn (string $type, $data): string => $data->format('j M Y'));

		return new ClientRiskPresenter($l10n);
	}//end presenter()

	/**
	 * A client without an assessment reads "not worked out yet", with no level.
	 *
	 * @return void
	 */
	public function testUnassessedClient(): void {
		$presented = $this->presenter()->present(null);

		$this->assertNull($presented['level']);
		$this->assertSame('Not worked out yet', $presented['label']);
		$this->assertSame([], $presented['reasons']);
	}//end testUnassessedClient()

	/**
	 * A low client without reasons reads calm.
	 *
	 * @return void
	 */
	public function testLowWithoutReasons(): void {
		$presented = $this->presenter()->present(['level' => 'low', 'score' => 0, 'reasons' => []]);

		$this->assertSame('Low', $presented['label']);
		$this->assertSame('No warning signs', $presented['caption']);
	}//end testLowWithoutReasons()

	/**
	 * Every signal has its own line, and the heaviest reason is the caption.
	 *
	 * @return void
	 */
	public function testEverySignalReadsAndTheHeaviestLeads(): void {
		$presented = $this->presenter()->present(
			[
				'level' => 'high',
				'reasons' => [
					['signal' => 'open-complaints', 'points' => 20, 'count' => 2],
					['signal' => 'sla-breached', 'points' => 15, 'count' => 1],
					['signal' => 'silence', 'points' => 20, 'since' => '2026-06-01'],
					['signal' => 'detractor', 'points' => 25, 'since' => '2026-10-15'],
					['signal' => 'contract-expiring', 'points' => 30, 'since' => '2026-12-31'],
					['signal' => 'contract-churned', 'points' => 40, 'since' => '2026-10-29'],
				],
			]
		);

		$this->assertSame('High', $presented['label']);
		$this->assertSame('Contract ended on 29 Oct 2026', $presented['caption']);
		$labels = array_column($presented['reasons'], 'label');
		$this->assertContains('2 open complaints', $labels);
		$this->assertContains('1 deadline passed', $labels);
		$this->assertContains('No contact since 1 Jun 2026', $labels);
		$this->assertContains('Unhappy survey answer on 15 Oct 2026', $labels);
		$this->assertContains('Contract ends on 31 Dec 2026', $labels);
	}//end testEverySignalReadsAndTheHeaviestLeads()
}//end class
