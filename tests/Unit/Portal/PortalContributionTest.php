<?php

/**
 * Unit tests for PortalContribution.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Portal
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Portal;

use OCA\Pipelinq\Kernel\Contracts\PortalContributionInterface;
use OCA\Pipelinq\Portal\PortalContribution;
use OCA\Pipelinq\Portal\PortalContributionProvider;
use PHPUnit\Framework\TestCase;

/**
 * The in-app contract hands every manifest request to the provider portaliq discovers.
 *
 * @covers \OCA\Pipelinq\Portal\PortalContribution
 */
final class PortalContributionTest extends TestCase {
	/**
	 * Every audience gets exactly the provider's manifest.
	 *
	 * @return void
	 */
	public function testGetContributionAnswersWhatTheProviderAnswers(): void {
		$provider     = new PortalContributionProvider();
		$contribution = new PortalContribution($provider);

		foreach (['client', 'customer', 'citizen', 'unknown'] as $audience) {
			$subject = ['audience' => $audience, 'subjectRef' => 'subject-1'];
			$this->assertSame($provider->getContribution($subject), $contribution->getContribution($subject));
		}

		$this->assertNull($contribution->getContribution(['audience' => 'unknown']));
	}//end testGetContributionAnswersWhatTheProviderAnswers()

	/**
	 * The provider keeps the rule key the contract declares.
	 *
	 * @return void
	 */
	public function testTheRuleKeyIsTheContractsRuleKey(): void {
		$this->assertSame('pipelinq.question.answered', PortalContributionInterface::RULE_QUESTION_ANSWERED);
		$this->assertSame(PortalContributionInterface::RULE_QUESTION_ANSWERED, PortalContributionProvider::RULE_QUESTION_ANSWERED);
	}//end testTheRuleKeyIsTheContractsRuleKey()
}//end class
