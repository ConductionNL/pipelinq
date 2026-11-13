<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MailAddIn;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\MailAddIn\OpenMailService;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The refusals of the mail add-in (which one wins, and what it says), and the
 * normalisation it applies before reading or writing.
 *
 * @covers \OCA\Pipelinq\Service\MailAddIn\OpenMailService
 * @uses   \OCA\Pipelinq\Service\CallerObjectReader
 * @uses   \OCA\Pipelinq\Service\TicketService
 * @uses   \OCA\Pipelinq\Mcp\McpAnswer
 */
class OpenMailServiceEdgeCasesTest extends TestCase {
	private const ANNA = '11111111-1111-4111-8111-111111111111';

	/**
	 * The rows the caller may read, keyed `<schema>/<id>`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $readable = [];

	/**
	 * Every `<schema>/<id>` the reader asked for.
	 *
	 * @var array<int, string>
	 */
	private array $reads = [];

	/**
	 * Every object handed to OpenRegister.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	private EmailMatchService&MockObject $matcher;

	private ContactSyncService&MockObject $contactSync;

	/**
	 * Build the service over the readable rows with the real reader and ticket service.
	 */
	private function service(): OpenMailService {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'pipelinq',
				'contact_schema' => 'contact',
				'client_schema' => 'client',
				'ticket_schema' => 'ticket',
				default => $default,
			}
		);

		$store = new class($this->readable, $this->reads) {
			/**
			 * @param array<string, array<string, mixed>> $rows  The readable rows.
			 * @param array<int, string>                  $reads The reads made.
			 */
			public function __construct(private array &$rows, private array &$reads) {
			}

			public function find(string $id, $register = null, $schema = null, bool $_rbac = false): ?array {
				$this->reads[] = $schema.'/'.$id;
				return ($this->rows[$schema.'/'.$id] ?? null);
			}
		};
		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object): ObjectEntityInterface {
				$this->saved[] = $object;
				$entity        = $this->createMock(originalClassName: ObjectEntityInterface::class);
				$entity->method('getUuid')->willReturn('cm-7');
				return $entity;
			}
		);

		$this->matcher     = $this->createMock(originalClassName: EmailMatchService::class);
		$this->contactSync = $this->createMock(originalClassName: ContactSyncService::class);

		return new OpenMailService(
			matcher: $this->matcher,
			reader: new CallerObjectReader($container, $appConfig, new NullLogger()),
			tickets: new TicketService($appConfig, new NullLogger(), $objects, new McpAnswer(new NullLogger())),
			contactSync: $this->contactSync,
		);
	}

	/**
	 * @return array<string, array{0: callable(OpenMailService): mixed, 1: class-string, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'lookup of no address' => [static fn (OpenMailService $s) => $s->lookup('anna at example'), InvalidArgumentException::class, 'email is not an email address'],
			'sender without a name' => [static fn (OpenMailService $s) => $s->addSender('  ', 'anna@example.nl'), InvalidArgumentException::class, 'A name and an email address are required.'],
			'sender on an unreadable client' => [static fn (OpenMailService $s) => $s->addSender('Anna', 'anna@example.nl', 'c-unknown'), OutOfBoundsException::class, 'client not found'],
			'log on a lead' => [static fn (OpenMailService $s) => $s->logMail('lead', self::ANNA, '', '', ''), InvalidArgumentException::class, 'entityType must be contact or client, with an entityId'],
			'log without an id' => [static fn (OpenMailService $s) => $s->logMail('contact', '   ', 'Offerte', 'now', ''), InvalidArgumentException::class, 'entityType must be contact or client, with an entityId'],
			'log on an unreadable record' => [static fn (OpenMailService $s) => $s->logMail('client', 'c-unknown', '', '', ''), OutOfBoundsException::class, 'record not found'],
			'log without a subject' => [static fn (OpenMailService $s) => $s->logMail('contact', self::ANNA, ' ', '', ''), InvalidArgumentException::class, 'subject is required'],
			'log without a date' => [static fn (OpenMailService $s) => $s->logMail('contact', self::ANNA, 'Offerte', '  ', ''), InvalidArgumentException::class, 'sentAt is required'],
			'log with no date' => [static fn (OpenMailService $s) => $s->logMail('contact', self::ANNA, 'Offerte', 'yesterday-ish', ''), InvalidArgumentException::class, 'sentAt is not a date and time'],
		];
	}

	/**
	 * @dataProvider refusals
	 *
	 * @param callable(OpenMailService): mixed $call      The call to make.
	 * @param class-string                     $exception The expected exception.
	 * @param string                           $message   The expected message.
	 */
	public function testEachRefusalSaysWhatIsWrongAndWritesNothing(callable $call, string $exception, string $message): void {
		$service        = $this->service();
		$this->readable = ['contact/'.self::ANNA => ['name' => 'Anna']];
		$this->contactSync->expects($this->never())->method('createWithContact');

		try {
			$call($service);
			$this->fail('The call was accepted.');
		} catch (InvalidArgumentException | OutOfBoundsException $e) {
			$this->assertInstanceOf($exception, $e);
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}

	public function testABlankClientIsNeitherReadNorSent(): void {
		$service = $this->service();
		$this->contactSync->expects($this->once())->method('createWithContact')
			->with('contact', ['name' => 'Anna', 'email' => 'anna@example.nl'])
			->willReturn(['id' => 'c-1']);

		$this->assertSame(['id' => 'c-1'], $service->addSender('Anna', ' ANNA@example.nl ', '   '));
		$this->assertSame([], $this->reads);
	}

	public function testTheClientIdIsTrimmedBeforeItIsReadAndSent(): void {
		$service        = $this->service();
		$this->readable = ['client/c-1' => ['name' => 'Bakkerij']];
		$this->contactSync->expects($this->once())->method('createWithContact')
			->with('contact', ['name' => 'Anna', 'email' => 'anna@example.nl', 'client' => 'c-1'])
			->willReturn([]);

		$service->addSender('Anna', 'anna@example.nl', ' c-1 ');

		$this->assertSame(['client/c-1'], $this->reads);
	}

	public function testAMatchOfAnotherTypeIsNotEvenRead(): void {
		$service = $this->service();
		$this->matcher->method('matchEmailToEntities')->willReturn([['entityType' => 'lead', 'entityId' => 'l-1']]);
		$this->matcher->expects($this->once())->method('matchDomainToOrganization')->with('example.nl')->willReturn(null);

		$this->assertSame(['matches' => [], 'organisation' => null], $service->lookup('Anna@Example.NL'));
		$this->assertSame([], $this->reads);
	}

	public function testASuggestedOrganisationIsReadAsAClient(): void {
		$service        = $this->service();
		$this->readable = ['client/c-1' => ['name' => 'Bakkerij', 'client' => 'c-2']];
		$this->matcher->method('matchEmailToEntities')->willReturn([]);
		$this->matcher->method('matchDomainToOrganization')->willReturn(['entityType' => 'contact', 'entityId' => 'c-1']);

		$this->assertSame(
			['entityType' => 'client', 'entityId' => 'c-1', 'name' => 'Bakkerij', 'email' => ''],
			$service->lookup('piet@bakkerij.nl')['organisation']
		);
		$this->assertSame(['client/c-1'], $this->reads);
	}

	public function testAContactWithAnEmptyClientIsLoggedOnTheContactOnly(): void {
		$service        = $this->service();
		$this->readable = ['contact/'.self::ANNA => ['name' => 'Anna', 'client' => '']];

		$logged = $service->logMail('contact', self::ANNA, 'Offerte', '12 October 2026 09:30 +01:00', '');

		$this->assertSame('cm-7', $logged['id']);
		$this->assertSame(self::ANNA, $this->saved[0]['contact']);
		$this->assertArrayNotHasKey('client', $this->saved[0]);
		$this->assertArrayNotHasKey('description', $this->saved[0]);
		$this->assertSame('2026-10-12T09:30:00+01:00', $this->saved[0]['occurredAt']);
	}
}
