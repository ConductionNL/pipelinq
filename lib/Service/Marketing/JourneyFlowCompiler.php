<?php

/**
 * Pipelinq JourneyFlowCompiler.
 *
 * Turns a `journey` object into the flow document OpenRegister's engine
 * runs. Nothing here executes anything: it produces nodes and edges and
 * hands them to {@see JourneyService}, which saves them.
 *
 * 🔴 THE ENGINE IS OPENREGISTER'S, AND THERE IS ONLY ONE (ADR-094, ADR-065).
 * Pipelinq ships no scheduler, no tick job and no timer table for journeys.
 * The wait is `openregister.wait`, resumed by OpenRegister's own run worker;
 * the daily re-check of a bookkeeping signal is `openregister.trigger-schedule`.
 * A journey that could not be compiled simply does not run, and says so on
 * the object, which is the honest outcome. A background job that quietly did
 * the same work would be a second engine.
 *
 * 🔴 A CONDITION LIVES ON THE NODE'S `exits`, NOT ON THE EDGE. The edge only
 * names which exit it leaves by. Putting the condition on the edge produces
 * a flow that saves, validates and then takes every branch.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Marketing
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * JourneyFlowCompiler: one journey, one flow document.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyFlowCompiler {

	/**
	 * The register every journey trigger watches.
	 *
	 * @var string
	 */
	public const REGISTER = 'pipelinq';

	/**
	 * The pipelinq node that performs a journey's action.
	 *
	 * @var string
	 */
	public const ACTION_NODE = 'pipelinq.journey-action';

	/**
	 * Trigger kind to the schema whose change starts the journey.
	 *
	 * `shillinqSignal` is deliberately absent: no pipelinq object changes
	 * when an invoice is paid in another app, so that kind compiles to a
	 * schedule instead of an object trigger.
	 *
	 * @var array<string, string>
	 */
	public const TRIGGER_SCHEMAS = [
		'leadStageChanged' => 'lead',
		'contractRenewalWindow' => 'salesContract',
		'listConfirmed' => 'subscription',
	];

	/**
	 * The cron a bookkeeping-signal journey falls back to.
	 *
	 * @var string
	 */
	public const DEFAULT_CRON = '0 7 * * *';

	/**
	 * Build the flow document for one journey.
	 *
	 * @param array<string, mixed> $journey The journey payload.
	 * @param string $journeyId The journey's own id, which the action node needs.
	 * @param string $runAs The user a scheduled journey runs as.
	 *
	 * @return array<string, mixed> The document `FlowService::save()` accepts.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function documentFor(array $journey, string $journeyId, string $runAs = ''): array {
		$trigger = (array)($journey['trigger'] ?? []);
		$kind = (string)($trigger['kind'] ?? '');
		$schema = (self::TRIGGER_SCHEMAS[$kind] ?? '');

		$nodes = [$this->triggerNode(kind: $kind, schema: $schema, trigger: $trigger, runAs: $runAs)];
		$order = ['start'];

		$waitFor = trim((string)($journey['waitFor'] ?? ''));
		if ($waitFor !== '') {
			$nodes[] = ['id' => 'hold', 'type' => 'openregister.wait', 'config' => ['for' => $waitFor]];
			$order[] = 'hold';
		}

		$condition = $this->conditionFor(condition: (array)($journey['condition'] ?? []));
		if ($condition !== null) {
			$nodes[] = [
				'id' => 'gate',
				'type' => 'openregister.switch',
				'config' => [],
				'exits' => [
					['id' => 'match', 'condition' => $condition],
					['id' => 'skip'],
				],
			];
			$order[] = 'gate';
		}

		$nodes[] = [
			'id' => 'act',
			'type' => self::ACTION_NODE,
			'config' => ['journey' => $journeyId],
		];
		$order[] = 'act';

		$nodes[] = ['id' => 'end', 'type' => 'openregister.end', 'config' => []];
		$order[] = 'end';

		$scheduled = ($schema === '');
		$flowTrigger = 'object.updated';
		$triggerRegister = self::REGISTER;
		$cron = '';
		if ($scheduled === true) {
			$flowTrigger = 'schedule';
			$triggerRegister = '';
			$cron = $this->cronFor(trigger: $trigger);
		}

		return [
			'name' => $this->flowName(journey: $journey, journeyId: $journeyId),
			'description' => (string)($journey['description'] ?? ''),
			'app' => self::REGISTER,
			'applicationSlug' => self::REGISTER,
			'trigger' => $flowTrigger,
			'triggerRegister' => $triggerRegister,
			'triggerSchema' => $schema,
			'cron' => $cron,
			'executionMode' => 'async',
			'enabled' => (strtolower((string)($journey['status'] ?? 'draft')) === 'active'),
			'limits' => ['maxTransitions' => 200],
			'nodes' => $nodes,
			'edges' => $this->edgesFor(order: $order, hasCondition: ($condition !== null)),
		];
	}//end documentFor()

	/**
	 * The entry node for one trigger kind.
	 *
	 * @param string $kind The journey's trigger kind.
	 * @param string $schema The schema an object trigger watches, empty for a schedule.
	 * @param array<string, mixed> $trigger The trigger block.
	 * @param string $runAs The user a scheduled journey runs as.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function triggerNode(string $kind, string $schema, array $trigger, string $runAs): array {
		if ($schema === '') {
			// A schedule needs an explicit identity. The flow's owner is NOT
			// a fallback, and a schedule node without one never fires.
			return [
				'id' => 'start',
				'type' => 'openregister.trigger-schedule',
				'config' => ['cron' => $this->cronFor(trigger: $trigger), 'runAs' => $runAs],
			];
		}

		// A subscription becomes a member when it is confirmed, which is an
		// update of an existing row rather than a creation, so all three
		// object kinds watch the same event.
		unset($kind);

		return [
			'id' => 'start',
			'type' => 'openregister.trigger-object',
			'config' => ['event' => 'object.updated', 'register' => self::REGISTER, 'schema' => $schema],
		];
	}//end triggerNode()

	/**
	 * The JSONLogic condition for a journey's single check.
	 *
	 * @param array<string, mixed> $condition The journey's condition block.
	 *
	 * @return array<string, mixed>|null The expression, or null when there is no check.
	 */
	private function conditionFor(array $condition): ?array {
		$field = trim((string)($condition['field'] ?? ''));
		if ($field === '') {
			return null;
		}

		$path = ['var' => 'json.' . $field];
		$value = ($condition['value'] ?? '');
		$operator = (string)($condition['operator'] ?? 'equals');

		return match ($operator) {
			'notEquals' => ['!=' => [$path, $value]],
			'isNull' => ['!' => [$path]],
			'isNotNull' => ['!!' => [$path]],
			default => ['==' => [$path, $value]],
		};
	}//end conditionFor()

	/**
	 * The edges that chain the nodes in order.
	 *
	 * @param array<int, string> $order The node ids, in order.
	 * @param bool $hasCondition Whether a gate node is present.
	 *
	 * @return array<int, array<string, string>> The edges.
	 */
	private function edgesFor(array $order, bool $hasCondition): array {
		$edges = [];
		$count = count($order);
		for ($index = 0; $index < ($count - 1); $index++) {
			$from = $order[$index];
			$edge = ['id' => 'e' . ($index + 1), 'from' => $from, 'to' => $order[($index + 1)]];
			if ($from === 'gate' && $hasCondition === true) {
				$edge['fromExit'] = 'match';
			}

			$edges[] = $edge;
		}

		if ($hasCondition === true) {
			// The else branch. Without it an item the condition rejected has
			// nowhere to go, and the engine drops it silently.
			$edges[] = ['id' => 'e-skip', 'from' => 'gate', 'fromExit' => 'skip', 'to' => 'end'];
		}

		return $edges;
	}//end edgesFor()

	/**
	 * The cron a scheduled journey runs on.
	 *
	 * @param array<string, mixed> $trigger The trigger block.
	 *
	 * @return string A five-field cron expression.
	 */
	private function cronFor(array $trigger): string {
		$cron = trim((string)($trigger['cron'] ?? ''));
		if ($cron === '' || count(preg_split('/\s+/', $cron)) !== 5) {
			return self::DEFAULT_CRON;
		}

		return $cron;
	}//end cronFor()

	/**
	 * The flow's name, which is also half of its import identity.
	 *
	 * @param array<string, mixed> $journey The journey payload.
	 * @param string $journeyId The journey id.
	 *
	 * @return string The name.
	 */
	private function flowName(array $journey, string $journeyId): string {
		$name = trim((string)($journey['name'] ?? ''));
		if ($name === '') {
			return ('Journey ' . $journeyId);
		}

		return ('Journey: ' . $name);
	}//end flowName()
}//end class
