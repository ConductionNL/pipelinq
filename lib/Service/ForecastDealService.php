<?php

/**
 * Pipelinq ForecastDealService.
 *
 * Server-authoritative forecast-category lifecycle rules for deal (lead) objects:
 * default assignment, closed-deal lock, reopen reset and large-commit justification.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\Lifecycle\SchemaLifecycleGraph;
use OCP\IAppConfig;

/**
 * Pure forecast-category lifecycle decision engine for deal (lead) objects.
 *
 * Holds no OpenRegister wiring so the rules are unit-testable in isolation. The
 * listeners ({@see \OCA\Pipelinq\Listener\DealCreatedListener},
 * {@see \OCA\Pipelinq\Listener\DealUpdatedListener}) call into this service and
 * apply its decisions to the persisted object.
 *
 * Lifecycle source of truth (ADR-031): the open/closed forecast-category
 * partition and the create-default live in the lead schema's
 * `configuration.x-pipelinq-forecast-lifecycle` annotation. They are read here
 * via {@see SchemaLifecycleGraph::configurationFor()}, with the constants below
 * as a never-regress fallback. This is a SECOND state machine on the lead schema;
 * OpenRegister's `x-openregister-lifecycle` already owns the `status` field and
 * supports only one enforced lifecycle field per schema, so this one is enforced
 * in PHP (the deal listeners' revert-after-write), NOT by OR's listener.
 */
class ForecastDealService {
	/**
	 * Schema slug whose `x-pipelinq-forecast-lifecycle` declares the partition.
	 *
	 * @var string
	 */
	private const LEAD_SCHEMA_SLUG = 'lead';

	/**
	 * Configuration key holding the forecast-category lifecycle annotation.
	 *
	 * @var string
	 */
	private const FORECAST_LIFECYCLE_KEY = 'x-pipelinq-forecast-lifecycle';

	/**
	 * Fallback closed (locked) forecast categories. The canonical source of truth
	 * is the lead schema's `x-pipelinq-forecast-lifecycle.closed`; this mirrors it.
	 *
	 * @var array<int, string>
	 */
	public const CLOSED_CATEGORIES = ['closed_won', 'closed_lost'];

	/**
	 * Fallback default forecast category assigned to a new deal. The canonical
	 * source of truth is `x-pipelinq-forecast-lifecycle.default`.
	 *
	 * @var string
	 */
	public const DEFAULT_CATEGORY = 'pipeline';

	/**
	 * Fallback open forecast categories a rep may freely choose. The canonical
	 * source of truth is `x-pipelinq-forecast-lifecycle.open`.
	 *
	 * @var array<int, string>
	 */
	public const OPEN_CATEGORIES = ['commit', 'best_case', 'pipeline', 'omitted'];

	/**
	 * App-config key for the commit justification threshold (in reporting currency).
	 *
	 * @var string
	 */
	public const COMMIT_THRESHOLD_KEY = 'forecast_commit_threshold';

	/**
	 * Default commit justification threshold when none is configured.
	 *
	 * @var int
	 */
	public const COMMIT_THRESHOLD_DEFAULT = 50000;

