<?php

/**
 * Pipelinq JourneyActionNode.
 *
 * The `pipelinq.journey-action` node type. It is how a journey's action runs
 * inside OpenRegister's flow engine without pipelinq owning a scheduler, a
 * queue or a tick job.
 *
 * 🔴 THROWING IS MEANINGFUL, AND CATCHING EVERYTHING IS THE BUG. The engine
 * reads the step's `onError` policy from what this method throws. A node that
 * swallowed a Throwable and returned an empty list would produce a green run
 * that did nothing, which is the failure a journey cannot afford: nothing
 * happening looks exactly like nothing needing to happen. A REFUSAL is not a
 * failure and does not throw: it is a correct outcome, recorded on a
 * `journeyRun` with the contact in it.
 *
 * 🔴 `validateConfig()` ONLY RUNS ON SAVE. A flow imported or seeded through
 * another path reaches `execute()` unvalidated, so the journey id is checked
 * again there rather than assumed.
 *
 * @category Flow
 * @package  OCA\Pipelinq\Flow
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

namespace OCA\Pipelinq\Flow;

use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\Pipelinq\Service\Marketing\JourneyStepRunner;
use OCP\IURLGenerator;
use UnexpectedValueException;

/**
 * JourneyActionNode: run one journey's action for the items the flow carries.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
class JourneyActionNode implements IFlowNode, IFlowNodeConfigKeys {

	/**
	 * The app-namespaced type identifier.
	 *
	 * @var string
	 */
	public const NODE_ID = 'pipelinq.journey-action';

	/**
	 * Constructor.
	 *
	 * @param JourneyStepRunner $runner The gate and the action.
	 * @param IURLGenerator $urls URL generator, for the palette icon.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function __construct(
		private JourneyStepRunner $runner,
		private IURLGenerator $urls,
	) {
	}//end __construct()

	/**
	 * The app-namespaced type identifier.
	 *
	 * @return string The type identifier.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function getId(): string {
		return self::NODE_ID;
	}//end getId()

	/**
	 * Name shown in the flow builder's palette.
	 *
	 * @return string The display name.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function getDisplayName(): string {
		return 'Journey action';
	}//end getDisplayName()

	/**
	 * One sentence describing what the node does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function getDescription(): string {
		return 'Send the journey\'s mailing or create its task, after the consent gate.';
	}//end getDescription()

	/**
	 * Absolute URL of the palette icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function getIcon(): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath('pipelinq', 'app.svg'));
	}//end getIcon()

	/**
	 * A journey acts on the tenant's own contacts, so both scopes apply.
	 *
	 * @param int $scope The scope constant.
	 *
	 * @return bool Whether it is available.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function isAvailableForScope(int $scope): bool {
		unset($scope);
		return true;
	}//end isAvailableForScope()

	/**
	 * The configuration keys this node reads.
	 *
	 * @return array<int, string> The accepted keys.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function configKeys(): array {
		return ['journey'];
	}//end configKeys()

	/**
	 * A journey id is the whole configuration, and it is required.
	 *
	 * @param array<string, mixed> $config The step's authored configuration.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When no journey is named.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
	 */
	public function validateConfig(array $config): void {
		if (trim((string)($config['journey'] ?? '')) === '') {
			throw new UnexpectedValueException('A journey action needs a journey.');
		}
	}//end validateConfig()

	/**
	 * Run the journey's action for every item the flow delivered.
	 *
	 * The items are passed through unchanged. A journey step decides what
	 * happens to people, not what happens to the flow's data, and rewriting
	 * the items would make a later node read something a marketer never
	 * authored.
	 *
	 * @param array<int, array<string, mixed>> $items The input items.
	 * @param array<string, mixed> $config The step's authored configuration.
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return array<int, array<string, mixed>> The items, unchanged.
	 *
	 * @throws UnexpectedValueException When the step was never validated.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
	 */
	public function execute(array $items, array $config, array $context): array {
		// Re-checked here on purpose: validateConfig() only runs when a flow
		// is SAVED, and a seeded or imported flow reaches this method having
		// never passed through that path.
		$this->validateConfig(config: $config);

		$journeyId = trim((string)($config['journey'] ?? ''));
		$flowRunUuid = (string)($context['runUuid'] ?? ($context['uuid'] ?? ''));

		if ($items === []) {
			// A scheduled journey fires with nothing attached. Its audience
			// segment is the audience, and the runner resolves it.
			$this->runner->run(journeyId: $journeyId, subject: [], flowRunUuid: $flowRunUuid);
			return $items;
		}

		foreach ($items as $item) {
			$subject = ($item['json'] ?? []);
			if (is_array($subject) === false) {
				$subject = [];
			}

			$this->runner->run(journeyId: $journeyId, subject: $subject, flowRunUuid: $flowRunUuid);
		}

		return $items;
	}//end execute()
}//end class
