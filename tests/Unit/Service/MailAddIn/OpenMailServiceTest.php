<?php

/**
 * Unit tests for OpenMailService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\MailAddIn
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\MailAddIn;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\CallerObjectReader;
use OCA\Pipelinq\Service\ConfigFileLoaderService;
use OCA\Pipelinq\Service\ContactSyncService;
use OCA\Pipelinq\Service\EmailMatchService;
use OCA\Pipelinq\Service\MailAddIn\OpenMailService;
use OCA\Pipelinq\Mcp\McpAnswer;
use OCA\Pipelinq\Service\TicketService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Opis\JsonSchema\Validator;
use OutOfBoundsException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Sender lookup, add as contact and log this mail, as the signed-in colleague.
 *
 * The reader and the ticket service are the real ones. The object store under
 * them answers only for the rows a test says the caller may read, which is how
 * a refused read looks to this service.
 *
 * @covers \OCA\Pipelinq\Service\MailAddIn\OpenMailService
 * @uses   \OCA\Pipelinq\Service\CallerObjectReader
 * @uses   \OCA\Pipelinq\Service\TicketService
 * @uses   \OCA\Pipelinq\Mcp\McpAnswer
 * @uses   \OCA\Pipelinq\Service\ConfigFileLoaderService
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */
class OpenMailServiceTest extends TestCase {
	private const ANNA = '11111111-1111-4111-8111-111111111111';
	private const BAKKERIJ = '22222222-2222-4222-8222-222222222222';

