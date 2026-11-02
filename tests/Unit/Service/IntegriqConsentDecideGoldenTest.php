<?php

/**
 * Golden test for IntegriqConsentClient's decision questions.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\Consent\ConsentAsk;
use OCA\Pipelinq\Service\Consent\ConsentQuestion;
use OCA\Pipelinq\Service\IntegriqConsentClient;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every answer decide(), decideOne() and probeOne() give, and every question
 * they put to integriq, compared with IntegriqConsentDecideGolden.json. Set
 * CONSENT_DECIDE_GOLDEN_RECORD=1 to rewrite the file, then run once more
 * without it.
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md
 */
class IntegriqConsentDecideGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/IntegriqConsentDecideGolden.json';

	/**
	 * Warnings logged during the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $warnings = [];

	/**
	 * Run every scenario and compare it with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => $scenario) {
			$actual[$name] = $this->runScenario(scenario: $scenario);
		}

		$json = json_encode($actual, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n";
		if (getenv('CONSENT_DECIDE_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, $json);
			$this->markTestSkipped('recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		foreach ($expected as $name => $want) {
			$this->assertSame($want, ($actual[$name] ?? null), $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testGolden()

	/**
	 * The scenarios: the call, its arguments, and integriq's mode and rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function scenarios(): array {
		$optedOut = ['address' => 'out@example.nl', 'state' => 'opted-out', 'scope' => 'instance', 'channel' => ''];
		$recipients = [
			['address' => 'out@example.nl', 'contactRef' => 'c-1'],
			['address' => 'ok@example.nl'],
			['address' => '   '],
			'not a row',
			['contactRef' => 'no-address'],
		];
		return [
			'decide: marketing, consent, one opted out' => ['call' => 'decide', 'args' => ['channel' => 'email', 'category' => 'marketing', 'requiresConsent' => true, 'recipients' => $recipients, 'correlationId' => 'corr-1'], 'rows' => [$optedOut]],
			'decide: service, no consent needed'       => ['call' => 'decide', 'args' => ['channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'recipients' => $recipients], 'rows' => [$optedOut]],
			'decide: account is exempt'                => ['call' => 'decide', 'args' => ['channel' => 'sms', 'category' => 'account', 'requiresConsent' => false, 'recipients' => [['address' => 'out@example.nl']]], 'rows' => [$optedOut]],
			'decide: reply in a thread, probe'         => ['call' => 'decide', 'args' => ['channel' => 'whatsapp', 'category' => 'reply', 'requiresConsent' => false, 'recipients' => [['address' => '+31600000000']], 'correlationId' => 'c', 'inReplyTo' => 'in-1', 'probe' => true]],
			'decide: unhandled'                        => ['call' => 'decide', 'args' => ['channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'recipients' => $recipients], 'mode' => FakeIntegriq::MODE_UNHANDLED],
			'decide: listener throws'                  => ['call' => 'decide', 'args' => ['channel' => 'email', 'category' => 'account', 'requiresConsent' => false, 'recipients' => $recipients], 'mode' => FakeIntegriq::MODE_THROW],
			'decide: event class missing'              => ['call' => 'decide', 'args' => ['channel' => 'email', 'category' => 'marketing', 'requiresConsent' => false, 'recipients' => $recipients], 'event' => 'Event\\NoSuchDecisionEvent'],
			'decide: more than one chunk'              => ['call' => 'decide', 'args' => ['channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'recipients' => $this->many(count: 503), 'correlationId' => 'bulk']],
			'decide: nobody'                           => ['call' => 'decide', 'args' => ['channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'recipients' => []]],
			'decideOne: allowed with list ref'         => ['call' => 'decideOne', 'args' => ['channel' => 'email', 'category' => 'marketing', 'requiresConsent' => false, 'address' => 'ok@example.nl', 'contactRef' => 'c-2', 'listRef' => 'l-1']],
			'decideOne: opted out by contact'          => ['call' => 'decideOne', 'args' => ['channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'address' => 'new@example.nl', 'contactRef' => 'c-1'], 'rows' => [[...$optedOut, 'contactRef' => 'c-1']]],
			'decideOne: reply'                         => ['call' => 'decideOne', 'args' => ['channel' => 'sms', 'category' => 'reply', 'requiresConsent' => false, 'address' => '+31611111111', 'inReplyTo' => 'm-1']],
			'decideOne: no address'                    => ['call' => 'decideOne', 'args' => ['channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'address' => '  ']],
			'decideOne: unhandled'                     => ['call' => 'decideOne', 'args' => ['channel' => 'email', 'category' => 'reminder', 'requiresConsent' => false, 'address' => 'ok@example.nl'], 'mode' => FakeIntegriq::MODE_UNHANDLED],
			'probeOne: allowed'                        => ['call' => 'probeOne', 'args' => ['channel' => 'email', 'category' => 'marketing', 'requiresConsent' => true, 'address' => 'ok@example.nl', 'contactRef' => 'c-3'], 'rows' => [['address' => 'ok@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email']]],
			'probeOne: no address'                     => ['call' => 'probeOne', 'args' => ['channel' => 'sms', 'category' => 'account', 'requiresConsent' => false, 'address' => '']],
			'probeOne: listener throws'                => ['call' => 'probeOne', 'args' => ['channel' => 'email', 'category' => 'service', 'requiresConsent' => false, 'address' => 'ok@example.nl'], 'mode' => FakeIntegriq::MODE_THROW],
		];
	}//end scenarios()

	/**
	 * Run one scenario against a fresh client and integriq.
	 *
	 * @param array<string, mixed> $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function runScenario(array $scenario): array {
		$this->warnings = [];
		$integriq = new FakeIntegriq();
		$integriq->mode = ($scenario['mode'] ?? FakeIntegriq::MODE_ANSWER);
		foreach (($scenario['rows'] ?? []) as $row) {
			$integriq->seed($row);
		}

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturn('https://pipelinq.example/');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->warnings[] = ['message' => $message, 'context' => $context];
		});

		$client = new IntegriqConsentClient(
			dispatcher: $integriq,
			appConfig: $this->createMock(IAppConfig::class),
			urlGenerator: $urls,
			logger: $logger,
			decisionEvent: ($scenario['event'] ?? IntegriqConsentClient::DECISION_EVENT),
		);
		$result = $this->call(client: $client, scenario: $scenario);

		$questions = [];
		foreach ($integriq->decisionEvents as $event) {
			$questions[] = [
				'sourceApp' => $event->getSourceApp(),
				'channel' => $event->getChannel(),
				'category' => $event->getCategory(),
				'recipients' => count($event->getRecipients()),
				'first' => ($event->getRecipients()[0] ?? null),
				'correlationId' => preg_replace('/^pipelinq-[0-9a-f]{16}$/', 'pipelinq-<random>', $event->getCorrelationId()),
				'baseUrl' => $event->getBaseUrl(),
				'requiresConsent' => $event->requiresConsent(),
				'probe' => $event->isProbe(),
				'inReplyTo' => $event->getInReplyTo(),
			];
		}

		if (count($result) > 6) {
			$result = ['count' => count($result), 'first' => reset($result), 'last' => end($result)];
		}

		return ['result' => $result, 'questions' => $questions, 'warnings' => $this->warnings];
	}//end runScenario()

	/**
	 * Call the scenario's method, its arguments put in the client's value objects.
	 *
	 * @param IntegriqConsentClient $client   The client.
	 * @param array<string, mixed>  $scenario The scenario.
	 *
	 * @return array<string, mixed>
	 */
	private function call(IntegriqConsentClient $client, array $scenario): array {
		$args = $scenario['args'];
		$ask = new ConsentAsk(channel: $args['channel'], category: $args['category'], requiresConsent: $args['requiresConsent']);
		if ($scenario['call'] === 'decide') {
			$question = new ConsentQuestion(
				ask: $ask,
				correlationId: ($args['correlationId'] ?? ''),
				inReplyTo: ($args['inReplyTo'] ?? null),
				probe: ($args['probe'] ?? false),
			);
			return $client->decide(question: $question, recipients: $args['recipients']);
		}

		$recipient = array_diff_key($args, ['channel' => true, 'category' => true, 'requiresConsent' => true]);
		return $client->{$scenario['call']}(ask: $ask, recipient: $recipient);
	}//end call()

	/**
	 * Distinct recipients, for a send larger than one chunk.
	 *
	 * @param int $count How many.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function many(int $count): array {
		$out = [];
		for ($i = 1; $i <= $count; $i++) {
			$out[] = ['address' => 'r' . $i . '@example.nl'];
		}

		return $out;
	}//end many()
}//end class
