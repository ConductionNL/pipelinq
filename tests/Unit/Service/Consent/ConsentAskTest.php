<?php

/**
 * Unit tests for ConsentAsk.
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
use OCA\Pipelinq\Service\Consent\ConsentQuestion;
use PHPUnit\Framework\TestCase;

/**
 * An ask becomes a plain question: no correlation, no reply, no probe.
 *
 * @covers \OCA\Pipelinq\Service\Consent\ConsentAsk
 * @uses   \OCA\Pipelinq\Service\Consent\ConsentQuestion
 */
class ConsentAskTest extends TestCase {

	/**
	 * The question carries the ask itself and none of the correlation of a reply or probe.
	 *
	 * @return void
	 */
	public function testTheQuestionIsAPlainQuestionAboutThisAsk(): void {
		$ask = new ConsentAsk(channel: 'email', category: 'marketing', requiresConsent: true);

		$question = $ask->question();

		$this->assertInstanceOf(ConsentQuestion::class, $question);
		$this->assertSame($ask, $question->ask);
		$this->assertSame('', $question->correlationId);
		$this->assertNull($question->inReplyTo);
		$this->assertFalse($question->probe);
	}//end testTheQuestionIsAPlainQuestionAboutThisAsk()

	/**
	 * Each call gives a new question about the same ask.
	 *
	 * @return void
	 */
	public function testEveryCallGivesANewQuestion(): void {
		$ask = new ConsentAsk(channel: 'sms', category: 'service', requiresConsent: false);

		$first  = $ask->question();
		$second = $ask->question();

		$this->assertNotSame($first, $second);
		$this->assertSame($first->ask, $second->ask);
		$this->assertSame(['sms', 'service', false], [$first->ask->channel, $first->ask->category, $first->ask->requiresConsent]);
	}//end testEveryCallGivesANewQuestion()
}//end class
