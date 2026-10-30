<?php

/**
 * Unit tests for DealCreatedListener's deferred work: the forecast default
 * and lead rotation.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\Pipelinq\Listener\DealCreatedListener;
use OCA\Pipelinq\Service\ForecastDealService;
use OCA\Pipelinq\Service\LeadRotationService;
use OCA\Pipelinq\Service\SchemaMapService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rotation runs only when switched on and only for a lead without assignee.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 */
class DealCreatedListenerTest extends TestCase {
	/**
	 * What the fake object service saved, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the listener over a stored lead.
	 *
	 * @param array<string, mixed> $lead The lead as stored.
	 * @param bool $enabled Whether rotation is switched on.
	 * @param array<string, mixed>|null $rotated What rotation answers.
	 *
	 * @return DealCreatedListener The listener.
	 */
	private function listener(array $lead, bool $enabled, ?array $rotated): DealCreatedListener {
		$this->saved = [];
		$saved = &$this->saved;
		$objects = new class ($lead, $saved) {
			/**
			 * @param array<string, mixed> $lead The stored lead.
			 * @param array<int, array<string, mixed>> $saved Collects saves.
			 */
			public function __construct(private array $lead, private array &$saved) {
			}//end __construct()

			/**
			 * @param string $id The uuid.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 *
			 * @return object The entity.
			 */
			public function find(string $id, string $register, string $schema): object {
				$lead = $this->lead;
				return new class ($lead) {
					/**
					 * @param array<string, mixed> $lead The data.
					 */
					public function __construct(private array $lead) {
					}//end __construct()

					/**
					 * @return array<string, mixed> The data.
					 */
					public function getObject(): array {
						return $this->lead;
					}//end getObject()
				};
			}//end find()

			/**
			 * @param array<string, mixed> $object The data.
			 * @param array<int, string> $extend Unused.
			 * @param string $register The register.
			 * @param string $schema The schema.
			 * @param string $uuid The uuid.
			 *
			 * @return void
			 */
			public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): void {
				$this->saved[] = $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => 'pipelinq', 'lead_schema' => 'lead'][$key] ?? $default
		);

		$deals = $this->createMock(ForecastDealService::class);
		$deals->method('applyDefaultCategory')->willReturn(null);

		$rotation = $this->createMock(LeadRotationService::class);
		$rotation->method('isEnabled')->willReturn($enabled);
		$rotation->method('assign')->willReturn($rotated);

		return new DealCreatedListener(
			schemaMapService: $this->createMock(SchemaMapService::class),
			dealService: $deals,
			container: $container,
			appConfig: $config,
			deferral: new RecordingDeferralService(),
			logger: $this->createMock(LoggerInterface::class),
			rotation: $rotation,
		);
	}//end listener()

	/**
	 * Switched off: nothing is saved.
	 *
	 * @return void
	 */
	public function testSwitchOffLeavesTheLead(): void {
		$this->listener(['category' => 'solar'], false, ['category' => 'solar', 'assignee' => 'anna'])->runDeferredWork(['uuid' => 'l1']);
		$this->assertSame([], $this->saved);
	}//end testSwitchOffLeavesTheLead()

	/**
	 * Switched on: the rotated lead is saved.
	 *
	 * @return void
	 */
	public function testSwitchOnSavesTheAssignee(): void {
		$this->listener(['category' => 'solar'], true, ['category' => 'solar', 'assignee' => 'anna', 'assignmentReason' => 'r'])->runDeferredWork(['uuid' => 'l1']);
		$this->assertSame('anna', $this->saved[0]['assignee']);
	}//end testSwitchOnSavesTheAssignee()

	/**
	 * A lead that already has an assignee: rotation answers null and nothing is saved.
	 *
	 * @return void
	 */
	public function testAssignedLeadNotSaved(): void {
		$this->listener(['category' => 'solar', 'assignee' => 'rep'], true, null)->runDeferredWork(['uuid' => 'l1']);
		$this->assertSame([], $this->saved);
	}//end testAssignedLeadNotSaved()
}//end class
