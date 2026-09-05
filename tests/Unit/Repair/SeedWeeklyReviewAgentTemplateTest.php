<?php

/**
 * Unit tests for SeedWeeklyReviewAgentTemplate.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\Pipelinq\Repair\SeedWeeklyReviewAgentTemplate;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for the agent template seed: what it grants, when it is a no-op,
 * and that running it twice writes once.
 *
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-ships-as-an-agent-template-with-no-send-tool
 */
class SeedWeeklyReviewAgentTemplateTest extends TestCase {

	/**
	 * The in-memory object service the step writes through.
	 *
	 * @var object
	 */
	private object $objectService;

	/**
	 * Set up an object service that stores whatever it is handed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objectService = new class {
			/**
			 * Everything written, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $written = [];

			/**
			 * Rows a read can find.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * Store one object.
			 *
			 * @param array<string, mixed> $object The payload.
			 * @param array<int, string> $extend Ignored.
			 * @param string|null $register The register.
			 * @param string|null $schema The schema.
			 * @param string|null $uuid The uuid.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<string, mixed> The payload.
			 */
			public function saveObject(
				array $object,
				array $extend = [],
				?string $register = null,
				?string $schema = null,
				?string $uuid = null,
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->written[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
				$this->rows[] = $object;
				return $object;
			}//end saveObject()

			/**
			 * Every stored row.
			 *
			 * @param array<string, mixed> $config Ignored.
			 * @param bool $_rbac Ignored.
			 * @param bool $_multitenancy Ignored.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				return $this->rows;
			}//end findAll()
		};
	}//end setUp()

	/**
	 * The grant is read-only. An agent that could send would be an agent
	 * that publishes without a human decision (ADR-088).
	 *
	 * @return void
	 */
	public function testGrantsOnlyReadOnlyTools(): void {
		$this->step(installed: ['openregister', 'hermiq'])->run($this->createMock(IOutput::class));

		$template = $this->objectService->written[0]['object'];

		$this->assertSame(SeedWeeklyReviewAgentTemplate::TOOLS, $template['tools']);
		foreach ($template['tools'] as $tool) {
			$this->assertStringNotContainsString('send', $tool);
			$this->assertStringNotContainsString('publish', $tool);
			$this->assertStringNotContainsString(':write', $tool);
		}
	}//end testGrantsOnlyReadOnlyTools()

	/**
	 * It writes into hermiq's register and its agent-template schema, and
	 * suggests a Monday-morning schedule delivered to Talk.
	 *
	 * @return void
	 */
	public function testSeedsIntoHermiqsAgentTemplateSchema(): void {
		$this->step(installed: ['openregister', 'hermiq'])->run($this->createMock(IOutput::class));

		$written = $this->objectService->written[0];

		$this->assertSame(SeedWeeklyReviewAgentTemplate::HERMIQ_REGISTER, $written['register']);
		$this->assertSame(SeedWeeklyReviewAgentTemplate::TEMPLATE_SCHEMA, $written['schema']);
		$this->assertSame('cron', $written['object']['suggestedSchedule']['kind']);
		$this->assertSame('talk', $written['object']['suggestedSchedule']['deliver']);
	}//end testSeedsIntoHermiqsAgentTemplateSchema()

	/**
	 * Without hermiq the step writes nothing and does not raise. Hermiq is
	 * an optional peer, and a missing template is not an install failure.
	 *
	 * @return void
	 */
	public function testIsANoOpWithoutHermiq(): void {
		$this->step(installed: ['openregister'])->run($this->createMock(IOutput::class));

		$this->assertSame([], $this->objectService->written);
	}//end testIsANoOpWithoutHermiq()

	/**
	 * A second run finds the template already there and writes nothing,
	 * so an upgrade never leaves two of them behind.
	 *
	 * @return void
	 */
	public function testIsIdempotentOnASecondRun(): void {
		$step = $this->step(installed: ['openregister', 'hermiq']);
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertCount(1, $this->objectService->written);
	}//end testIsIdempotentOnASecondRun()

	/**
	 * The step under test, with the given apps reported as installed.
	 *
	 * @param array<int, string> $installed The installed app ids.
	 *
	 * @return SeedWeeklyReviewAgentTemplate The step.
	 */
	private function step(array $installed): SeedWeeklyReviewAgentTemplate {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn($installed);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $this->objectService;
				}

				throw new RuntimeException('not registered: ' . $id);
			}
		);

		return new SeedWeeklyReviewAgentTemplate(
			$appManager,
			$container,
			$this->createMock(LoggerInterface::class),
		);
	}//end step()
}//end class
