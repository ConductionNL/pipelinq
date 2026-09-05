<?php

/**
 * Pipelinq KeywordTargetService.
 *
 * The only path that creates a `keywordTarget`. {@see KeywordAnalysisService}
 * derives proposals and writes nothing; a proposal becomes a record when a
 * person confirms it here.
 *
 * That separation is rule 4 of the marketing architecture applied to analysis
 * rather than to sending, and it has a plainer justification too: a striking-
 * distance list is recomputed on every read, so a service that created targets
 * from it would create and delete records under the marketer's hands, while a
 * keyword target is a commitment somebody is going to write a page against.
 *
 * `volume` and `difficulty` are never written. The source that would fill them
 * is a later bring-your-own-key source, and a zero in those fields reads as a
 * measurement of no demand rather than as an absence of one.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Search
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Search;

use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCP\AppFramework\Utility\ITimeFactory;
use UnexpectedValueException;

/**
 * Reads keyword targets, and creates one when a person confirms a proposal.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */
class KeywordTargetService {

	/**
	 * The schema slug the register fragment declares.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'keywordTarget';

	/**
	 * App-config key that may override the schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'keywordTarget_schema';

	/**
	 * What a target may say we decided.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['use-more', 'use-less', 'watch'];

	/**
	 * Which derivation a target may claim to come from.
	 *
	 * @var array<int, string>
	 */
	public const PROPOSAL_KINDS = ['striking-distance', 'cannibalisation', 'content-gap', 'manual'];

	/**
	 * What somebody typing the term is trying to do.
	 *
	 * @var array<int, string>
	 */
	public const INTENTS = ['informational', 'navigational', 'commercial', 'transactional'];

	/**
	 * Constructor.
	 *
	 * @param ListObjectStore $store Register-scoped object access.
	 * @param ITimeFactory $time Time factory for the confirmation stamp.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function __construct(
		private ListObjectStore $store,
		private ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Every keyword target.
	 *
	 * @return array<int, array<string, mixed>> The targets.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function all(): array {
		return $this->store->findAll(schemaSlug: $this->schemaSlug());
	}//end all()

	/**
	 * The terms already confirmed, normalised for comparison, so a page can
	 * mark a proposal as already taken.
	 *
	 * @return array<int, string> The lowercase terms.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function confirmedTerms(): array {
		$terms = [];
		foreach ($this->all() as $target) {
			$term = mb_strtolower(trim((string)($target['term'] ?? '')), 'UTF-8');
			if ($term !== '') {
				$terms[$term] = true;
			}
		}

		return array_keys($terms);
	}//end confirmedTerms()

	/**
	 * Confirm a proposal into a keyword target.
	 *
	 * @param array<string, mixed> $payload The confirmation: term, status, and optionally intent, targetPageRef, proposalKind, property and notes.
	 * @param string $uid The user confirming it.
	 *
	 * @return array<string, mixed>|null The stored target, or null when it could not be written.
	 *
	 * @throws UnexpectedValueException When the term is empty or a vocabulary value is unknown.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function confirm(array $payload, string $uid): ?array {
		$term = trim((string)($payload['term'] ?? ''));
		if ($term === '') {
			throw new UnexpectedValueException('A keyword target needs a term.');
		}

		$record = [
			'term' => $term,
			'status' => $this->oneOf(value: (string)($payload['status'] ?? 'watch'), allowed: self::STATUSES, field: 'status'),
			'proposalKind' => $this->oneOf(
				value: (string)($payload['proposalKind'] ?? 'manual'),
				allowed: self::PROPOSAL_KINDS,
				field: 'proposalKind'
			),
			'createdBy' => $uid,
			'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime()),
		];

		$intent = trim((string)($payload['intent'] ?? ''));
		if ($intent !== '') {
			$record['intent'] = $this->oneOf(value: $intent, allowed: self::INTENTS, field: 'intent');
		}

		foreach (['targetPageRef', 'property', 'notes'] as $optional) {
			$value = trim((string)($payload[$optional] ?? ''));
			if ($value !== '') {
				$record[$optional] = $value;
			}
		}

		return $this->store->save(schemaSlug: $this->schemaSlug(), payload: $record);
	}//end confirm()

	/**
	 * The schema slug in use.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
	 */
	public function schemaSlug(): string {
		return $this->store->schemaSlug(configKey: self::SCHEMA_CONFIG_KEY, default: self::SCHEMA_SLUG);
	}//end schemaSlug()

	/**
	 * Refuse a value the vocabulary does not admit, rather than storing it.
	 *
	 * @param string $value The value.
	 * @param array<int, string> $allowed The vocabulary.
	 * @param string $field The field name, for the message.
	 *
	 * @return string The value.
	 *
	 * @throws UnexpectedValueException When the value is unknown.
	 */
	private function oneOf(string $value, array $allowed, string $field): string {
		$trimmed = trim($value);
		if (in_array($trimmed, $allowed, true) === false) {
			throw new UnexpectedValueException(
				'The ' . $field . ' must be one of ' . implode(', ', $allowed) . '.'
			);
		}

		return $trimmed;
	}//end oneOf()
}//end class
