<?php

/**
 * Pipelinq RelevanceScorer.
 *
 * Asks hermiq how relevant one competitor item is to what this tenant cares
 * about, on a scale of 0 to 100.
 *
 * IT DEGRADES TO UNSCORED, NEVER TO ZERO. When hermiq is absent, when its
 * provider is unconfigured, or when it answers something that is not a number
 * in range, the event is stored with NO `relevanceScore` key. A zero would be
 * a lie with the same shape as a real answer: the page sorts by relevance, so
 * every unscored event would sink to the bottom and never be read again. An
 * absent field is the honest value and the page renders it as "not scored".
 *
 * IT IS OFF BY DEFAULT. Scoring sends a competitor's headline to whatever
 * model the tenant configured, and that is a decision an administrator makes
 * on purpose rather than one that arrives with an upgrade.
 *
 * A scored event is marked agent-authored (ADR-088). The item itself never is:
 * it is somebody else's publication, and only the judgement about it is ours.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Competitor
 *
 * @author    Conduction <info@conduction.nl>
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Competitor;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Relevance scoring through hermiq, or nothing at all.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
 */
class RelevanceScorer {

	/**
	 * App-config key turning scoring on. Off unless it says `true`.
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'competitor.relevance';

	/**
	 * App-config key holding what this tenant considers relevant, in the
	 * tenant's own words. It is put in front of the model verbatim.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'competitor.relevance_context';

	/**
	 * Hermiq's provider factory, resolved by name at run time.
	 *
	 * @var string
	 */
	public const PROVIDER_CLASS = 'OCA\\Hermiq\\Service\\Llm\\ProviderFactory';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the lazy hermiq resolve.
	 * @param IAppConfig $appConfig Pipelinq app config.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
	 */
	public function __construct(
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether scoring is switched on.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
	 */
	public function enabled(): bool {
		return ($this->appConfig->getValueString(Application::APP_ID, self::ENABLED_KEY, 'false') === 'true');
	}//end enabled()

	/**
	 * The fields a watch event gains from scoring: `relevanceScore`,
	 * `relevanceReason` and `agentAuthored`, or an EMPTY array when the item
	 * was not scored.
	 *
	 * @param array<string, mixed> $item The watch item.
	 * @param string|null $actingUserId The identity the run acts as.
	 *
	 * @return array<string, mixed> The fields to merge, possibly empty.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
	 */
	public function fieldsFor(array $item, ?string $actingUserId = null): array {
		if ($this->enabled() === false) {
			return [];
		}

		$factory = $this->provider();
		if ($factory === null) {
			return [];
		}

		try {
			$answer = $factory->generateText($this->prompt(item: $item), $actingUserId, true, null);
		} catch (Throwable $e) {
			$this->logger->info('RelevanceScorer: hermiq did not answer', ['exception' => $e->getMessage()]);
			return [];
		}

		$parsed = $this->parse(answer: (string)$answer);
		if ($parsed === null) {
			return [];
		}

		return [
			'relevanceScore' => $parsed['score'],
			'relevanceReason' => $parsed['reason'],
			'agentAuthored' => true,
		];
	}//end fieldsFor()

	/**
	 * Read a score out of the model's answer.
	 *
	 * @param string $answer What the model said.
	 *
	 * @return array{score: int, reason: string}|null The score, or null when the answer is not one.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
	 */
	public function parse(string $answer): ?array {
		$trimmed = trim($answer);
		if ($trimmed === '') {
			return null;
		}

		$decoded = json_decode($trimmed, true);
		if (is_array($decoded) === true && array_key_exists('score', $decoded) === true) {
			return $this->bounded(score: $decoded['score'], reason: (string)($decoded['reason'] ?? ''));
		}

		if (preg_match('/-?\d+/', $trimmed, $matches) !== 1) {
			return null;
		}

		return $this->bounded(score: $matches[0], reason: '');
	}//end parse()

	/**
	 * Accept a score only inside the range the prompt asked for. A model that
	 * answers 180 has not scored the item; clamping it to 100 would turn a
	 * misunderstanding into a confident top result.
	 *
	 * @param mixed $score What the model gave.
	 * @param string $reason The reason it gave, if any.
	 *
	 * @return array{score: int, reason: string}|null
	 */
	private function bounded(mixed $score, string $reason): ?array {
		if (is_numeric($score) === false) {
			return null;
		}

		$value = (int)$score;
		if ($value < 0 || $value > 100) {
			return null;
		}

		return ['score' => $value, 'reason' => mb_substr(trim($reason), 0, 500, 'UTF-8')];
	}//end bounded()

	/**
	 * The prompt. It asks for JSON and says what to do when unsure, because
	 * "I am not sure" as prose is what makes an answer unparsable, and an
	 * unparsable answer is silently discarded.
	 *
	 * @param array<string, mixed> $item The watch item.
	 *
	 * @return string
	 */
	private function prompt(array $item): string {
		$context = trim($this->appConfig->getValueString(Application::APP_ID, self::CONTEXT_KEY, ''));
		if ($context === '') {
			$context = 'the marketing and sales interests of this organisation';
		}

		return implode(
			"\n",
			[
				'Score how relevant this item is to ' . $context . '.',
				'Answer with JSON only: {"score": <integer 0 to 100>, "reason": "<one short sentence>"}.',
				'If you cannot judge it, answer {"score": 50, "reason": "not enough information"}.',
				'',
				'Title: ' . (string)($item['title'] ?? ''),
				'URL: ' . (string)($item['url'] ?? ''),
				'Summary: ' . mb_substr((string)($item['summary'] ?? ''), 0, 1000, 'UTF-8'),
			]
		);
	}//end prompt()

	/**
	 * Hermiq's provider factory, or null when hermiq is absent.
	 *
	 * @return object|null
	 */
	private function provider(): ?object {
		try {
			$factory = $this->container->get(self::PROVIDER_CLASS);
		} catch (Throwable) {
			return null;
		}

		if (method_exists($factory, 'generateText') === false) {
			return null;
		}

		return $factory;
	}//end provider()
}//end class