	/**
	 * Minimum number of characters required in a commit justification.
	 *
	 * @var int
	 */
	public const JUSTIFICATION_MIN_LENGTH = 10;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app configuration.
	 * @param SchemaLifecycleGraph $lifecycleGraph Reads the forecast-category partition from the lead schema.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private SchemaLifecycleGraph $lifecycleGraph = new SchemaLifecycleGraph(),
	) {
	}//end __construct()

	/**
	 * Resolve the forecast-category lifecycle annotation from the lead schema.
	 *
	 * @return array<string, mixed> The annotation, or an empty array when unreadable.
	 */
	private function forecastLifecycle(): array {
		$annotation = $this->lifecycleGraph->configurationFor(
			schemaSlug: self::LEAD_SCHEMA_SLUG,
			key: self::FORECAST_LIFECYCLE_KEY
		);

		if (is_array($annotation) === true) {
			return $annotation;
		}

		return [];
	}//end forecastLifecycle()

	/**
	 * The closed (locked) forecast categories, sourced from the schema.
	 *
	 * @return array<int, string> The closed categories (falls back to the constant).
	 */
	private function closedCategories(): array {
		$closed = ($this->forecastLifecycle()['closed'] ?? null);
		if (is_array($closed) === false || $closed === []) {
			return self::CLOSED_CATEGORIES;
		}

		return array_map(static fn ($value): string => (string)$value, $closed);
	}//end closedCategories()

	/**
	 * The open forecast categories, sourced from the schema.
	 *
	 * @return array<int, string> The open categories (falls back to the constant).
	 */
	private function openCategories(): array {
		$open = ($this->forecastLifecycle()['open'] ?? null);
		if (is_array($open) === false || $open === []) {
			return self::OPEN_CATEGORIES;
		}

		return array_map(static fn ($value): string => (string)$value, $open);
	}//end openCategories()

	/**
	 * The default forecast category, sourced from the schema.
	 *
	 * @return string The default category (falls back to the constant).
	 */
	private function defaultCategory(): string {
		$default = ($this->forecastLifecycle()['default'] ?? null);
		if (is_string($default) === true && $default !== '') {
			return $default;
		}

		return self::DEFAULT_CATEGORY;
	}//end defaultCategory()

	/**
	 * Whether a forecast category is a closed (locked) category.
	 *
	 * @param string|null $category The category to test.
	 *
	 * @return bool True when the category is closed_won or closed_lost.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-002
	 */
	public function isClosedCategory(?string $category): bool {
		return $category !== null && in_array($category, $this->closedCategories(), true);
	}//end isClosedCategory()

	/**
	 * Compute the default-assigned data for a freshly created deal.
	 *
	 * Idempotent: returns null when no change is required (category already set),
	 * otherwise the deal data with forecast_category defaulted to pipeline.
	 *
	 * @param array<string, mixed> $data The created deal data.
	 *
	 * @return array<string, mixed>|null The mutated data to persist, or null when unchanged.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-001-01
	 */
	public function applyDefaultCategory(array $data): ?array {
		$current = $data['forecast_category'] ?? null;
		if (is_string($current) === true && $current !== '') {
			return null;
		}

		$data['forecast_category'] = $this->defaultCategory();
		return $data;
	}//end applyDefaultCategory()

	/**
	 * Validate a forecast-category transition on an updated deal.
	 *
	 * Returns an error key when the transition is rejected, or null when allowed.
	 * Rules:
	 *  - A closed deal cannot move to an open category (must be reopened first).
	 *  - A commit on a deal above the threshold requires a justification of at
	 *    least {@see self::JUSTIFICATION_MIN_LENGTH} characters.
	 *
	 * @param array<string, mixed> $oldData The deal data before the update.
	 * @param array<string, mixed> $newData The deal data after the update.
	 *
	 * @return string|null The error key (translatable) or null when the change is valid.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-002-01, REQ-FRC-003-01
	 */
	public function validateTransition(array $oldData, array $newData): ?string {
		$oldCategory = $oldData['forecast_category'] ?? null;
		$newCategory = $newData['forecast_category'] ?? null;

		if (is_string($oldCategory) === true
			&& is_string($newCategory) === true
			&& $oldCategory !== $newCategory
			&& $this->isClosedCategory(category: $oldCategory) === true
			&& in_array($newCategory, $this->openCategories(), true) === true
		) {
			return 'forecast.error.closed_deal_locked';
		}

		if ($newCategory === 'commit' && $this->requiresJustification(data: $newData) === true) {
			$justification = trim((string)($newData['commit_justification'] ?? ''));
			if (mb_strlen($justification) < self::JUSTIFICATION_MIN_LENGTH) {
				return 'forecast.error.justification_required';
			}
		}

		return null;
	}//end validateTransition()

	/**
	 * Whether a commit on this deal requires a justification.
	 *
	 * True when the deal value exceeds the org-configured commit threshold.
	 *
	 * @param array<string, mixed> $data The deal data.
	 *
	 * @return bool True when a justification is required.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-003-04
	 */
	public function requiresJustification(array $data): bool {
		$value = (float)($data['value'] ?? 0);
		return $value > (float)$this->getCommitThreshold();
	}//end requiresJustification()

	/**
	 * Resolve the org-configured commit justification threshold.
	 *
	 * @return int The threshold in the reporting currency.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-003-05
	 */
	public function getCommitThreshold(): int {
		$configured = $this->appConfig->getValueInt(
			Application::APP_ID,
			self::COMMIT_THRESHOLD_KEY,
			self::COMMIT_THRESHOLD_DEFAULT
		);

		if ($configured <= 0) {
			return self::COMMIT_THRESHOLD_DEFAULT;
		}

		return $configured;
	}//end getCommitThreshold()

	/**
	 * Compute the reset data when a closed deal is reopened.
	 *
	 * When the deal's status moves away from a closed value while the forecast
	 * category is still closed, the category resets to pipeline.
	 *
	 * @param array<string, mixed> $oldData The deal data before the update.
	 * @param array<string, mixed> $newData The deal data after the update.
	 *
	 * @return array<string, mixed>|null The mutated data to persist, or null when no reset applies.
	 *
	 * @spec openspec/changes/forecast-roll-up-and-categories/specs.md#REQ-FRC-002-03
	 */
	public function applyReopenReset(array $oldData, array $newData): ?array {
		$newCategory = $newData['forecast_category'] ?? null;
		if ($this->isClosedCategory(category: $newCategory) === false) {
			return null;
		}

		$oldStatus = $oldData['status'] ?? null;
		$newStatus = $newData['status'] ?? null;
		$closedStatuses = ['won', 'lost'];

		$wasClosed = in_array($oldStatus, $closedStatuses, true);
		$nowOpen = $newStatus !== null && in_array($newStatus, $closedStatuses, true) === false;

		if ($wasClosed === true && $nowOpen === true) {
			$newData['forecast_category'] = $this->defaultCategory();
			return $newData;
		}

		return null;
	}//end applyReopenReset()
}//end class
