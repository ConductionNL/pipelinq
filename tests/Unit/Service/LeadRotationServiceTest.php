<?php

/**
 * Unit tests for LeadRotationService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\LeadRotationService;
use OCA\Pipelinq\Service\RoutingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Fewest wins, cap and pause exclude, ties go to the oldest last lead.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 */
class LeadRotationServiceTest extends TestCase {
	/**
	 * Build the service over profiles and the leads each colleague holds.
	 *
	 * @param array<int, array<string, mixed>> $profiles The matching agent profiles.
	 * @param array<string, array<int, string>> $leads Colleague => creation times of their leads.
	 * @param string $enabled The switch value.
	 *
	 * @return LeadRotationService The service.
	 */
	private function service(array $profiles, array $leads = [], string $enabled = 'true'): LeadRotationService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => [
				'register' => 'pipelinq',
				'lead_schema' => 'lead',
				LeadRotationService::ENABLED_KEY => $enabled,
			][$key] ?? $default
		);

		$routing = $this->createMock(RoutingService::class);
		$routing->method('findMatchingAgents')->willReturn($profiles);
		$routing->method('filterByAvailability')->willReturnCallback(
			static fn (array $all): array => array_values(array_filter($all, static fn (array $p): bool => ($p['isAvailable'] ?? true) !== false))
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			static function (array $config) use ($leads): array {
				$user = $config['filters']['assignee'];
				return array_map(
					static fn (string $created): array => ['assignee' => $user, '@self' => ['created' => $created]],
					($leads[$user] ?? [])
				);
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-10-30T12:00:00+00:00'));

		return new LeadRotationService($config, $routing, $objects, $l10n, $this->createMock(LoggerInterface::class), $clock);
	}//end service()

	/**
	 * A profile with skill Solar.
	 *
	 * @param string $userId The colleague.
	 * @param array<string, mixed> $extra Further profile fields.
	 *
	 * @return array<string, mixed> The profile.
	 */
	private function profile(string $userId, array $extra = []): array {
		return ['userId' => $userId, 'matchedSkill' => 'Solar'] + $extra;
	}//end profile()

	/**
	 * The colleague with the fewest leads in 30 days gets the lead, with a reason.
	 *
	 * @return void
	 */
	public function testFewestWins(): void {
		$service = $this->service(
			[$this->profile('anna'), $this->profile('bram', ['leadCapPer30Days' => 10])],
			['anna' => ['2026-10-20T09:00:00+00:00', '2026-10-21T09:00:00+00:00'], 'bram' => ['2026-10-22T09:00:00+00:00']]
		);

		$lead = $service->assign(['title' => 'Panels', 'category' => 'solar']);
		$this->assertSame('bram', $lead['assignee']);
		$this->assertSame('Rotation: fewest leads in 30 days (1 of cap 10), skill Solar', $lead['assignmentReason']);
	}//end testFewestWins()

	/**
	 * Leads older than 30 days do not count.
	 *
	 * @return void
	 */
	public function testOldLeadsDoNotCount(): void {
		$service = $this->service(
			[$this->profile('anna'), $this->profile('bram')],
			['anna' => ['2026-08-01T09:00:00+00:00', '2026-08-02T09:00:00+00:00'], 'bram' => ['2026-10-22T09:00:00+00:00']]
		);

		$this->assertSame('anna', $service->assign(['category' => 'solar'])['assignee']);
	}//end testOldLeadsDoNotCount()

	/**
	 * A colleague at their cap is passed over.
	 *
	 * @return void
	 */
	public function testCapExcludes(): void {
		$service = $this->service(
			[$this->profile('anna', ['leadCapPer30Days' => 1]), $this->profile('bram')],
			['anna' => ['2026-10-20T09:00:00+00:00'], 'bram' => ['2026-10-21T09:00:00+00:00', '2026-10-22T09:00:00+00:00']]
		);

		$this->assertSame('bram', $service->assign(['category' => 'solar'])['assignee']);
	}//end testCapExcludes()

	/**
	 * A paused or unavailable colleague is passed over.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-a-colleague-can-pause-their-share-req-lrt-002
	 */
	public function testPauseExcludes(): void {
		$service = $this->service(
			[$this->profile('anna', ['pauseLeadAssignment' => true]), $this->profile('cees', ['isAvailable' => false]), $this->profile('bram')],
			['bram' => ['2026-10-21T09:00:00+00:00']]
		);

		$this->assertSame('bram', $service->assign(['category' => 'solar'])['assignee']);
	}//end testPauseExcludes()

	/**
	 * A tie goes to the colleague whose last lead is oldest; one with none goes first.
	 *
	 * @return void
	 */
	public function testTieGoesToTheOldestLastLead(): void {
		$service = $this->service(
			[$this->profile('anna'), $this->profile('bram')],
			['anna' => ['2026-10-25T09:00:00+00:00'], 'bram' => ['2026-10-10T09:00:00+00:00']]
		);
		$this->assertSame('bram', $service->assign(['category' => 'solar'])['assignee']);

		$fresh = $this->service(
			[$this->profile('anna'), $this->profile('dirk')],
			['anna' => ['2026-08-25T09:00:00+00:00']]
		);
		$this->assertSame('dirk', $fresh->assign(['category' => 'solar'])['assignee']);
	}//end testTieGoesToTheOldestLastLead()

	/**
	 * Nobody eligible: only the reason is written, and only once.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-a-colleague-can-pause-their-share-req-lrt-002
	 */
	public function testNobodyEligibleWritesOnlyTheReason(): void {
		$service = $this->service([$this->profile('anna', ['pauseLeadAssignment' => true])]);

		$lead = $service->assign(['category' => 'solar']);
		$this->assertArrayNotHasKey('assignee', $lead);
		$this->assertSame('Rotation found no eligible colleague', $lead['assignmentReason']);
		$this->assertNull($service->assign($lead));
	}//end testNobodyEligibleWritesOnlyTheReason()

	/**
	 * A lead with an assignee is left alone; the switch reads the app setting.
	 *
	 * @return void
	 */
	public function testAssignedLeadLeftAloneAndSwitch(): void {
		$service = $this->service([$this->profile('anna')]);
		$this->assertNull($service->assign(['category' => 'solar', 'assignee' => 'rep']));
		$this->assertTrue($service->isEnabled());
		$this->assertFalse($this->service([], [], 'false')->isEnabled());
	}//end testAssignedLeadLeftAloneAndSwitch()
}//end class
