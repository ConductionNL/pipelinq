<?php

/**
 * Unit tests for TicketLeafProvider.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Integration;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Integration\TicketLeafProvider;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the open tickets a host app reads per master entity.
 *
 * @spec openspec/specs/ticket-leaf/spec.md
 */
class TicketLeafProviderTest extends TestCase {
	/**
	 * Every findAll config the double was asked, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queries = [];

	/**
	 * The RBAC and multitenancy flags each findAll ran with, in order.
	 *
	 * @var array<int, array{rbac: bool, multitenancy: bool}>
	 */
	private array $postures = [];

	/**
	 * Whether the store double applies the status filter, as OpenRegister does.
	 *
	 * @var bool
	 */
	private bool $storeFiltersStatus = true;

	/**
	 * Build the provider over a store holding two clients and four tickets.
	 *
	 * @param bool $configured Whether the register and schemas are configured.
	 *
	 * @return TicketLeafProvider The provider under test.
	 */
	private function provider(bool $configured = true): TicketLeafProvider {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($configured): string {
				$values = [
					'register' => 'reg-1',
					'client_schema' => 'sch-client',
					'ticket_schema' => 'sch-ticket',
				];

				return $configured === true ? ($values[$key] ?? $default) : $default;
			}
		);

		$clients = [
			['id' => 'c-slagerij', 'name' => 'Slagerij', 'masterEntityRef' => 'm-slagerij'],
			['id' => 'c-cloud', 'name' => 'CloudGemeente', 'masterEntityRef' => 'm-cloud'],
		];
		$tickets = [
			['id' => 't1', 'title' => 'Vraag over de vergunning', 'ticketType' => 'request', 'status' => 'new', 'client' => 'c-slagerij', 'assignee' => 'pieter', 'slaDeadline' => '2026-10-30T12:00:00+00:00'],
			['id' => 't2', 'title' => 'Klacht over de stoep', 'ticketType' => 'complaint', 'status' => 'in_progress', 'client' => 'c-slagerij'],
			['id' => 't3', 'title' => 'Vraag beantwoord', 'ticketType' => 'request', 'status' => 'new', 'client' => 'c-cloud'],
			['id' => 't4', 'reference' => 'PQ-2026-0377', 'title' => 'Vraag over huur van het buurthuis', 'ticketType' => 'request', 'status' => 'resolved', 'client' => 'c-slagerij', 'assignee' => 'gone', 'resolvedAt' => '2026-09-29T10:00:00+00:00'],
		];

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config, bool $_rbac=true, bool $_multitenancy=true) use ($clients, $tickets): array {
				$this->queries[]  = $config;
				$this->postures[] = ['rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				$filters = ($config['filters'] ?? []);
				if (($filters['schema'] ?? '') === 'sch-client') {
					$refs = ($filters['masterEntityRef']['in'] ?? []);
					$rows = array_values(array_filter($clients, static fn (array $c): bool => in_array($c['masterEntityRef'], $refs, true)));
				} else {
					$ids = ($filters['client']['in'] ?? []);
					$rows = array_values(array_filter($tickets, static fn (array $t): bool => in_array($t['client'], $ids, true)));
					if ($this->storeFiltersStatus === true && isset($filters['status']) === true) {
						$rows = array_values(array_filter($rows, static function (array $t) use ($filters): bool {
							$closed = in_array($t['status'], TicketLeafProvider::CLOSED_STATUSES, true);
							return isset($filters['status']['in']) === true ? $closed : ($closed === false);
						}));
					}
				}

				return array_map(
					function (array $data): ObjectEntityInterface {
						$entity = $this->createMock(ObjectEntityInterface::class);
						$entity->method('jsonSerialize')->willReturn($data);
						return $entity;
					},
					$rows
				);
			}
		);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => (['pieter' => 'Pieter Jansen'][$uid] ?? null)
		);

		return new TicketLeafProvider($appConfig, $objectService, $userManager, $this->createMock(LoggerInterface::class));
	}//end provider()

	/**
	 * The clients of one master entity, for a host app that links to that
	 * party's ticket list; unknown or unconfigured reads as none.
	 *
	 * @return void
	 */
	public function testClientIdsOfOneMasterEntity(): void {
		$this->assertSame(expected: ['c-slagerij'], actual: $this->provider()->clientIdsOf(ref: 'm-slagerij'));
		$this->assertSame(expected: [], actual: $this->provider()->clientIdsOf(ref: 'm-unknown'));
		$this->assertSame(expected: [], actual: $this->provider()->clientIdsOf(ref: ' '));
		$this->assertSame(expected: [], actual: $this->provider(configured: false)->clientIdsOf(ref: 'm-slagerij'));
	}//end testClientIdsOfOneMasterEntity()

	/**
	 * Open tickets are grouped by the master entity their client maps to.
	 *
	 * @return void
	 */
	public function testOpenTicketsAreGroupedByMasterEntity(): void {
		$result = $this->provider()->openByMasterEntity(refs: ['m-slagerij', 'm-cloud', 'm-unknown']);

		$this->assertSame(200, $result['status']);
		$this->assertSame(['t1', 't2'], array_column($result['tickets']['m-slagerij'], 'id'));
		$this->assertSame(['t3'], array_column($result['tickets']['m-cloud'], 'id'));
		$this->assertSame([], $result['tickets']['m-unknown']);
		$this->assertSame(
			['id' => 't1', 'reference' => '', 'title' => 'Vraag over de vergunning', 'ticketType' => 'request', 'status' => 'new', 'assignee' => 'pieter', 'assigneeName' => 'Pieter Jansen', 'slaDeadline' => '2026-10-30T12:00:00+00:00', 'resolvedAt' => ''],
			$result['tickets']['m-slagerij'][0]
		);
	}//end testOpenTicketsAreGroupedByMasterEntity()

	/**
	 * The ticket read asks only for open requests and complaints of those clients.
	 *
	 * @return void
	 */
	public function testTheTicketReadAsksForOpenRequestsAndComplaintsOnly(): void {
		$this->provider()->openByMasterEntity(refs: ['m-slagerij']);

		$ticketQuery = $this->queries[1]['filters'];
		$this->assertSame('reg-1', $ticketQuery['register']);
		$this->assertSame('sch-ticket', $ticketQuery['schema']);
		$this->assertSame(['in' => ['c-slagerij']], $ticketQuery['client']);
		$this->assertSame(['in' => ['request', 'complaint']], $ticketQuery['ticketType']);
		$this->assertSame(['notIn' => TicketLeafProvider::CLOSED_STATUSES], $ticketQuery['status']);
	}//end testTheTicketReadAsksForOpenRequestsAndComplaintsOnly()

	/**
	 * Both reads run as the calling user: OpenRegister's RBAC and organisation
	 * scoping stay on, so a caller outside the ticket and client schemas' read
	 * groups gets no tickets and no counts, whatever master entity they name.
	 *
	 * @return void
	 */
	public function testBothReadsKeepRbacAndMultitenancyOn(): void {
		$this->provider()->openByMasterEntity(refs: ['m-slagerij']);

		$this->assertCount(2, $this->postures);
		foreach ($this->postures as $posture) {
			$this->assertSame(['rbac' => true, 'multitenancy' => true], $posture);
		}
	}//end testBothReadsKeepRbacAndMultitenancyOn()

	/**
	 * No reference, or no client behind them, asks for no tickets at all.
	 *
	 * @return void
	 */
	public function testNoClientMeansNoTicketRead(): void {
		$this->assertSame(['status' => 200, 'tickets' => []], $this->provider()->openByMasterEntity(refs: ['', '  ']));
		$this->assertSame([], $this->queries);

		$result = $this->provider()->openByMasterEntity(refs: ['m-unknown']);
		$this->assertSame(['m-unknown' => []], $result['tickets']);
		$this->assertCount(1, $this->queries);
	}//end testNoClientMeansNoTicketRead()

	/**
	 * An unconfigured instance answers 503, not an empty list.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreIsNotAnEmptyOne(): void {
		$result = $this->provider(configured: false)->openByMasterEntity(refs: ['m-slagerij']);

		$this->assertSame(503, $result['status']);
		$this->assertArrayNotHasKey('tickets', $result);
	}//end testAnUnconfiguredStoreIsNotAnEmptyOne()

	/**
	 * A host app can ask for the closed tickets, or all of them, with the
	 * reference, the assignee's name (the uid when the user is gone) and when
	 * it was resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-host-app-can-read-a-partys-closed-tickets-too
	 */
	public function testClosedAndAllTicketsCarryReferenceAndAssigneeName(): void {
		$closed = $this->provider()->byMasterEntity(refs: ['m-slagerij'], status: 'closed');
		$this->assertSame(['t4'], array_column($closed['tickets']['m-slagerij'], 'id'));
		$this->assertSame(['in' => TicketLeafProvider::CLOSED_STATUSES], $this->queries[1]['filters']['status']);
		$this->assertSame('PQ-2026-0377', $closed['tickets']['m-slagerij'][0]['reference']);
		$this->assertSame('gone', $closed['tickets']['m-slagerij'][0]['assigneeName']);
		$this->assertSame('2026-09-29T10:00:00+00:00', $closed['tickets']['m-slagerij'][0]['resolvedAt']);

		$all = $this->provider()->byMasterEntity(refs: ['m-slagerij'], status: 'all');
		$this->assertSame(['t1', 't2', 't4'], array_column($all['tickets']['m-slagerij'], 'id'));
		$this->assertArrayNotHasKey('status', $this->queries[3]['filters']);
	}//end testClosedAndAllTicketsCarryReferenceAndAssigneeName()

	/**
	 * The open read stays open even when the store ignores the status filter,
	 * and an unknown filter is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/specs/ticket-leaf/spec.md#requirement-a-host-app-can-read-a-partys-closed-tickets-too
	 */
	public function testTheStatusFilterHoldsAndAnUnknownOneIsRefused(): void {
		$this->storeFiltersStatus = false;
		$open = $this->provider()->openByMasterEntity(refs: ['m-slagerij']);
		$this->assertSame(['t1', 't2'], array_column($open['tickets']['m-slagerij'], 'id'));

		$this->assertSame(400, $this->provider()->byMasterEntity(refs: ['m-slagerij'], status: 'deleted')['status']);
	}//end testTheStatusFilterHoldsAndAnUnknownOneIsRefused()

	/**
	 * More references than the cap are refused rather than scanned.
	 *
	 * @return void
	 */
	public function testTooManyReferencesAreRefused(): void {
		$refs = array_map(static fn (int $i): string => 'm-'.$i, range(1, TicketLeafProvider::MAX_REFS + 1));
		$this->assertSame(400, $this->provider()->openByMasterEntity(refs: $refs)['status']);
	}//end testTooManyReferencesAreRefused()
}//end class
