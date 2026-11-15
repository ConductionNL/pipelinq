<?php

/**
 * Unit tests for NormaliseTicketTitle.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Repair;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Repair\NormaliseTicketTitle;
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
 * A title stored as a translation map is rewritten to one plain string; everything else is left alone.
 *
 * @covers \OCA\Pipelinq\Repair\NormaliseTicketTitle
 * @uses \OCA\Pipelinq\Service\TicketService
 * @uses \OCA\Pipelinq\Mcp\McpAnswer
 */
class NormaliseTicketTitleTest extends TestCase {

	/**
	 * The object service double.
	 *
	 * @var ObjectServiceInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $objects;

	/**
	 * The saves made, as [object, uuid, currentUser].
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
	 * Build the step over tickets per type.
	 *
	 * @param array<string, array<int, mixed>> $rowsByType Ticket type to rows.
	 * @param array<string, string>            $config     App config values.
	 * @param array<int, IUser>                $admins     The admin group's users.
	 * @param LoggerInterface|null             $logger     The step's logger.
	 * @param callable|null                    $onSave     Called with each saved object before it is recorded; may throw.
	 *
	 * @return NormaliseTicketTitle The step.
	 */
	private function step(array $rowsByType, array $config = ['register' => '5', 'ticket_schema' => '9'], array $admins = [], ?LoggerInterface $logger = null, ?callable $onSave = null): NormaliseTicketTitle {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$this->objects = $this->createMock(ObjectServiceInterface::class);
		$this->objects->method('findAll')->willReturnCallback(
			function (array $config = [], bool $_rbac = true, bool $_multitenancy = true) use ($rowsByType): array {
				$this->reads[] = [$config, $_rbac, $_multitenancy];
				return ($rowsByType[$config['filters']['ticketType']] ?? []);
			}
		);
		$this->objects->method('saveObject')->willReturnCallback(
			function (...$args) use ($onSave) {
				// Positional: object, extend, register, schema, uuid, _rbac, _multitenancy, silent, _validation, uploadedFiles, currentUser.
				if ($onSave !== null) {
					$onSave($args[0]);
				}

				$this->saved[] = [$args[0], $args[4], ($args[10] ?? null), $args[2], $args[3], $args[5], $args[6]];
				return $this->createMock(\OCA\OpenRegister\Contract\ObjectEntityInterface::class);
			}
		);

		$tickets = new TicketService(
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			objectService: $this->objects,
			mcp: new McpAnswer(logger: $this->createMock(LoggerInterface::class)),
		);

		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn($admins);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => $gid === 'admin' ? $adminGroup : null);

