<?php

/**
 * Unit tests for SubmittedLeadListener.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/marketing-campaigns/spec.md#requirement-a-landing-page-form-submits-into-a-lead-with-its-contact-and-touchpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Listener\SubmittedLeadListener;
use OCA\Pipelinq\Service\Marketing\SubmittedLeadIntake;
use OCA\Pipelinq\Service\TouchpointService;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryListObjectStore;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/../Support/InMemoryListObjectStore.php';

/**
 * People before the lead is stored, the touchpoint after; only for leads, never blocking.
 */
class SubmittedLeadListenerTest extends TestCase {

	private InMemoryListObjectStore $store;

	/**
	 * The listener over the real intake and an in-memory store; the lead schema id is 30.
	 */
	private function listener(?SubmittedLeadIntake $intake = null, ?LoggerInterface $logger = null): SubmittedLeadListener {
		$this->store = new InMemoryListObjectStore();
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'lead_schema') ? '30' : $default
		);

		return new SubmittedLeadListener(
			$config,
			($intake ?? new SubmittedLeadIntake($this->store, new TouchpointService($this->store))),
			($logger ?? $this->createMock(LoggerInterface::class))
		);
	}//end listener()

	/**
	 * An entity of a schema.
	 *
	 * @param array<string, mixed> $data The object data.
	 */
	private static function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('lead-1');
		$entity->setSchema($schema);
		$entity->setObject($data);

		return $entity;
	}//end entity()

	/**
	 * Before the lead is stored it gets its contact, keeping what an earlier listener set.
	 */
	public function testALeadBeingCreatedGetsItsContact(): void {
		$event = new ObjectCreatingEvent(self::entity('30', ['title' => 'Webinar', 'submitterEmail' => 'jane@example.com', 'submitterName' => 'Jane']));
		$event->setModifiedData(['stage' => 'New']);

		$this->listener()->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame('New', $modified['stage'], 'An earlier listener\'s change must survive.');
		$this->assertSame('jane@example.com', $this->store->find(schemaSlug: 'contact', id: $modified['contact'])['email']);
		$this->assertSame('Webinar: Jane', $modified['title']);
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'touchpoint'), 'The touchpoint needs the stored lead\'s id.');
	}//end testALeadBeingCreatedGetsItsContact()

	/**
	 * A value an earlier listener set counts as the lead's own: a contact set there is kept.
	 */
	public function testAnEarlierListenersContactIsKept(): void {
		$event = new ObjectCreatingEvent(self::entity('30', ['title' => 'Jane', 'submitterEmail' => 'jane@example.com', 'submitterName' => 'Jane']));
		$event->setModifiedData(['contact' => 'contact-9']);

		$this->listener()->handle($event);

		$this->assertSame(['contact' => 'contact-9'], $event->getModifiedData());
		$this->assertSame(0, $this->store->countOf(schemaSlug: 'contact'));
	}//end testAnEarlierListenersContactIsKept()

	/**
	 * Once the lead is stored its submit touchpoint is appended, with the lead's real id.
	 */
	public function testAStoredLeadGetsItsTouchpoint(): void {
		$listener = $this->listener();

		$listener->handle(new ObjectCreatedEvent(self::entity('30', ['campaignId' => 'camp-1', 'nonce' => 'nonce-1', 'contact' => 'contact-1'])));

		$rows = $this->store->findAll(schemaSlug: 'touchpoint');
		$this->assertCount(1, $rows);
		$this->assertSame('lead-1', $rows[0]['leadId']);
		$this->assertSame('submit', $rows[0]['kind']);
	}//end testAStoredLeadGetsItsTouchpoint()

	/**
	 * Other schemas, other events and an unconfigured lead schema are not touched.
	 */
	public function testOnlyLeadsAreTouched(): void {
		$intake = $this->createMock(SubmittedLeadIntake::class);
		$intake->expects($this->never())->method('partiesFor');
		$intake->expects($this->never())->method('recordTouchpoint');
		$listener = $this->listener(intake: $intake);

		$listener->handle(new ObjectCreatingEvent(self::entity('99', ['submitterEmail' => 'jane@example.com'])));
		$listener->handle(new ObjectCreatedEvent(self::entity('99', ['nonce' => 'nonce-1'])));
		$listener->handle(new Event());

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with(Application::APP_ID, 'lead_schema', '')->willReturn('');
		(new SubmittedLeadListener($config, $intake, $this->createMock(LoggerInterface::class)))
			->handle(new ObjectCreatingEvent(self::entity('', ['submitterEmail' => 'jane@example.com'])));
	}//end testOnlyLeadsAreTouched()

	/**
	 * A failure is logged and never blocks the lead: the submission must not be lost.
	 */
	public function testAFailureNeverBlocksTheLead(): void {
		$intake = $this->createMock(SubmittedLeadIntake::class);
		$intake->method('partiesFor')->willThrowException(new RuntimeException('contacts unreachable'));
		$intake->method('recordTouchpoint')->willThrowException(new RuntimeException('touchpoints unreachable'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning');
		$listener = $this->listener(intake: $intake, logger: $logger);
		$creating = new ObjectCreatingEvent(self::entity('30', ['submitterEmail' => 'jane@example.com']));

		$listener->handle($creating);
		$listener->handle(new ObjectCreatedEvent(self::entity('30', ['nonce' => 'nonce-1'])));

		$this->assertSame([], $creating->getModifiedData());
		$this->assertSame([], $creating->getErrors());
	}//end testAFailureNeverBlocksTheLead()

	/**
	 * The listener is wired to both events; a listener nothing registers never runs.
	 */
	public function testTheListenerIsRegisteredForBothEvents(): void {
		$source = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/AppInfo/Application.php');

		foreach (['ObjectCreatingEvent', 'ObjectCreatedEvent'] as $event) {
			$this->assertMatchesRegularExpression(
				'/registerEventListener\(\s*event: ' . $event . '::class,\s*listener: SubmittedLeadListener::class\s*\)/',
				$source,
				'SubmittedLeadListener is not registered for ' . $event . '.'
			);
		}
	}//end testTheListenerIsRegisteredForBothEvents()
}//end class
