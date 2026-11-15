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
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Marketing;

/**
 * JourneyFlowCompiler: one journey, one flow document.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
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
		'addressChanged' => 'client',
		'ticketClosed' => 'ticket',
	];

	/**
	 * Trigger kind to the check every run of that kind must pass first.
	 *
	 * An object trigger fires on every save of the object. These kinds start
	 * on one change only, read from the fields the update changed
	 * (`context.changed`, from OpenRegister's update trigger). A journey that
	 * only wants complaints adds its own condition on the ticket type.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	public const TRIGGER_GUARDS = [
		'addressChanged' => ['in' => ['address', ['var' => 'context.changed']]],
		'ticketClosed' => [
			'and' => [
				['in' => [['var' => 'json.status'], ['resolved', 'completed', 'closed']]],
				['in' => ['status', ['var' => 'context.changed']]],
			],
		],
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
	 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function documentFor(array $journey, string $journeyId, string $runAs = ''): array {
		$trigger = (array)($journey['trigger'] ?? []);
		$kind = (string)($trigger['kind'] ?? '');
		$schema = (self::TRIGGER_SCHEMAS[$kind] ?? '');
		$condition = $this->gateCondition(kind: $kind, journey: $journey);

		$nodes = [$this->objectNode(schema: $schema)];
		if ($schema === '') {
			$nodes = [$this->scheduleNode(trigger: $trigger, runAs: $runAs)];
		}

		array_push($nodes, ...$this->middleNodes(journey: $journey, condition: $condition), ...$this->closingNodes(journeyId: $journeyId));

		return $this->flowHeader(journey: $journey, journeyId: $journeyId)
			+ $this->triggerFields(schema: $schema, trigger: $trigger)
			+ $this->runFields(journey: $journey, nodes: $nodes)
			+ ['edges' => $this->edgesFor(order: array_column($nodes, 'id'), hasCondition: ($condition !== null))];
	}//end documentFor()

	/**
	 * The optional wait node and the optional gate node, in that order.
	 *
	 * @param array<string, mixed>      $journey   The journey payload.
	 * @param array<string, mixed>|null $condition The gate condition, or null for no gate.
	 *
	 * @return array<int, array<string, mixed>> The nodes.
	 */
	private function middleNodes(array $journey, ?array $condition): array {
		$nodes = [];
		$waitFor = trim((string)($journey['waitFor'] ?? ''));
		if ($waitFor !== '') {
			$nodes[] = ['id' => 'hold', 'type' => 'openregister.wait', 'config' => ['for' => $waitFor]];
		}

		if ($condition !== null) {
			$nodes[] = $this->gateNode(condition: $condition);
		}

		return $nodes;
	}//end middleNodes()

	/**
	 * The switch node that lets a matching item through and sends the rest to the end.
	 *
	 * @param array<string, mixed> $condition The gate condition.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function gateNode(array $condition): array {
		return [
			'id' => 'gate',
			'type' => 'openregister.switch',
			'config' => [],
			'exits' => [
				['id' => 'match', 'condition' => $condition],
				['id' => 'skip'],
			],
		];
	}//end gateNode()

	/**
	 * The action node that performs the journey, and the end node.
	 *
	 * @param string $journeyId The journey's own id, which the action node needs.
	 *
	 * @return array<int, array<string, mixed>> The two nodes.
	 */
	private function closingNodes(string $journeyId): array {
		return [
			['id' => 'act', 'type' => self::ACTION_NODE, 'config' => ['journey' => $journeyId]],
			['id' => 'end', 'type' => 'openregister.end', 'config' => []],
		];
	}//end closingNodes()

	/**
	 * The gate condition: the trigger kind's guard and the journey's own check, either alone or both.
	 *
	 * @param string               $kind    The journey's trigger kind.
	 * @param array<string, mixed> $journey The journey payload.
	 *
	 * @return array<string, mixed>|null The condition, or null when there is nothing to check.
	 */
	private function gateCondition(string $kind, array $journey): ?array {
		$condition = $this->conditionFor(condition: (array)($journey['condition'] ?? []));
		$guard = (self::TRIGGER_GUARDS[$kind] ?? null);
		if ($guard === null) {
			return $condition;
		}

		if ($condition === null) {
			return $guard;
		}

		return ['and' => [$guard, $condition]];
	}//end gateCondition()

	/**
	 * The flow's identity fields.
	 *
	 * @param array<string, mixed> $journey   The journey payload.
	 * @param string               $journeyId The journey id.
	 *
	 * @return array<string, string> The name, description, app and application slug.
	 */
	private function flowHeader(array $journey, string $journeyId): array {
		return [
			'name' => $this->flowName(journey: $journey, journeyId: $journeyId),
			'description' => (string)($journey['description'] ?? ''),
			'app' => self::REGISTER,
			'applicationSlug' => self::REGISTER,
		];
	}//end flowHeader()

	/**
	 * The flow's trigger fields: an object update in the register, or a schedule when no schema is watched.
	 *
	 * @param string               $schema  The schema an object trigger watches, empty for a schedule.
	 * @param array<string, mixed> $trigger The trigger block.
	 *
	 * @return array<string, string> The trigger, trigger register, trigger schema and cron.
	 */
	private function triggerFields(string $schema, array $trigger): array {
		if ($schema === '') {
			return ['trigger' => 'schedule', 'triggerRegister' => '', 'triggerSchema' => $schema, 'cron' => $this->cronFor(trigger: $trigger)];
		}

		return ['trigger' => 'object.updated', 'triggerRegister' => self::REGISTER, 'triggerSchema' => $schema, 'cron' => ''];
	}//end triggerFields()

	/**
	 * The flow's execution fields and its nodes.
	 *
	 * @param array<string, mixed>             $journey The journey payload.
	 * @param array<int, array<string, mixed>> $nodes   The nodes, in order.
	 *
	 * @return array<string, mixed> The execution mode, enabled flag, limits and nodes.
	 */
	private function runFields(array $journey, array $nodes): array {
		return [
			'executionMode' => 'async',
			'enabled' => (strtolower((string)($journey['status'] ?? 'draft')) === 'active'),
			'limits' => ['maxTransitions' => 200],
			'nodes' => $nodes,
		];
	}//end runFields()

	/**
	 * The entry node of a scheduled journey.
	 *
	 * A schedule needs an explicit identity. The flow's owner is NOT
	 * a fallback, and a schedule node without one never fires.
	 *
	 * @param array<string, mixed> $trigger The trigger block.
	 * @param string               $runAs   The user a scheduled journey runs as.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function scheduleNode(array $trigger, string $runAs): array {
		return [
			'id' => 'start',
			'type' => 'openregister.trigger-schedule',
			'config' => ['cron' => $this->cronFor(trigger: $trigger), 'runAs' => $runAs],
		];
	}//end scheduleNode()

	/**
	 * The entry node of an object-triggered journey.
	 *
	 * A subscription becomes a member when it is confirmed, which is an
	 * update of an existing row rather than a creation, so all three
	 * object kinds watch the same event.
	 *
	 * @param string $schema The schema the trigger watches.
	 *
	 * @return array<string, mixed> The node.
	 */
	private function objectNode(string $schema): array {
		return [
			'id' => 'start',
			'type' => 'openregister.trigger-object',
			'config' => ['event' => 'object.updated', 'register' => self::REGISTER, 'schema' => $schema],
		];
	}//end objectNode()

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
