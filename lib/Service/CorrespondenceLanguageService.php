<?php

/**
 * Pipelinq CorrespondenceLanguageService.
 *
 * Which language to write to a party in, and which rule produced that answer.
 *
 * THE ANSWER ALWAYS NAMES ITS RULE. A resolver that returns `nl` tells a
 * caller nothing about whether the party asked for Dutch or simply never said
 * anything, and those two are different facts on a letter. So every answer
 * carries the rule that produced it: the party's own preference, the instance
 * default, or the floor.
 *
 * NOTHING IS INFERRED. Not from a name, not from an address, not from a
 * nationality. A preference is something a person states, and guessing it from
 * a surname is how an organisation ends up writing to people in a language
 * they never chose.
 *
 * THE SELECTABLE SET IS THE INSTANCE'S, NOT THE SCHEMA'S. An enum in the
 * schema goes stale the day a language pack is added or removed, and offering
 * a tag nothing can render produces a letter nobody can read.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-language-to-write-in-resolves-with-its-reason-req-pcl-003
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolve the language to write to a party in, with its reason.
 *
 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-a-party-carries-the-language-it-asked-to-be-written-in-req-pcl-001
 */
class CorrespondenceLanguageService {
	/**
	 * The rule that produced an answer: the party asked for it.
	 *
	 * @var string
	 */
	public const RULE_PARTY = 'party';

	/**
	 * The rule that produced an answer: the instance default.
	 *
	 * @var string
	 */
	public const RULE_INSTANCE_DEFAULT = 'instanceDefault';

	/**
	 * The rule that produced an answer: the floor, when nothing else answered.
	 *
	 * @var string
	 */
	public const RULE_FALLBACK = 'fallback';

	/**
	 * The floor. English, because it is the source language of every string
	 * this fleet ships, so it is the one tag always renderable.
	 *
	 * @var string
	 */
	public const FALLBACK = 'en';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config, holding the register and schema ids.
	 * @param IConfig $config The system config, holding the instance default language.
	 * @param IFactory $l10nFactory Answers which languages the instance ships.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IConfig $config,
		private readonly IFactory $l10nFactory,
		private readonly ObjectServiceInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The language tags this instance can actually render.
	 *
	 * @return array<int, string> The available tags, sorted, always including
	 *   the floor.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002
	 */
	public function available(): array {
		try {
			$languages = $this->l10nFactory->findAvailableLanguages(Application::APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CorrespondenceLanguageService: could not read the available languages',
				['exception' => $e->getMessage()]
			);
			$languages = [];
		}

		$tags = [];
		foreach ($languages as $language) {
			$tag = trim((string)$language);
			if ($tag !== '' && in_array($tag, $tags, true) === false) {
				$tags[] = $tag;
			}
		}

		// The floor is always offered: an instance that renders nothing else
		// still renders its source strings, and a picker with no options at
		// all is indistinguishable from a broken one.
		if (in_array(self::FALLBACK, $tags, true) === false) {
			$tags[] = self::FALLBACK;
		}

		sort($tags);

		return $tags;
	}//end available()

	/**
	 * Whether a tag may be written, and what to say when it may not.
	 *
	 * @param string $tag The BCP 47 tag.
	 *
	 * @return array{valid: bool, reason: string} The verdict.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002
	 */
	public function validate(string $tag): array {
		$tag = trim($tag);
		if ($tag === '') {
			// Unset is a legitimate value: it is what "no preference stated"
			// looks like, and clearing a preference must stay possible.
			return ['valid' => true, 'reason' => ''];
		}

		$available = $this->available();
		if (in_array($tag, $available, true) === true) {
			return ['valid' => true, 'reason' => ''];
		}

		return [
			'valid' => false,
			'reason' => "This instance cannot render {$tag}. Available: " . implode(', ', $available) . '.',
		];
	}//end validate()

	/**
	 * The instance default language, or '' when it has none.
	 *
	 * @return string The tag.
	 */
	public function instanceDefault(): string {
		return trim((string)$this->config->getSystemValue('default_language', ''));
	}//end instanceDefault()