	/**
	 * The rows the caller may read, keyed `<schema>/<id>`.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $readable = [];

	/**
	 * Every payload the ticket service handed to OpenRegister.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	private EmailMatchService&MockObject $matcher;
	private ContactSyncService&MockObject $contactSync;

	/**
	 * Build the service over the readable rows.
	 *
	 * @return OpenMailService
	 */
	private function service(): OpenMailService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'pipelinq',
				'contact_schema' => 'contact',
				'client_schema' => 'client',
				'ticket_schema' => 'ticket',
				default => $default,
			}
		);

		$readable = &$this->readable;
		$store = new class($readable) {
			/**
			 * @param array<string, array<string, mixed>> $rows The readable rows.
			 */
			public function __construct(private array &$rows) {
			}//end __construct()

			public function find(string $id, $register = null, $schema = null, bool $_rbac = false): ?array {
				if ($_rbac !== true) {
					throw new \LogicException('The reader must read as the caller.');
				}

				return ($this->rows[$schema . '/' . $id] ?? null);
			}//end find()
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);

		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object, ?array $extend = [], $register = null, $schema = null) {
				$this->saved[] = ['object' => $object, 'register' => $register, 'schema' => $schema];
				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('getUuid')->willReturn('cm-1');
				return $entity;
			}
		);

		$this->matcher = $this->createMock(EmailMatchService::class);
		$this->contactSync = $this->createMock(ContactSyncService::class);

		return new OpenMailService(
			$this->matcher,
			new CallerObjectReader($container, $appConfig, new NullLogger()),
			new TicketService($appConfig, new NullLogger(), $objects, new McpAnswer(new NullLogger())),
			$this->contactSync,
		);
	}//end service()

	/**
	 * The ticket schema as the import composes it.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function ticketSchema(): array {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn(dirname(__DIR__, 4));
		$register = (new ConfigFileLoaderService($appManager))->loadConfigurationFile();
		$schema = ($register['components']['schemas']['ticket'] ?? null);
		$this->assertIsArray($schema, 'The ticket schema was not read.');

		return $schema;
	}//end ticketSchema()

	/**
	 * Whether the ticket schema takes a payload, judged on the keywords a
	 * JSON Schema validator knows.
	 *
	 * @param array<string, mixed> $payload The payload.
	 *
	 * @return bool True when valid.
	 */
	private function ticketSchemaTakes(array $payload): bool {
		$schema = $this->ticketSchema();
		$properties = [];
		foreach ($schema['properties'] as $name => $property) {
			$properties[$name] = array_intersect_key($property, array_flip(['type', 'maxLength', 'minLength', 'format', 'enum', 'pattern']));
		}

		$jsonSchema = json_decode((string)json_encode(['type' => 'object', 'required' => $schema['required'], 'properties' => $properties]));

		return (new Validator())->validate(json_decode((string)json_encode($payload)), $jsonSchema)->isValid();
	}//end ticketSchemaTakes()

	/**
	 * A known sender comes back with the client they belong to, and no
	 * organisation is looked for.
	 *
	 * @return void
	 */
	public function testAKnownSenderComesBackWithTheirClient(): void {
		$service = $this->service();
		$this->readable = [
			'contact/' . self::ANNA => ['name' => 'Anna Bakker', 'email' => 'anna@bakkerij-korenschoof.nl', 'client' => self::BAKKERIJ],
			'client/' . self::BAKKERIJ => ['name' => 'Bakkerij de Korenschoof'],
		];
		$this->matcher->method('matchEmailToEntities')->with('anna@bakkerij-korenschoof.nl')
			->willReturn([['entityType' => 'contact', 'entityId' => self::ANNA]]);
		$this->matcher->expects($this->never())->method('matchDomainToOrganization');

		$result = $service->lookup(' Anna@Bakkerij-Korenschoof.nl ');

		$this->assertSame(
			[
				[
					'entityType' => 'contact',
					'entityId' => self::ANNA,
					'name' => 'Anna Bakker',
					'email' => 'anna@bakkerij-korenschoof.nl',
					'clientId' => self::BAKKERIJ,
					'clientName' => 'Bakkerij de Korenschoof',
				],
			],
			$result['matches']
		);
		$this->assertNull($result['organisation']);
	}//end testAKnownSenderComesBackWithTheirClient()

	/**
	 * A match the caller may not read is left out, and so is the client of
	 * a contact when only the contact is readable.
	 *
	 * @return void
	 */
	public function testARecordTheCallerMayNotReadIsLeftOut(): void {
		$service = $this->service();
		$this->readable = ['contact/' . self::ANNA => ['name' => 'Anna Bakker', 'email' => 'anna@example.nl', 'client' => self::BAKKERIJ]];
		$this->matcher->method('matchEmailToEntities')->willReturn(
			[
				['entityType' => 'contact', 'entityId' => self::ANNA],
				['entityType' => 'client', 'entityId' => self::BAKKERIJ],
				['entityType' => 'lead', 'entityId' => 'x'],
			]
		);

		$result = $service->lookup('anna@example.nl');

		$this->assertCount(1, $result['matches']);
		$this->assertSame(self::ANNA, $result['matches'][0]['entityId']);
		$this->assertArrayNotHasKey('clientName', $result['matches'][0]);
	}//end testARecordTheCallerMayNotReadIsLeftOut()

	/**
	 * An unknown sender from a known organisation gets that organisation as
	 * a suggestion; from an unknown one, nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownSenderGetsTheirOrganisationSuggested(): void {
		$service = $this->service();
		$this->readable = ['client/' . self::BAKKERIJ => ['name' => 'Bakkerij de Korenschoof', 'email' => 'info@bakkerij-korenschoof.nl']];
		$this->matcher->method('matchEmailToEntities')->willReturn([]);
		$this->matcher->method('matchDomainToOrganization')->willReturnCallback(
			static fn (string $domain): ?array => match ($domain) {
				'bakkerij-korenschoof.nl' => ['entityType' => 'client', 'entityId' => self::BAKKERIJ],
				default => null,
			}
		);

		$known = $service->lookup('piet@bakkerij-korenschoof.nl');
		$unknown = $service->lookup('piet@elders.nl');

		$this->assertSame([], $known['matches']);
		$this->assertSame(self::BAKKERIJ, $known['organisation']['entityId']);
		$this->assertSame('Bakkerij de Korenschoof', $known['organisation']['name']);
		$this->assertSame(['matches' => [], 'organisation' => null], $unknown);
	}//end testAnUnknownSenderGetsTheirOrganisationSuggested()

	/**
	 * Text that is no address is refused before anything is searched.
	 *
	 * @return void
	 */
	public function testLookupRefusesTextThatIsNoAddress(): void {
		$service = $this->service();
		$this->matcher->expects($this->never())->method('matchEmailToEntities');

		$this->expectException(InvalidArgumentException::class);
		$service->lookup('not an address');
	}//end testLookupRefusesTextThatIsNoAddress()

	/**
	 * Logging a mail on a contact writes one inbound email contact moment,
	 * on the contact and their client, with the opening of the mail only.
	 * The payload is what the real ticket schema takes.
	 *
	 * @return void
	 */
	public function testLoggingAMailWritesAnInboundEmailContactMoment(): void {
		$service = $this->service();
		$this->readable = ['contact/' . self::ANNA => ['name' => 'Anna Bakker', 'client' => self::BAKKERIJ]];

		$logged = $service->logMail('contact', self::ANNA, ' Offerte brood ', '2026-10-12T09:30:00+02:00', str_repeat('é', 1500));

		$this->assertCount(1, $this->saved);
		$payload = $this->saved[0]['object'];
		$this->assertSame('pipelinq', $this->saved[0]['register']);
		$this->assertSame('ticket', $this->saved[0]['schema']);
		$this->assertSame(TicketService::TYPE_CONTACTMOMENT, $payload['ticketType']);
		$this->assertSame(self::ANNA, $payload['contact']);
		$this->assertSame(self::BAKKERIJ, $payload['client']);
		$this->assertSame('Offerte brood', $payload['title']);
		$this->assertSame('email', $payload['channel']);
		$this->assertSame('inbound', $payload['direction']);
		$this->assertSame('2026-10-12T09:30:00+02:00', $payload['occurredAt']);
		$this->assertSame(1000, mb_strlen($payload['description']));
		$this->assertSame('cm-1', $logged['id']);

		$this->assertTrue($this->ticketSchemaTakes(payload: $payload));
		// Control: the judge can refuse, so the pass above means something.
		$this->assertFalse($this->ticketSchemaTakes(payload: (['direction' => 'sideways'] + $payload)));
		$this->assertFalse($this->ticketSchemaTakes(payload: array_diff_key($payload, ['title' => true])));
	}//end testLoggingAMailWritesAnInboundEmailContactMoment()

	/**
	 * A mail logged on a client names the client only, and an empty preview
	 * writes no description.
	 *
	 * @return void
	 */
	public function testLoggingOnAClientNamesTheClientOnly(): void {
		$service = $this->service();
		$this->readable = ['client/' . self::BAKKERIJ => ['name' => 'Bakkerij de Korenschoof']];

		$service->logMail('client', self::BAKKERIJ, str_repeat('a', 300), '2026-10-12 09:30', '  ');

		$payload = $this->saved[0]['object'];
		$this->assertSame(self::BAKKERIJ, $payload['client']);
		$this->assertArrayNotHasKey('contact', $payload);
		$this->assertArrayNotHasKey('description', $payload);
		$this->assertSame(255, mb_strlen($payload['title']));
		$this->assertTrue($this->ticketSchemaTakes(payload: $payload));
	}//end testLoggingOnAClientNamesTheClientOnly()

	/**
	 * A record the caller may not read takes no contact moment.
	 *
	 * @return void
	 */
	public function testNothingIsLoggedOnARecordTheCallerMayNotRead(): void {
		$service = $this->service();

		try {
			$service->logMail('contact', self::ANNA, 'Offerte', '2026-10-12T09:30:00Z', 'tekst');
			$this->fail('The log was accepted.');
		} catch (OutOfBoundsException $e) {
			$this->assertSame([], $this->saved);
		}
	}//end testNothingIsLoggedOnARecordTheCallerMayNotRead()

	/**
	 * A log without a usable type, subject or date is refused and writes
	 * nothing.
	 *
	 * @param string $type The record type.
	 * @param string $subject The subject.
	 * @param string $sentAt The date.
	 *
	 * @return void
	 *
	 * @dataProvider unusableLogs
	 */
	public function testAnUnusableLogIsRefused(string $type, string $subject, string $sentAt): void {
		$service = $this->service();
		$this->readable = ['contact/' . self::ANNA => ['name' => 'Anna Bakker']];

		try {
			$service->logMail($type, self::ANNA, $subject, $sentAt, 'tekst');
			$this->fail('The log was accepted.');
		} catch (InvalidArgumentException $e) {
			$this->assertSame([], $this->saved);
		}
	}//end testAnUnusableLogIsRefused()

	/**
	 * Logs that must be refused.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function unusableLogs(): array {
		return [
			'a lead is no target' => ['lead', 'Offerte', '2026-10-12T09:30:00Z'],
			'no subject' => ['contact', '  ', '2026-10-12T09:30:00Z'],
			'no date' => ['contact', 'Offerte', ''],
			'text that is no date' => ['contact', 'Offerte', 'gisteren ofzo'],
		];
	}//end unusableLogs()

	/**
	 * Adding the sender goes through the contact-first create, with the
	 * client when the caller may read it.
	 *
	 * @return void
	 */
	public function testAddingTheSenderCreatesAContactOfTheirClient(): void {
		$service = $this->service();
		$this->readable = ['client/' . self::BAKKERIJ => ['name' => 'Bakkerij de Korenschoof']];
		$this->contactSync->expects($this->once())->method('createWithContact')
			->with('contact', ['name' => 'Piet Bakker', 'email' => 'piet@bakkerij-korenschoof.nl', 'client' => self::BAKKERIJ])
			->willReturn(['id' => 'c-9']);

		$created = $service->addSender(' Piet Bakker ', 'Piet@Bakkerij-Korenschoof.nl', self::BAKKERIJ);

		$this->assertSame(['id' => 'c-9'], $created);
	}//end testAddingTheSenderCreatesAContactOfTheirClient()

	/**
	 * A client the caller may not read gets no contact, and a sender without
	 * a name or with a broken address is refused.
	 *
	 * @return void
	 */
	public function testAddingTheSenderRefusesWhatItCannotStandBehind(): void {
		$service = $this->service();
		$this->contactSync->expects($this->never())->method('createWithContact');

		try {
			$service->addSender('Piet Bakker', 'piet@bakkerij-korenschoof.nl', self::BAKKERIJ);
			$this->fail('A contact was added to a client the caller cannot read.');
		} catch (OutOfBoundsException $e) {
			$this->addToAssertionCount(1);
		}

		foreach ([['', 'piet@example.nl'], ['Piet', 'piet-at-example']] as [$name, $email]) {
			try {
				$service->addSender($name, $email);
				$this->fail('A sender without a name or an address was added.');
			} catch (InvalidArgumentException $e) {
				$this->addToAssertionCount(1);
			}
		}
	}//end testAddingTheSenderRefusesWhatItCannotStandBehind()
}//end class