		return new NormaliseTicketTitle(
			ticketService: $tickets,
			groupManager: $groups,
			logger: ($logger ?? $this->createMock(LoggerInterface::class)),
		);
	}//end step()

	/**
	 * An output that collects its info lines.
	 *
	 * @param array<int, string> $lines Where the lines go.
	 *
	 * @return IOutput The output.
	 */
	private function collectingOutput(array &$lines): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $line) use (&$lines): void {
			$lines[] = $line;
		});

		return $output;
	}//end output()

	/**
	 * Without a configured register and schema nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSurfaceIsSkipped(): void {
		$lines = [];
		$this->step(rowsByType: [], config: ['register' => '5'])->run(output: $this->collectingOutput($lines));

		self::assertSame([], $this->reads);
		self::assertSame(['Ticket surface not configured — skipping title normalisation.'], $lines);
	}//end testAnUnconfiguredSurfaceIsSkipped()

	/**
	 * Every ticket type is read unscoped from the configured register and schema.
	 *
	 * @return void
	 */
	public function testEveryTicketTypeIsReadUnscoped(): void {
		$lines = [];

		$this->step(rowsByType: [])->run(output: $this->collectingOutput($lines));

		self::assertSame(
			[
				[['filters' => ['register' => '5', 'schema' => '9', 'ticketType' => 'request'], 'limit' => 10000], false, false],
				[['filters' => ['register' => '5', 'schema' => '9', 'ticketType' => 'complaint'], 'limit' => 10000], false, false],
				[['filters' => ['register' => '5', 'schema' => '9', 'ticketType' => 'melding'], 'limit' => 10000], false, false],
				[['filters' => ['register' => '5', 'schema' => '9', 'ticketType' => 'interaction'], 'limit' => 10000], false, false],
			],
			$this->reads
		);
		self::assertSame(['Ticket titles normalised: 0 rewritten, 0 already plain.'], $lines);
	}//end testEveryTicketTypeIsReadUnscoped()

	/**
	 * Dutch is preferred, then English, then any non-empty value; plain titles are counted.
	 *
	 * @return void
	 */
	public function testTitleMapsAreCollapsedByLocalePreference(): void {
		$admin = $this->createMock(IUser::class);
		$entity = new class implements \JsonSerializable {
			/**
			 * Serialise.
			 *
			 * @return array<string, mixed> The ticket.
			 */
			public function jsonSerialize(): array {
				return ['id' => 't-4', 'title' => ['de' => 'Beschwerde']];
			}
		};
		$lines = [];
		$step = $this->step(
			rowsByType: [
				'request' => [
					['id' => 't-1', 'title' => ['en' => 'Question', 'nl' => 'Vraag']],
					['id' => 't-2', 'title' => ['nl' => '', 'en' => 'Question']],
					['id' => 't-3', 'title' => 'Already plain'],
				],
				'complaint' => [$entity, 'not a row'],
			],
			admins: ['admin' => $admin],
		);

		$step->run(output: $this->collectingOutput($lines));

		self::assertSame(
			[['t-1', 'Vraag'], ['t-2', 'Question'], ['t-4', 'Beschwerde']],
			array_map(static fn (array $save): array => [$save[1], $save[0]['title']], $this->saved)
		);
		self::assertSame($admin, $this->saved[0][2]);
		self::assertSame(['5', '9', false, false], array_slice($this->saved[0], 3));
		self::assertSame(['Ticket titles normalised: 3 rewritten, 1 already plain.'], $lines);
	}//end testTitleMapsAreCollapsedByLocalePreference()

	/**
	 * Date-times are sanitised on the way back, and no admin means no acting user.
	 *
	 * @return void
	 */
	public function testTheSavedTicketIsSanitised(): void {
		$lines = [];
		$step = $this->step(rowsByType: ['melding' => [['id' => 't-1', 'title' => ['nl' => 'Melding'], 'occurredAt' => '2026-01-02 03:04:05+00:00']]]);

		$step->run(output: $this->collectingOutput($lines));

		self::assertSame('2026-01-02T03:04:05+00:00', $this->saved[0][0]['occurredAt']);
		self::assertNull($this->saved[0][2]);
	}//end testTheSavedTicketIsSanitised()

	/**
	 * A map without a usable value is warned about and left alone.
	 *
	 * @return void
	 */
	public function testAnUnusableMapIsLeftUntouched(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with('NormaliseTicketTitle: unusable title map, left untouched', ['uuid' => 't-1']);
		$lines = [];

		$this->step(rowsByType: ['request' => [['id' => 't-1', 'title' => ['nl' => '', 'en' => 3]]]], logger: $logger)->run(output: $this->collectingOutput($lines));

		self::assertSame([], $this->saved);
		self::assertSame(['Ticket titles normalised: 0 rewritten, 0 already plain.'], $lines);
	}//end testAnUnusableMapIsLeftUntouched()

	/**
	 * A failing save is logged and not counted, and the rest continue.
	 *
	 * @return void
	 */
	public function testAFailedSaveIsLoggedAndNotCounted(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with('NormaliseTicketTitle: failed to save ticket', ['uuid' => 't-1', 'exception' => 'validation failed']);
		$lines = [];
		$failFirst = static function (array $object): void {
			if ($object['id'] === 't-1') {
				throw new RuntimeException('validation failed');
			}
		};

		$this->step(
			rowsByType: ['request' => [['id' => 't-1', 'title' => ['nl' => 'A']]], 'complaint' => [['id' => 't-2', 'title' => ['nl' => 'B']]]],
			logger: $logger,
			onSave: $failFirst,
		)->run(output: $this->collectingOutput($lines));

		self::assertSame(['t-2'], array_column($this->saved, 1));
		self::assertSame(['Ticket titles normalised: 1 rewritten, 0 already plain.'], $lines);
	}//end testAFailedSaveIsLoggedAndNotCounted()

	/**
	 * The step names what it does.
	 *
	 * @return void
	 */
	public function testTheStepIsNamed(): void {
		self::assertSame('Normalise ticket titles stored as translation maps into plain strings', $this->step(rowsByType: [])->getName());
	}//end testTheStepIsNamed()
}//end class
