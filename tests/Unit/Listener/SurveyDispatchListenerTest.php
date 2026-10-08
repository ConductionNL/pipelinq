<?php

/**
 * Unit tests for SurveyDispatchListener, the caller of the survey dispatch.
 *
 * pipelinq#2072: `SurveyDispatchService::onInteractionCompleted()` existed with
 * a full test suite, but nothing called it, so no satisfaction survey was ever
 * sent. These tests go through the CALLER: the registration in Application,
 * then the listener given OpenRegister's real ObjectUpdatedEvent for a ticket
 * that reached a terminal status, drained through the real deferred job, into
 * the real dispatch service, down to the invitation it writes.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\DeferredWorkGuard;
use OCA\Pipelinq\Listener\SurveyDispatchListener;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Service\SurveyDispatchService;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/RecordingDeferralService.php';
require_once __DIR__ . '/DeferredJobDrain.php';

/**
 * Tests for the listener that turns a completed ticket into survey invitations.
 */
class SurveyDispatchListenerTest extends TestCase {

	/**
	 * The listener class, named as a string so the registration test fails on
	 * its assertion rather than on autoloading.
	 *
	 * @var string
	 */
	private const LISTENER = 'OCA\Pipelinq\Listener\SurveyDispatchListener';

	/**
	 * The ticket schema id the schema map resolves to `ticket`.
	 *
	 * @var string
	 */
	private const TICKET_SCHEMA = 'sch-ticket';

	/**
	 * The configured dispatch rules, as JSON.
	 *
	 * @var string
	 */
	private string $rulesJson = '[]';

	/**
	 * Tickets and contacts the object service double serves, keyed by uuid.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $stored = [];

	/**
	 * Invitations already on file, served to findAll().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $invitations = [];

	/**
	 * Every object the dispatch wrote, with its schema.
	 *
	 * @var array<int, array{object: array<string, mixed>, schema: mixed}>
	 */
	private array $written = [];

	/**
	 * The deferral double the last-built listener was wired with.
	 *
	 * @var RecordingDeferralService|null
	 */
	private ?RecordingDeferralService $deferral = null;

	/**
	 * Clear the shared re-entrancy guard between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		DeferredWorkGuard::reset();
	}//end setUp()

	/**
	 * Application registers the survey listener on both object events.
	 *
	 * Without this registration the listener is a class nobody instantiates,
	 * which is exactly the defect pipelinq#2072 reported for the service.
	 *
	 * @return void
	 */
	public function testApplicationRegistersTheSurveyListener(): void {
		$registered = [];
		$context = $this->createMock(IRegistrationContext::class);
		$context->method('registerEventListener')->willReturnCallback(
			function (string $event, string $listener, int $priority = 0) use (&$registered): void {
				$registered[] = [$event, $listener];
			}
		);

		$app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$app->register($context);

		$this->assertContains([ObjectUpdatedEvent::class, self::LISTENER], $registered);
		$this->assertContains([ObjectCreatedEvent::class, self::LISTENER], $registered);
	}//end testApplicationRegistersTheSurveyListener()

