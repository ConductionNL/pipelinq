<?php

/**
 * The recipients a template's variables leave empty.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
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

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\Pipelinq\Service\Marketing\EmptyVariableCounter;
use OCA\Pipelinq\Service\Marketing\RecipientNameReader;
use PHPUnit\Framework\TestCase;

/**
 * EmptyVariableCounter.
 */
class EmptyVariableCounterTest extends TestCase {

	/**
	 * A counter whose name reader knows the given names by contact id.
	 *
	 * @param array<string, string> $names Greeting name by contact id.
	 *
	 * @return EmptyVariableCounter The counter.
	 */
	private function counter(array $names = []): EmptyVariableCounter {
		$reader = $this->createMock(RecipientNameReader::class);
		$reader->method('greetingName')->willReturnCallback(
			static fn (string $recipientId): string => ($names[$recipientId] ?? '')
		);

		return new EmptyVariableCounter(recipientNames: $reader);
	}//end counter()

	/**
	 * Both brace forms count, once each, in order; the articles marker does not.
	 *
	 * @return void
	 */
	public function testUsedVariablesReadsSubjectAndBothBodies(): void {
		$template = [
			'subject' => 'Hello {{ preferredName }}',
			'bodyHtml' => '<p>{{{preferredName}}}</p>{{articles}}<a href="{{unsubscribe_link}}">x</a>',
			'bodyText' => 'From {{city}}',
		];

		self::assertSame(
			['preferredName', 'unsubscribe_link', 'city'],
			$this->counter()->usedVariables(template: $template)
		);
	}//end testUsedVariablesReadsSubjectAndBothBodies()

	/**
	 * A recipient without a name counts against preferredName; one with a name does not.
	 *
	 * @return void
	 */
	public function testARecipientWithoutANameIsCounted(): void {
		$counter = $this->counter(names: ['c-1' => 'Anna']);
		$template = ['subject' => 'Dear {{preferredName}},'];
		$recipients = [
			['contactId' => 'c-1', 'email' => 'anna@example.nl'],
			['contactId' => 'c-2', 'email' => 'bob@example.nl'],
			['contactId' => 'c-3', 'email' => 'cor@example.nl'],
		];

		self::assertSame(
			[['variable' => 'preferredName', 'count' => 2]],
			$counter->count(template: $template, recipients: $recipients)
		);
	}//end testARecipientWithoutANameIsCounted()

	/**
	 * A variable the send never fills counts every recipient, as the board's city example.
	 *
	 * @return void
	 */
	public function testAVariableTheSendDoesNotFillCountsEveryone(): void {
		$recipients = array_fill(0, 12, ['contactId' => 'c', 'email' => 'x@example.nl']);

		self::assertSame(
			[['variable' => 'city', 'count' => 12]],
			$this->counter()->count(template: ['bodyText' => 'From {{city}}'], recipients: $recipients)
		);
	}//end testAVariableTheSendDoesNotFillCountsEveryone()

	/**
	 * Row values are read where the send reads them; a filled variable is left out.
	 *
	 * @return void
	 */
	public function testRowValuesAreReadAsTheSendReadsThem(): void {
		$template = ['bodyHtml' => '{{email}} {{contactId}} <a href="{{unsubscribe_link}}">x</a>'];
		$recipients = [
			['contactId' => 'c-1', 'email' => 'a@example.nl', 'unsubscribeUrl' => 'https://u/1'],
			['contactId' => 'c-2', 'email' => 'b@example.nl', 'unsubscribeUrl' => '  '],
		];

		self::assertSame(
			[['variable' => 'unsubscribe_link', 'count' => 1]],
			$this->counter()->count(template: $template, recipients: $recipients)
		);
	}//end testRowValuesAreReadAsTheSendReadsThem()

	/**
	 * No recipients, or no variables, means no gaps.
	 *
	 * @return void
	 */
	public function testNothingMissingIsAnEmptyList(): void {
		self::assertSame([], $this->counter()->count(template: ['subject' => '{{city}}'], recipients: []));
		self::assertSame([], $this->counter()->count(template: ['subject' => 'Plain'], recipients: [['contactId' => 'c']]));
	}//end testNothingMissingIsAnEmptyList()
}//end class
