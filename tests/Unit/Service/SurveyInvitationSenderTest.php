<?php
/**
 * Tests for SurveyInvitationSender's response link.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\SurveyInvitationSender;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The link in an invitation opens a page a person can answer, not raw JSON.
 */
class SurveyInvitationSenderTest extends TestCase {

	/**
	 * Build the sender over an url generator that prefixes a host.
	 *
	 * @return SurveyInvitationSender The sender.
	 */
	private function sender(): SurveyInvitationSender {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://cloud.example'.$path
		);

		return new SurveyInvitationSender(
			$this->createMock(IMailer::class),
			$urls,
			$this->createMock(LoggerInterface::class),
		);
	}//end sender()

	/**
	 * The link opens the public survey page in the customer portal.
	 *
	 * The JSON endpoint under /survey/i/ is what that page reads; a respondent
	 * who opened it directly saw raw JSON and had no way to answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-survey-fatigue-throttling-and-opt-out
	 */
	public function testTheLinkOpensThePublicSurveyPage(): void {
		$this->assertSame(
			'https://cloud.example/index.php/apps/pipelinq/portal/survey/abc%2F1',
			$this->sender()->linkFor(token: 'abc/1')
		);
	}//end testTheLinkOpensThePublicSurveyPage()
}//end class
