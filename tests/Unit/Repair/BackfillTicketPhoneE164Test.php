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
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Repair\BackfillTicketPhoneE164;
use OCA\Pipelinq\Service\PhoneNormaliser;
use OCA\Pipelinq\Service\TicketPhoneNumber;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tickets stored before phoneE164 existed get it once; a second run writes nothing.
 *
 * @covers \OCA\Pipelinq\Repair\BackfillTicketPhoneE164
 * @uses   \OCA\Pipelinq\Service\TicketPhoneNumber
 * @uses   \OCA\Pipelinq\Service\PhoneNormaliser
 * @uses   \OCA\Pipelinq\Service\TicketService
 * @uses   \OCA\Pipelinq\Mcp\McpAnswer
 *
 * @spec openspec/changes/contact-moments-phone-lookup/tasks.md#task-1.3
 */
class BackfillTicketPhoneE164Test extends TestCase {

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
	 * The step over the store.
	 *
	 * @param array<string, string> $config App config values.
	 * @param callable|null         $onSave Called with each saved object; may throw.
	 * @param IUser|null            $admin  The admin group's user.
	 *
	 * @return BackfillTicketPhoneE164
	 */
	private function step(array $config = ['register' => '5', 'ticket_schema' => '9'], ?callable $onSave = null, ?IUser $admin = null): BackfillTicketPhoneE164 {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? ($key === 'default_country' ? 'NL' : $default))
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
				// Positional: object, extend, register, schema, uuid, _rbac, _multitenancy, silent, _validation, uploadedFiles, currentUser.
				if ($onSave !== null) {
					$onSave($args[0]);
				}

				$this->saved[] = [$args[0], $args[4], $args[5], $args[6], ($args[10] ?? null)];
				$this->store[$args[4]] = $args[0];
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$logger  = $this->createMock(LoggerInterface::class);
		$tickets = new TicketService(appConfig: $appConfig, logger: $logger, objectService: $objects, mcp: new McpAnswer(logger: $logger));

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($admin === null ? [] : [$admin]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);

		return new BackfillTicketPhoneE164($tickets, new TicketPhoneNumber(new PhoneNormaliser($appConfig, $logger)), $groups, $logger);
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
	 * The first run stores each number in E.164 as the system; the second changes nothing.
	 *
	 * @return void
	 */
	public function testTheSecondRunChangesNothing(): void {
		$this->store = [
			't-1' => ['id' => 't-1', 'direction' => 'inbound', 'from_number' => '+31612345678'],
			't-2' => ['id' => 't-2', 'reporterPhone' => '06-87654321'],
			't-3' => ['id' => 't-3', 'title' => 'Geen nummer'],
			't-4' => ['id' => 't-4', 'from_number' => '+31201234567', 'phoneE164' => '+31201234567'],
		];
		$admin = $this->createMock(IUser::class);
		$lines = [];

		$this->step(admin: $admin)->run($this->collectingOutput($lines));

		$this->assertSame(['t-1', 't-2'], array_column($this->saved, 1));
		$this->assertSame('+31612345678', $this->store['t-1']['phoneE164']);
		$this->assertSame('+31687654321', $this->store['t-2']['phoneE164']);
		$this->assertSame([false, false, $admin], array_slice($this->saved[0], 2));
		$this->assertSame([false, false], array_slice($this->reads[0], 1));
		$this->assertSame(['Ticket phone numbers: 2 stored in E.164, 2 unchanged.'], $lines);

		$this->saved = [];
		$second = [];
		$this->step()->run($this->collectingOutput($second));

		$this->assertSame([], $this->saved);
		$this->assertSame(['Ticket phone numbers: 0 stored in E.164, 4 unchanged.'], $second);
	}//end testTheSecondRunChangesNothing()

	/**
	 * The tickets are read in batches until a batch comes back short.
	 *
	 * @return void
	 */
	public function testTheTicketsAreReadInBatches(): void {
		for ($i = 0; $i < (BackfillTicketPhoneE164::BATCH_SIZE + 1); $i++) {
			$this->store['t-'.$i] = ['id' => 't-'.$i, 'phoneE164' => '+31612345678', 'from_number' => '+31612345678'];
		}

		$lines = [];
		$this->step()->run($this->collectingOutput($lines));

		$this->assertSame([0, BackfillTicketPhoneE164::BATCH_SIZE], array_map(static fn (array $r): int => $r[0]['offset'], $this->reads));
		$this->assertSame(['5', '9'], [$this->reads[0][0]['filters']['register'], $this->reads[0][0]['filters']['schema']]);
	}//end testTheTicketsAreReadInBatches()

	/**
	 * A failed save is counted unchanged and the walk goes on; an unconfigured register is skipped.
	 *
	 * @return void
	 */
	public function testAFailedSaveDoesNotStopTheWalk(): void {
		$this->store = [
			't-1' => ['id' => 't-1', 'from_number' => '+31612345678'],
			't-2' => ['id' => 't-2', 'from_number' => '+31687654321'],
		];
		$lines = [];
		$step  = $this->step(onSave: static function (array $object): void {
			if ($object['id'] === 't-1') {
				throw new RuntimeException('locked');
			}
		});

		$step->run($this->collectingOutput($lines));

		$this->assertSame(['t-2'], array_column($this->saved, 1));
		$this->assertSame(['Ticket phone numbers: 1 stored in E.164, 1 unchanged.'], $lines);

		$this->reads = [];
		$skipped = [];
		$this->step(config: [])->run($this->collectingOutput($skipped));
		$this->assertSame([], $this->reads);
		$this->assertSame(['Ticket surface not configured — skipping the ticket phone backfill.'], $skipped);
	}//end testAFailedSaveDoesNotStopTheWalk()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testItNamesWhatItDoes(): void {
		$this->assertSame('Store the phone number of every ticket in E.164 for the phone search', $this->step()->getName());
	}//end testItNamesWhatItDoes()
}//end class
