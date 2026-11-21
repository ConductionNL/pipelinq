<?php

/**
 * Unit tests for ConsentSubject.
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

use OCA\Pipelinq\Service\Consent\ConsentAsk;
use OCA\Pipelinq\Service\Consent\ConsentSubject;
use PHPUnit\Framework\TestCase;

/**
 * The consent question a subject asks is about the subject's own channel.
 *
 * @covers \OCA\Pipelinq\Service\Consent\ConsentSubject
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentAsk
 */
class ConsentSubjectTest extends TestCase {

	/**
	 * An ask carries the subject's channel and the category and need it was given.
	 *
	 * @return void
	 */
	public function testAnAskIsForTheSubjectsChannel(): void {
		$subject = new ConsentSubject(contactId: 'c-7', channel: 'sms', address: '+31600000000');

		$ask = $subject->askFor(category: 'marketing', requiresConsent: true);

		$this->assertInstanceOf(ConsentAsk::class, $ask);
		$this->assertSame(['sms', 'marketing', true], [$ask->channel, $ask->category, $ask->requiresConsent]);
	}//end testAnAskIsForTheSubjectsChannel()

	/**
	 * A service message that needs no consent is asked as such, and each ask is its own.
	 *
	 * @return void
	 */
	public function testEachAskKeepsItsOwnCategoryAndNeed(): void {
		$subject = new ConsentSubject(contactId: 'c-7', channel: 'email', address: 'anna@example.nl');

		$service   = $subject->askFor(category: 'service', requiresConsent: false);
		$marketing = $subject->askFor(category: 'marketing', requiresConsent: true);

		$this->assertNotSame($service, $marketing);
		$this->assertSame(['email', 'service', false], [$service->channel, $service->category, $service->requiresConsent]);
		$this->assertSame(['email', 'marketing', true], [$marketing->channel, $marketing->category, $marketing->requiresConsent]);
	}//end testEachAskKeepsItsOwnCategoryAndNeed()
}//end class
