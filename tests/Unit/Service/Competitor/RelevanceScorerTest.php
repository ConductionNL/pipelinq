<?php

/**
 * Tests for RelevanceScorer.
 *
 * The whole point of this class is what it does when it cannot score, so that
 * is what is asserted: no hermiq, an unparsable answer and an out-of-range
 * answer all leave the event with NO `relevanceScore` key. A zero would sort
 * with the genuinely irrelevant items and the unscored ones would never be
 * read again.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Competitor
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Competitor;

use OCA\Pipelinq\Service\Competitor\RelevanceScorer;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A stand-in for hermiq's provider factory, resolved by name at run time.
 */
class FakeProviderFactory {

	/**
	 * What the model will answer.
	 *
	 * @var string
	 */
	public string $answer = '';

	/**
	 * How often it was asked.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Answer whatever the test set.
	 *
	 * @param string $prompt The prompt.
	 * @param string|null $userId The acting user.
	 * @param bool $allowNextcloud Whether the Nextcloud provider may be used.
	 * @param string|null $organisation The organisation.
	 *
	 * @return string
	 */
	public function generateText(string $prompt, ?string $userId = null, bool $allowNextcloud = true, ?string $organisation = null): string {
		$this->calls++;

		return $this->answer;
	}//end generateText()
}//end class

/**
 * @covers \OCA\Pipelinq\Service\Competitor\RelevanceScorer
 */
class RelevanceScorerTest extends TestCase {

	/**
	 * One watch item.
	 *
	 * @return array<string, mixed>
	 */
	private function item(): array {
		return [
			'url' => 'https://example.org/1',
			'title' => 'Voorbeeld B.V. wint aanbesteding',
			'summary' => 'Een alinea.',
		];
	}//end item()

	/**
	 * A scorer whose setting and hermiq availability the test decides.
	 *
	 * @param bool $enabled Whether scoring is on.
	 * @param FakeProviderFactory|null $factory The fake hermiq, or null for none.
	 *
	 * @return RelevanceScorer
	 */
	private function scorer(bool $enabled, ?FakeProviderFactory $factory): RelevanceScorer {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($enabled): string {
				if ($key === RelevanceScorer::ENABLED_KEY) {
					if ($enabled === true) {
						return 'true';
					}

					return 'false';
				}

				return $default;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		if ($factory === null) {
			$container->method('get')->willThrowException(new RuntimeException('hermiq is not installed'));
		} else {
			$container->method('get')->willReturn($factory);
		}

		return new RelevanceScorer(
			container: $container,
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end scorer()

	/**
	 * Scoring is off unless an administrator turns it on, and nothing is
	 * sent to hermiq while it is off.
	 *
	 * @return void
	 */
	public function testDoesNotCallHermiqWhenTheSettingIsOff(): void {
		$factory = new FakeProviderFactory();
		$factory->answer = '{"score": 90, "reason": "raakt onze doelgroep"}';

		$fields = $this->scorer(enabled: false, factory: $factory)->fieldsFor(item: $this->item());

		$this->assertSame([], $fields);
		$this->assertSame(0, $factory->calls);
	}//end testDoesNotCallHermiqWhenTheSettingIsOff()

	/**
	 * With hermiq absent the event is unscored, not scored zero.
	 *
	 * @return void
	 */
	public function testLeavesTheEventUnscoredWithoutHermiq(): void {
		$fields = $this->scorer(enabled: true, factory: null)->fieldsFor(item: $this->item());

		$this->assertSame([], $fields);
		$this->assertArrayNotHasKey('relevanceScore', $fields);
	}//end testLeavesTheEventUnscoredWithoutHermiq()

	/**
	 * A degraded score is never written as a zero.
	 *
	 * @return void
	 */
	public function testNeverWritesZeroAsADegradedScore(): void {
		foreach (['', 'very relevant', 'null'] as $answer) {
			$factory = new FakeProviderFactory();
			$factory->answer = $answer;

			$fields = $this->scorer(enabled: true, factory: $factory)->fieldsFor(item: $this->item());

			$this->assertSame([], $fields, 'answer: ' . $answer);
		}
	}//end testNeverWritesZeroAsADegradedScore()

	/**
	 * An answer outside the range is not clamped: a model that says 180 has
	 * not scored the item, and clamping would turn that into a top result.
	 *
	 * @return void
	 */
	public function testAnAnswerOutsideTheRangeIsUnscored(): void {
		$this->assertNull($this->scorer(enabled: true, factory: null)->parse(answer: '180'));
		$this->assertNull($this->scorer(enabled: true, factory: null)->parse(answer: '{"score": 180}'));
		$this->assertNull($this->scorer(enabled: true, factory: null)->parse(answer: '{"score": -5}'));
	}//end testAnAnswerOutsideTheRangeIsUnscored()

	/**
	 * Prose is not a score.
	 *
	 * @return void
	 */
	public function testANonNumericAnswerIsUnscored(): void {
		$this->assertNull($this->scorer(enabled: true, factory: null)->parse(answer: 'very relevant'));
		$this->assertNull($this->scorer(enabled: true, factory: null)->parse(answer: '   '));
	}//end testANonNumericAnswerIsUnscored()

	/**
	 * A well-formed answer becomes a score, a reason and the agent mark
	 * ADR-088 requires.
	 *
	 * @return void
	 */
	public function testAWellFormedAnswerIsScoredAndMarked(): void {
		$factory = new FakeProviderFactory();
		$factory->answer = '{"score": 72, "reason": "Raakt dezelfde aanbestedingen."}';

		$fields = $this->scorer(enabled: true, factory: $factory)->fieldsFor(item: $this->item());

		$this->assertSame(72, $fields['relevanceScore']);
		$this->assertSame('Raakt dezelfde aanbestedingen.', $fields['relevanceReason']);
		$this->assertTrue($fields['agentAuthored']);
	}//end testAWellFormedAnswerIsScoredAndMarked()

	/**
	 * A bare number is accepted too, because a model asked for JSON often
	 * answers with the number alone.
	 *
	 * @return void
	 */
	public function testABareNumberIsAccepted(): void {
		$parsed = $this->scorer(enabled: true, factory: null)->parse(answer: '61');

		$this->assertIsArray($parsed);
		$this->assertSame(61, $parsed['score']);
	}//end testABareNumberIsAccepted()

	/**
	 * Both ends of the range are inside it.
	 *
	 * @return void
	 */
	public function testBothEndsOfTheRangeAreAccepted(): void {
		$scorer = $this->scorer(enabled: true, factory: null);
		$lowest = $scorer->parse(answer: '0');
		$highest = $scorer->parse(answer: '100');

		$this->assertIsArray($lowest);
		$this->assertIsArray($highest);
		$this->assertSame(0, $lowest['score']);
		$this->assertSame(100, $highest['score']);
	}//end testBothEndsOfTheRangeAreAccepted()
}//end class
