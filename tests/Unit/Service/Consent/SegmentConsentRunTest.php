<?php

/**
 * Unit tests for SegmentConsentRun.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Consent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Consent;

use OCA\Pipelinq\Service\Consent\SegmentConsentRun;
use PHPUnit\Framework\TestCase;

/**
 * The tally of one segment consent check.
 *
 * @covers \OCA\Pipelinq\Service\Consent\SegmentConsentRun
 */
class SegmentConsentRunTest extends TestCase {

	/**
	 * A run with nothing recorded is compliant and empty.
	 *
	 * @return void
	 */
	public function testAnEmptyRunIsCompliant(): void {
		$run = new SegmentConsentRun(channel: 'email', intent: 'marketing');

		$this->assertSame('email', $run->channel);
		$this->assertSame('marketing', $run->intent);
		$this->assertSame(
			['compliant' => true, 'missingConsent' => [], 'missingCount' => 0, 'suppressed' => [], 'suppressedCount' => 0],
			$run->result()
		);
	}//end testAnEmptyRunIsCompliant()

	/**
	 * A contact without a decision may not be sent to.
	 *
	 * @return void
	 */
	public function testAContactWithoutADecisionIsRefused(): void {
		$run = new SegmentConsentRun(channel: 'email', intent: 'marketing');
		$run->useDecisions(['c-1' => ['send' => true, 'unsubscribe' => 'https://u/1']]);

		$this->assertSame(['send' => true, 'unsubscribe' => 'https://u/1'], $run->decisionFor('c-1'));
		$this->assertSame(['send' => false, 'unsubscribe' => null], $run->decisionFor('c-2'));
	}//end testAContactWithoutADecisionIsRefused()

	/**
	 * Missing and suppressed contacts are listed once each, in first-seen
	 * order, and any missing contact makes the run non-compliant.
	 *
	 * @return void
	 */
	public function testMissingAndSuppressedAreDeduplicated(): void {
		$run = new SegmentConsentRun(channel: 'sms', intent: 'service');
		$run->missing('c-2');
		$run->missing('c-1');
		$run->missing('c-2');
		$run->suppressed('c-3');
		$run->suppressed('c-3');

		$this->assertSame(
			[
				'compliant' => false,
				'missingConsent' => ['c-2', 'c-1'],
				'missingCount' => 2,
				'suppressed' => ['c-3'],
				'suppressedCount' => 1,
			],
			$run->result()
		);
	}//end testMissingAndSuppressedAreDeduplicated()

	/**
	 * Suppressed contacts alone keep the run compliant.
	 *
	 * @return void
	 */
	public function testSuppressionAloneStaysCompliant(): void {
		$run = new SegmentConsentRun(channel: 'email', intent: 'marketing');
		$run->suppressed('c-1');

		$this->assertTrue($run->result()['compliant']);
	}//end testSuppressionAloneStaysCompliant()

	/**
	 * The result with links adds the unsubscribe links, dropping empty ones
	 * and keeping the last link given per contact.
	 *
	 * @return void
	 */
	public function testResultWithLinksKeepsOnlyNonEmptyLinks(): void {
		$run = new SegmentConsentRun(channel: 'email', intent: 'marketing');
		$run->link('c-1', 'https://u/old');
		$run->link('c-1', 'https://u/1');
		$run->link('c-2', '');
		$run->missing('c-3');

		$result = $run->resultWithLinks();

		$this->assertSame(['c-1' => 'https://u/1'], $result['unsubscribe']);
		$this->assertSame(['c-3'], $result['missingConsent']);
		$this->assertFalse($result['compliant']);
		$this->assertArrayNotHasKey('unsubscribe', $run->result());
	}//end testResultWithLinksKeepsOnlyNonEmptyLinks()
}//end class