	/**
	 * A request ticket that reaches `completed` gets a scheduled invitation.
	 *
	 * The real ObjectUpdatedEvent, the real deferred job, the real dispatch
	 * service: the only doubles are the store and the config.
	 *
	 * @return void
	 */
	public function testACompletedTicketWritesAScheduledInvitation(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'request', status: 'completed')]);
		$this->stored['contact-1'] = [
			'contactsUid' => 'uid-1',
			'email' => 'jan@example.org',
			'client' => 'client-1',
		];
		$this->stored['ticket-1'] = $this->ticket(status: 'completed');

		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(uuid: 'ticket-1', data: $this->stored['ticket-1']),
				$this->entity(uuid: 'ticket-1', data: $this->ticket(status: 'in_progress'))
			)
		);

		$this->assertCount(1, $this->deferral->entries, 'The completion is queued, not run inside the save');
		DeferredJobDrain::run($this, $this->deferral, $listener);

		$this->assertCount(1, $this->written);
		$invitation = $this->written[0]['object'];
		$this->assertSame('sch-invitation', $this->written[0]['schema']);
		$this->assertSame('scheduled', $invitation['status']);
		$this->assertSame('survey-1', $invitation['surveyRef']);
		$this->assertSame('uid-1', $invitation['contactRef']);
		$this->assertSame('jan@example.org', $invitation['deliveryAddress']);
		$this->assertSame('ticket-1', $invitation['linkedEntityId']);
		$this->assertSame('request', $invitation['linkedEntityType']);
	}//end testACompletedTicketWritesAScheduledInvitation()

	/**
	 * A contact moment is matched by the rule vocabulary's `contactmoment`.
	 *
	 * The ticket discriminator says `interaction`; the rules and the spec say
	 * `contactmoment`, so the listener translates.
	 *
	 * @return void
	 */
	public function testAClosedContactMomentMatchesAContactmomentRule(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'contactmoment', status: 'closed')]);
		$this->stored['contact-1'] = ['contactsUid' => 'uid-1', 'email' => 'jan@example.org'];
		$this->stored['ticket-1'] = $this->ticket(status: 'closed', ticketType: 'interaction');

		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(uuid: 'ticket-1', data: $this->stored['ticket-1']),
				$this->entity(uuid: 'ticket-1', data: $this->ticket(status: 'new', ticketType: 'interaction'))
			)
		);
		DeferredJobDrain::run($this, $this->deferral, $listener);

		$this->assertCount(1, $this->written);
		$this->assertSame('contactmoment', $this->written[0]['object']['linkedEntityType']);
	}//end testAClosedContactMomentMatchesAContactmomentRule()

	/**
	 * A save that leaves the status where it was queues nothing.
	 *
	 * Otherwise every edit of a completed ticket would ask the customer again.
	 *
	 * @return void
	 */
	public function testAnEditWithoutAStatusChangeQueuesNothing(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'request', status: 'completed')]);
		$data = $this->ticket(status: 'completed');

		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(uuid: 'ticket-1', data: $data),
				$this->entity(uuid: 'ticket-1', data: $data)
			)
		);

		$this->assertSame([], $this->deferral->entries);
	}//end testAnEditWithoutAStatusChangeQueuesNothing()

	/**
	 * A status no rule names queues nothing, so an instance without rules pays
	 * no background job per ticket save.
	 *
	 * @return void
	 */
	public function testAStatusNoRuleNamesQueuesNothing(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'request', status: 'completed')]);

		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(uuid: 'ticket-1', data: $this->ticket(status: 'in_progress')),
				$this->entity(uuid: 'ticket-1', data: $this->ticket(status: 'new'))
			)
		);

		$this->assertSame([], $this->deferral->entries);
	}//end testAStatusNoRuleNamesQueuesNothing()

	/**
	 * An object on another schema is ignored, whatever its status says.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'request', status: 'completed')]);

		$listener = $this->listener();
		$listener->handle(
			new ObjectUpdatedEvent(
				$this->entity(uuid: 'lead-1', data: $this->ticket(status: 'completed'), schema: 'sch-lead'),
				$this->entity(uuid: 'lead-1', data: $this->ticket(status: 'new'), schema: 'sch-lead')
			)
		);

		$this->assertSame([], $this->deferral->entries);
	}//end testAnotherSchemaIsIgnored()

	/**
	 * A ticket created already completed counts as reaching that status.
	 *
	 * @return void
	 */
	public function testATicketCreatedCompletedIsDispatched(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'request', status: 'completed')]);
		$this->stored['contact-1'] = ['contactsUid' => 'uid-1', 'email' => 'jan@example.org'];
		$this->stored['ticket-1'] = $this->ticket(status: 'completed');

		$listener = $this->listener();
		$listener->handle(new ObjectCreatedEvent($this->entity(uuid: 'ticket-1', data: $this->stored['ticket-1'])));
		DeferredJobDrain::run($this, $this->deferral, $listener);

		$this->assertCount(1, $this->written);
		$this->assertSame('scheduled', $this->written[0]['object']['status']);
	}//end testATicketCreatedCompletedIsDispatched()

	/**
	 * A redelivered job does not invite the same customer twice.
	 *
	 * Deferred delivery is at-least-once (ADR-078), so the deferred pass must
	 * reconcile against what is already on file.
	 *
	 * @return void
	 */
	public function testARedeliveredCompletionWritesNoSecondInvitation(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'request', status: 'completed')]);
		$this->stored['contact-1'] = ['contactsUid' => 'uid-1', 'email' => 'jan@example.org'];
		$this->stored['ticket-1'] = $this->ticket(status: 'completed');
		$this->invitations = [['linkedEntityId' => 'ticket-1', 'status' => 'scheduled']];

		$listener = $this->listener();
		$listener->runDeferredWork(
			[
				'handler' => SurveyDispatchListener::HANDLER_KEY,
				'uuid' => 'ticket-1',
				'schema' => self::TICKET_SCHEMA,
				'entityType' => 'request',
				'status' => 'completed',
			]
		);

		$this->assertSame([], $this->written);
	}//end testARedeliveredCompletionWritesNoSecondInvitation()

	/**
	 * A ticket that moved on before the job ran is left alone.
	 *
	 * @return void
	 */
	public function testATicketReopenedBeforeTheJobRanIsLeftAlone(): void {
		$this->rulesJson = json_encode([$this->rule(entityType: 'request', status: 'completed')]);
		$this->stored['contact-1'] = ['contactsUid' => 'uid-1', 'email' => 'jan@example.org'];
		$this->stored['ticket-1'] = $this->ticket(status: 'in_progress');

		$listener = $this->listener();
		$listener->runDeferredWork(
			[
				'handler' => SurveyDispatchListener::HANDLER_KEY,
				'uuid' => 'ticket-1',
				'schema' => self::TICKET_SCHEMA,
				'entityType' => 'request',
				'status' => 'completed',
			]
		);

		$this->assertSame([], $this->written);
	}//end testATicketReopenedBeforeTheJobRanIsLeftAlone()

	/**
	 * One enabled rule, as the settings surface persists it.
	 *
	 * @param string $entityType The trigger entity type.
	 * @param string $status The trigger status.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function rule(string $entityType, string $status): array {
		return [
			'id' => 'rule-1',
			'enabled' => true,
			'trigger' => ['entityType' => $entityType, 'statusEquals' => $status],
			'surveyRef' => 'survey-1',
			'channel' => 'email',
			'delayMinutes' => 0,
			'cooldownDays' => 30,
			'expiryDays' => 30,
		];
	}//end rule()

	/**
	 * A ticket's data.
	 *
	 * @param string $status The ticket status.
	 * @param string $ticketType The ticket discriminator.
	 *
	 * @return array<string, mixed> The ticket.
	 */
	private function ticket(string $status, string $ticketType = 'request'): array {
		return [
			'ticketType' => $ticketType,
			'title' => 'Parking permit',
			'status' => $status,
			'contact' => 'contact-1',
			'client' => 'client-1',
			'channel' => 'email',
		];
	}//end ticket()

	/**
	 * A real ObjectEntity, as OpenRegister hands one to a listener.
	 *
	 * @param string $uuid The object uuid.
	 * @param array<string, mixed> $data The object data.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(string $uuid, array $data, string $schema = self::TICKET_SCHEMA): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Build the listener over the real dispatch service.
	 *
	 * @return SurveyDispatchListener The listener under test.
	 */
	private function listener(): SurveyDispatchListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'reg-1',
					'contact_schema' => 'sch-contact',
					'surveyInvitation_schema' => 'sch-invitation',
					SurveyDispatchService::RULES_KEY => $this->rulesJson,
				];

				return ($values[$key] ?? $default);
			}
		);

		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('find')->willReturnCallback(
			function (int|string $id, ?array $_extend = [], bool $files = false, $register = null, $schema = null): ?ObjectEntity {
				if (isset($this->stored[(string)$id]) === false) {
					return null;
				}

				return $this->entity(uuid: (string)$id, data: $this->stored[(string)$id], schema: (string)$schema);
			}
		);
		$objectService->method('findAll')->willReturnCallback(
			function (array $config = []): array {
				$filters = ($config['filters'] ?? []);
				$out = [];
				foreach ($this->invitations as $row) {
					if (isset($filters['linkedEntityId']) === true
						&& ($row['linkedEntityId'] ?? null) !== $filters['linkedEntityId']
					) {
						continue;
					}

					if (isset($filters['contactRef']) === true
						&& ($row['contactRef'] ?? null) !== $filters['contactRef']
					) {
						continue;
					}

					$out[] = $row;
				}

				return $out;
			}
		);
		$objectService->method('saveObject')->willReturnCallback(
			function (array|object $object, ?array $_extend = [], $register = null, $schema = null, ?string $uuid = null): ObjectEntity {
				$this->written[] = ['object' => (array)$object, 'schema' => $schema];

				return new ObjectEntity();
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('token-1');

		$logger = $this->createMock(LoggerInterface::class);

		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturnCallback(
			static fn (?string $schemaId): ?string => ($schemaId === self::TICKET_SCHEMA ? 'ticket' : null)
		);

		$this->deferral = new RecordingDeferralService();

		return new SurveyDispatchListener(
			dispatchService: new SurveyDispatchService($appConfig, $objectService, $random, $logger),
			schemaMapService: $schemaMap,
			objectService: $objectService,
			appConfig: $appConfig,
			deferral: $this->deferral,
			logger: $logger,
		);
	}//end listener()
}//end class