	/**
	 * The language to write to a party in, and the rule that produced it.
	 *
	 * The three rules, in order: the party's own preference, the instance
	 * default, then the floor. The answer always says which of the three
	 * answered, so an unset preference is never dressed up as a chosen one.
	 *
	 * @param array<string, mixed> $party The stored party record.
	 *
	 * @return array{language: string, rule: string, partyPreference: string}
	 *   The answer.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-language-to-write-in-resolves-with-its-reason-req-pcl-003
	 */
	public function resolveFor(array $party): array {
		$preference = trim((string)($party['correspondenceLanguage'] ?? ''));

		if ($preference !== '') {
			return [
				'language' => $preference,
				'rule' => self::RULE_PARTY,
				'partyPreference' => $preference,
			];
		}

		$default = $this->instanceDefault();
		if ($default !== '') {
			return [
				'language' => $default,
				'rule' => self::RULE_INSTANCE_DEFAULT,
				'partyPreference' => '',
			];
		}

		return [
			'language' => self::FALLBACK,
			'rule' => self::RULE_FALLBACK,
			'partyPreference' => '',
		];
	}//end resolveFor()

	/**
	 * The same answer, for a party read by id.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return array<string, mixed> `status` plus the answer, or `error`.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-resolver-is-published-and-no-caller-reads-the-property-directly-req-pcl-005
	 */
	public function resolve(string $partyId): array {
		$party = $this->readParty(partyId: $partyId);
		if ($party === null) {
			return ['status' => 403, 'error' => 'You may not read this party.'];
		}

		return array_merge(['status' => 200], $this->resolveFor(party: $party));
	}//end resolve()

	/**
	 * Set or clear a party's preference.
	 *
	 * @param string $partyId The party record's uuid.
	 * @param string $tag The BCP 47 tag, or '' to clear it.
	 *
	 * @return array<string, mixed> `status` plus the answer, or `error`.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-the-selectable-languages-are-the-ones-the-instance-can-render-req-pcl-002
	 */
	public function setPreference(string $partyId, string $tag): array {
		$party = $this->readParty(partyId: $partyId);
		if ($party === null) {
			return ['status' => 403, 'error' => 'You may not read this party.'];
		}

		$verdict = $this->validate(tag: $tag);
		if ($verdict['valid'] === false) {
			return ['status' => 422, 'error' => $verdict['reason']];
		}

		$party['correspondenceLanguage'] = trim($tag);

		try {
			$this->objectService->saveObject(
				object: $party,
				register: $this->appConfig->getValueString(Application::APP_ID, 'register', ''),
				schema: $this->appConfig->getValueString(Application::APP_ID, 'client_schema', ''),
				uuid: (string)($party['id'] ?? $party['uuid'] ?? $partyId),
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'CorrespondenceLanguageService: the preference could not be saved',
				['party' => $partyId, 'exception' => $e->getMessage()]
			);

			return ['status' => 500, 'error' => 'The correspondence language could not be saved.'];
		}

		return array_merge(['status' => 200], $this->resolveFor(party: $party));
	}//end setPreference()

	/**
	 * What a merge of two parties should do about their languages.
	 *
	 * Two stated preferences stop the merge and ask. One stated preference
	 * survives. The asymmetry is the point: picking between two stated
	 * preferences by rule means writing to somebody in a language they asked
	 * not to be written in, and nobody would ever see that decision happen.
	 *
	 * @param array<string, mixed> $winner The surviving record.
	 * @param array<string, mixed> $loser The merged record.
	 *
	 * @return array{needsChoice: bool, language: string, options: array<int, string>}
	 *   What the merge should do.
	 *
	 * @spec openspec/changes/correspondence-language-per-party/specs/parties/spec.md#requirement-a-merge-does-not-pick-a-language-silently-req-pcl-006
	 */
	public function mergeAnswer(array $winner, array $loser): array {
		$a = trim((string)($winner['correspondenceLanguage'] ?? ''));
		$b = trim((string)($loser['correspondenceLanguage'] ?? ''));

		if ($a !== '' && $b !== '' && $a !== $b) {
			return ['needsChoice' => true, 'language' => '', 'options' => [$a, $b]];
		}

		$survivor = $b;
		if ($a !== '') {
			$survivor = $a;
		}

		return ['needsChoice' => false, 'language' => $survivor, 'options' => []];
	}//end mergeAnswer()

	/**
	 * Read one party, with RBAC on.
	 *
	 * @param string $partyId The party record's uuid.
	 *
	 * @return array<string, mixed>|null The party, or null.
	 */
	private function readParty(string $partyId): ?array {
		$partyId = trim($partyId);
		if ($partyId === '') {
			return null;
		}

		try {
			$entity = $this->objectService->find(id: $partyId);
		} catch (Throwable $e) {
			$this->logger->debug(
				'CorrespondenceLanguageService: the party could not be read',
				['party' => $partyId, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->jsonSerialize();

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end readParty()
}//end class
