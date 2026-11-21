<?php

/**
 * BlastService::audienceRecipients(): the members the send's gate lets through.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BlastService;
use PHPUnit\Framework\TestCase;

/**
 * BlastService audience for a preview.
 */
class BlastAudienceRecipientsTest extends TestCase {

	/**
	 * The members of the resolved audience come back; the skipped ones do not.
	 *
	 * @return void
	 */
	public function testReturnsTheResolvedMembers(): void {
		$blast = $this->getMockBuilder(BlastService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveAudience'])
			->getMock();
		$blast->expects(self::once())->method('resolveAudience')
			->with('seg-1', '', 'email')
			->willReturn(['members' => [['contactId' => 'c-1']], 'missingConsent' => ['c-2'], 'suppressed' => []]);

		self::assertSame([['contactId' => 'c-1']], $blast->audienceRecipients('seg-1', '', 'email'));
	}//end testReturnsTheResolvedMembers()

	/**
	 * Without a segment or a list there is no audience to resolve.
	 *
	 * @return void
	 */
	public function testNoAudienceResolvesNothing(): void {
		$blast = $this->getMockBuilder(BlastService::class)
			->disableOriginalConstructor()
			->onlyMethods(['resolveAudience'])
			->getMock();
		$blast->expects(self::never())->method('resolveAudience');

		self::assertSame([], $blast->audienceRecipients('', '', 'email'));
	}//end testNoAudienceResolvesNothing()
}//end class
