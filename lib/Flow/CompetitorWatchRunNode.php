<?php

/**
 * Pipelinq CompetitorWatchRunNode.
 *
 * `pipelinq.competitor-watch-run`: the node an OpenRegister flow schedule
 * fires to run the competitor watches that are due.
 *
 * WHY THIS NODE EXISTS AT ALL. ADR-094 routes new scheduled automation to
 * OpenRegister's flow engine rather than to n8n or to a background job of
 * ours, and decision 3 of that same ADR records the gap that makes a naive
 * reading impossible: the node registry has no outbound-HTTP node, so a flow
 * built out of stock nodes cannot fetch a feed. The resolution is humaniq's:
 * the engine owns the schedule, the app contributes the step. A schedule
 * trigger starts a run with NO subject, which is exactly why the first real
 * node has to fetch what it works on, and this is that node.
 *
 * IT DOES NOT SCOPE ITSELF. `RegistryStepDispatcher` executes every
 * contributed node inside `FlowRunAsScope`, so wrapping the work in an
 * identity here would double-scope it. That is the mistake dossiq shipped
 * three times before the dispatcher grew the wrapper, and the reason this
 * class deliberately does nothing about identity beyond passing the acting
 * user along to hermiq, which needs it for its own guard.
 *
 * @category Flow
 * @package  OCA\Pipelinq\Flow
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
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\Pipelinq\Service\Competitor\CompetitorWatchService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use UnexpectedValueException;

/**
 * Runs the due competitor watches when a flow schedule fires.
 *
 * @psalm-suppress MissingDependency IFlowNode is OpenRegister's, loaded at
 *     runtime and suppressed as an undefined class in psalm.xml, so psalm
 *     cannot verify the implements-relationship here.
 *
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
 */
class CompetitorWatchRunNode implements IFlowNode {

	/**
	 * The node type, as the shipped flow names it.
	 *
	 * @var string
	 */
	public const NODE_ID = 'pipelinq.competitor-watch-run';

	/**
	 * The key the outcome travels on.
	 *
	 * @var string
	 */
	public const OUTCOME_KEY = 'competitorWatch';

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Palette strings.
	 * @param IURLGenerator $urls Palette icon.
	 * @param CompetitorWatchService $watches The service that does the work.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urls,
		private readonly CompetitorWatchService $watches,
	) {
	}//end __construct()

	/**
	 * The node type.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Palette name.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Run due competitor watches');
	}//end getDisplayName()

	/**
	 * Palette description.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Read the competitor watches whose cadence has come round, and record what changed. Put it after a schedule trigger.'
		);
	}//end getDescription()

	/**
	 * Palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('pipelinq', 'app-dark.svg');
	}//end getIcon()

	/**
	 * Available in both scopes. Watching carries no privilege of its own: the
	 * acting identity is the run's, applied by the dispatcher.
	 *
	 * @param int $scope The Nextcloud workflow scope constant.
	 *
	 * @return bool Whether the node is offered in this scope.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function isAvailableForScope(int $scope): bool {
		return in_array($scope, [IManager::SCOPE_ADMIN, IManager::SCOPE_USER], true);
	}//end isAvailableForScope()

	/**
	 * The only configurable value is how many watches one firing may run.
	 *
	 * @param array $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the limit is not a positive number.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function validateConfig(array $config): void {
		if (array_key_exists('limit', $config) === false) {
			return;
		}

		$limit = $config['limit'];
		if (is_numeric($limit) === false || (int)$limit < 1) {
			throw new UnexpectedValueException(
				$this->l10n->t('The limit must be a whole number of one or more.')
			);
		}
	}//end validateConfig()

	/**
	 * Run the due watches once per firing.
	 *
	 * A schedule trigger hands the flow no items, so an empty input is the
	 * normal case here rather than "nothing to do": the node's whole job is
	 * to go and find what to work on. It answers with one item carrying the
	 * run summary, so a later node can branch on what was found.
	 *
	 * @param array $items The input items, empty on a schedule.
	 * @param array $config The step configuration.
	 * @param array $context Run-level metadata.
	 *
	 * @return array One item carrying the summary.
	 *
	 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-runs-on-an-openregister-flow-schedule-and-never-on-a-job-of-ours
	 */
	public function execute(array $items, array $config, array $context): array {
		$this->validateConfig(config: $config);
		$limit = CompetitorWatchService::DEFAULT_LIMIT;
		if (array_key_exists('limit', $config) === true) {
			$limit = (int)$config['limit'];
		}

		$summary = $this->watches->runDue(limit: $limit, actingUserId: $this->actingUser(context: $context));
		if ($items === []) {
			return [['json' => [self::OUTCOME_KEY => $summary]]];
		}

		$out = [];
		foreach ($items as $item) {
			$row = (array)$item;
			$json = (array)($row['json'] ?? []);
			$json[self::OUTCOME_KEY] = $summary;
			$row['json'] = $json;
			$out[] = $row;
		}

		return $out;
	}//end execute()

	/**
	 * The identity the run acts as, only so hermiq's own egress guard can be
	 * given a caller. The node does NOT narrow to it: the dispatcher already
	 * executes every contributed node inside the run's identity, and doing it
	 * again here would double-scope the work.
	 *
	 * @param array $context Run-level metadata.
	 *
	 * @return string|null The user id, or null when the run names none.
	 */
	private function actingUser(array $context): ?string {
		foreach (['runAs', 'actingUserId', 'userId'] as $key) {
			$value = trim((string)($context[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return null;
	}//end actingUser()
}//end class
