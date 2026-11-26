<?php

/**
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\TicketReferenceSequenceInterface;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Repair\BackfillTicketReferences;
use OCA\Pipelinq\Service\TicketService;
use OCA\Pipelinq\Tests\Support\LegacyObjectArgs;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Requests and complaints stored before `reference` existed get a number once;
 * a second run writes nothing.
 *
 * @covers \OCA\Pipelinq\Repair\BackfillTicketReferences
 * @uses   \OCA\Pipelinq\Service\TicketService
 * @uses   \OCA\Pipelinq\Mcp\McpAnswer
 *
 * @spec openspec/changes/ticket-leaf-closed-reference-assignee/tasks.md#task-3.1
 */
class BackfillTicketReferencesTest extends TestCase {

	/**
	 * The stored tickets, by id; saves write back into it.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $store = [];

	/**
	 * The saves made, as [object, uuid, rbac, multitenancy, currentUser].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $saved = [];

	/**
	 * The reads made, as [config, rbac, multitenancy].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $reads = [];

	/**
	 * The last counter value the fake sequence handed out.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * The step over the store.
	 *
	 * @param array<string, string> $config     App config values.
	 * @param callable|null         $onSave     Called with each saved object; may throw.
	 * @param IUser|null            $admin      The admin group's user.
	 * @param bool                  $seqFailing Whether the sequence throws.
	 *
	 * @return BackfillTicketReferences
	 */
	private function step(
		array $config = ['register' => '5', 'ticket_schema' => '9'],
		?callable $onSave = null,
		?IUser $admin = null,
		bool $seqFailing = false,
	): BackfillTicketReferences {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				return array_slice(array_values($this->store), ($config['offset'] ?? 0), $config['limit']);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($onSave): ObjectEntityInterface {
				$args = LegacyObjectArgs::positional(method: 'saveObject', args: $args);
				// Positional: object, extend, register, schema, uuid, _rbac, _multitenancy, silent, _validation, uploadedFiles, currentUser.
				if ($onSave !== null) {
					$onSave($args[0]);
				}

				$this->saved[] = [$args[0], $args[4], $args[5], $args[6], ($args[10] ?? null)];
				$this->store[$args[4]] = $args[0];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$sequence = $this->createMock(TicketReferenceSequenceInterface::class);
		$sequence->method('next')->willReturnCallback(function () use ($seqFailing): string {
			if ($seqFailing === true) {
				throw new RuntimeException('counter locked');
			}

			$this->counter++;
			return sprintf('PQ-2026-%04d', $this->counter);
		});

		$logger  = $this->createMock(LoggerInterface::class);
		$tickets = new TicketService(appConfig: $appConfig, logger: $logger, objectService: $objects, mcp: new McpAnswer(logger: $logger));

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($admin === null ? [] : [$admin]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		return new BackfillTicketReferences($tickets, $sequence, $groups, $logger);
	}//end step()

	/**
	 * An output that collects its info lines.
	 *
	 * @param array<int, string> $lines Where the lines go.
	 *
	 * @return IOutput
	 */
	private function collectingOutput(array &$lines): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $line) use (&$lines): void {
			$lines[] = $line;
		});
		return $output;
	}//end collectingOutput()

	/**
	 * The first run numbers each request and complaint without a reference as the
	 * admin; tickets of other types and those that have one are left alone; the
	 * second run changes nothing.
	 *
	 * @return void
	 */
	public function testTheSecondRunChangesNothing(): void {
		$this->store = [
			't-1' => ['id' => 't-1', 'ticketType' => 'request', 'title' => 'Aanvraag'],
			't-2' => ['id' => 't-2', 'ticketType' => 'complaint', 'reference' => '  '],
			't-3' => ['id' => 't-3', 'ticketType' => 'request', 'reference' => 'PQ-2025-0007'],
			't-4' => ['id' => 't-4', 'ticketType' => 'contactmoment'],
			't-5' => ['id' => 't-5', 'title' => 'Geen type'],
		];
		$admin = $this->createMock(IUser::class);
		$lines = [];

		$this->step(admin: $admin)->run($this->collectingOutput($lines));

		$this->assertSame(['t-1', 't-2'], array_column($this->saved, 1));
		$this->assertSame('PQ-2026-0001', $this->store['t-1']['reference']);
		$this->assertSame('PQ-2026-0002', $this->store['t-2']['reference']);
		$this->assertSame('PQ-2025-0007', $this->store['t-3']['reference']);
		$this->assertArrayNotHasKey('reference', $this->store['t-4']);
		$this->assertSame([false, false, $admin], array_slice($this->saved[0], 2));
		$this->assertSame([false, false], array_slice($this->reads[0], 1));
		$this->assertSame(['Ticket references: 2 numbered, 3 unchanged, 0 failed.'], $lines);

		$this->saved = [];
		$second = [];
		$this->step()->run($this->collectingOutput($second));

		$this->assertSame([], $this->saved);
		$this->assertSame(2, $this->counter);
		$this->assertSame(['Ticket references: 0 numbered, 5 unchanged, 0 failed.'], $second);
	}//end testTheSecondRunChangesNothing()

	/**
	 * The tickets are read in batches until a batch comes back short.
	 *
	 * @return void
	 */
	public function testTheTicketsAreReadInBatches(): void {
		for ($i = 0; $i < (BackfillTicketReferences::BATCH_SIZE + 1); $i++) {
			$this->store['t-'.$i] = ['id' => 't-'.$i, 'ticketType' => 'request', 'reference' => 'PQ-2026-'.$i];
		}

		$lines = [];
		$this->step()->run($this->collectingOutput($lines));

		$this->assertSame([0, BackfillTicketReferences::BATCH_SIZE], array_map(static fn (array $r): int => $r[0]['offset'], $this->reads));
		$this->assertSame(['5', '9'], [$this->reads[0][0]['filters']['register'], $this->reads[0][0]['filters']['schema']]);
	}//end testTheTicketsAreReadInBatches()

	/**
	 * A failed save or a failing sequence is counted failed and the walk goes on;
	 * an unconfigured register is skipped.
	 *
	 * @return void
	 */
	public function testAFailureDoesNotStopTheWalk(): void {
		$this->store = [
			't-1' => ['id' => 't-1', 'ticketType' => 'request'],
			't-2' => ['id' => 't-2', 'ticketType' => 'complaint'],
		];
		$lines = [];
		$step  = $this->step(onSave: static function (array $object): void {
			if ($object['id'] === 't-1') {
				throw new RuntimeException('locked');
			}
		});

		$step->run($this->collectingOutput($lines));

		$this->assertSame(['t-2'], array_column($this->saved, 1));
		$this->assertSame(['Ticket references: 1 numbered, 0 unchanged, 1 failed.'], $lines);

		$this->store  = ['t-9' => ['id' => 't-9', 'ticketType' => 'request']];
		$this->saved  = [];
		$failingLines = [];
		$this->step(seqFailing: true)->run($this->collectingOutput($failingLines));
		$this->assertSame([], $this->saved);
		$this->assertSame(['Ticket references: 0 numbered, 0 unchanged, 1 failed.'], $failingLines);

		$this->reads = [];
		$skipped = [];
		$this->step(config: [])->run($this->collectingOutput($skipped));
		$this->assertSame([], $this->reads);
		$this->assertSame(['Ticket surface not configured — skipping the ticket reference backfill.'], $skipped);
	}//end testAFailureDoesNotStopTheWalk()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testItNamesWhatItDoes(): void {
		$this->assertSame('Give every request and complaint without one its reference number', $this->step()->getName());
	}//end testItNamesWhatItDoes()
}//end class
