<?php

/**
 * Pipelinq SeedWeeklyReviewAgentTemplate.
 *
 * Idempotent repair step that seeds the weekly marketing review as an
 * `agenttemplate` object in hermiq's register. A template is the portable,
 * secret-free half of an agent: a prompt, a tool grant and a suggested
 * schedule. Turning it into a running agent is a person's decision.
 *
 * 🔴 THE TEMPLATE GRANTS NO SEND AND NO PUBLISH TOOL, AND THAT IS THE WHOLE
 * POINT (ADR-088, marketing rule 4). An agent drafts and analyses; a person
 * sends. An empty `tools` array grants nothing at all, so the grant is written
 * out explicitly and is read-only.
 *
 * 🔴 IT IS A REPAIR STEP AND NOT A REGISTER FRAGMENT ON PURPOSE. Seeding into
 * a foreign register through `components.objects[]` needs that register to
 * exist at import time, and hermiq is an optional peer. A missing register
 * would take pipelinq's OWN register import down with it, which is a much
 * worse failure than a template nobody got.
 *
 * @category Repair
 * @package  OCA\Pipelinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Repair;

use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Seeds the weekly marketing review agent template into hermiq.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the small NC + OR
 *  collaborator set a data-seed step needs.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool
 */
class SeedWeeklyReviewAgentTemplate implements IRepairStep {

	/**
	 * Hermiq's register slug.
	 *
	 * @var string
	 */
	public const HERMIQ_REGISTER = 'hermiq';

	/**
	 * Hermiq's agent-template schema slug.
	 *
	 * @var string
	 */
	public const TEMPLATE_SCHEMA = 'agenttemplate';

	/**
	 * The template's name, which is also its idempotency key.
	 *
	 * @var string
	 */
	public const TEMPLATE_NAME = 'Weekly marketing review';

	/**
	 * Read-only tool grants. No send tool, no publish tool, by design.
	 *
	 * @var array<int, string>
	 */
	public const TOOLS = ['openregister.searchObjects'];

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager The app manager.
	 * @param ContainerInterface $container DI container, for OpenRegister's object service.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool
	 */
	public function getName(): string {
		return 'Seed the weekly marketing review agent template into hermiq (idempotent)';
	}//end getName()

	/**
	 * Seed the template, unless hermiq is absent or it is already there.
	 *
	 * @param IOutput $output The migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool
	 */
	public function run(IOutput $output): void {
		$installed = $this->appManager->getInstalledApps();
		if (in_array('openregister', $installed, true) === false || in_array('hermiq', $installed, true) === false) {
			$output->info('Hermiq or OpenRegister is not installed, so the weekly review agent template was not seeded.');
			return;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
		} catch (Throwable $e) {
			$output->warning('OpenRegister ObjectService unavailable, so the weekly review agent template was not seeded.');
			return;
		}

		try {
			if ($this->exists(objectService: $objectService) === true) {
				$output->info('The weekly review agent template is already present.');
				return;
			}

			// A repair step runs from `occ` with no session, so RBAC resolves
			// the actor to Anonymous and refuses the write. Both flags off is
			// how every other seed in this app writes system data.
			$objectService->saveObject(
				object: $this->template(),
				extend: [],
				register: self::HERMIQ_REGISTER,
				schema: self::TEMPLATE_SCHEMA,
				uuid: null,
				_rbac: false,
				_multitenancy: false
			);
			$output->info('Seeded the weekly marketing review agent template.');
		} catch (Throwable $e) {
			$output->warning('Could not seed the weekly review agent template: ' . $e->getMessage());
			$this->logger->warning(
				'Pipelinq: seeding the weekly review agent template failed',
				['exception' => $e->getMessage()]
			);
		}//end try
	}//end run()

	/**
	 * The template as hermiq's `agenttemplate` schema records it.
	 *
	 * @return array<string, mixed> The template payload.
	 */
	private function template(): array {
		return [
			'name' => self::TEMPLATE_NAME,
			'description' => 'Reads last week\'s campaigns, social posts and search queries and writes one page about them.',
			'category' => 'reporting',
			'systemPrompt' => implode("\n", [
				'You write one page of marketing review for a Dutch SMB or public-sector team.',
				'Read the weeklyReview object pipelinq composed for the week, plus the campaign, socialPublication and searchQueryDaily objects behind it.',
				'Write three things: what moved, what to try next week, and three topic ideas.',
				'Name every number you use and say where it came from.',
				'A source listed under degraded was not readable. Say so. Never report it as zero.',
				'You do not send and you do not publish. Recommend, and let a person decide.',
				'Write short sentences. No em-dashes. Sentence case in headings.',
			]),
			'suggestedProvider' => 'openai',
			'suggestedModel' => 'gpt-4o-mini',
			'tools' => self::TOOLS,
			'suggestedSchedule' => [
				'kind' => 'cron',
				'cronExpr' => '0 9 * * 1',
				'deliver' => 'talk',
			],
			'state' => 'active',
			'source' => 'local',
			'version' => '1.0.0',
		];
	}//end template()

	/**
	 * Whether a template of this name is already in hermiq's register.
	 *
	 * @param mixed $objectService OpenRegister's object service.
	 *
	 * @return bool True when it is present.
	 */
	private function exists(mixed $objectService): bool {
		$rows = $objectService->findAll(
			config: [
				'filters' => [
					'register' => self::HERMIQ_REGISTER,
					'schema' => self::TEMPLATE_SCHEMA,
					'name' => self::TEMPLATE_NAME,
				],
				'limit' => 1,
			],
			_rbac: false,
			_multitenancy: false
		);

		if (is_iterable($rows) === false) {
			return false;
		}

		foreach ($rows as $row) {
			unset($row);
			return true;
		}

		return false;
	}//end exists()
}//end class
