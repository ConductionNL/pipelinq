<?php

/**
 * Contact erasure keeps the opt-out in integriq (REQ-CII-005).
 *
 * Wired from the caller: OpenRegister's ObjectDeletedEvent reaches
 * ContactErasedListener, which calls ClientManagementIntegration with a real
 * ConsentService and IntegriqConsentClient over a fake integriq.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-contact-erasure-keeps-the-opt-out-in-integriq-req-cii-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\Pipelinq\Listener\ContactErasedListener;
use OCA\Pipelinq\Service\ClientManagementIntegration;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\SchemaMapService;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * An erased contact's address stays opted out.
 */
class ClientManagementIntegrationTest extends TestCase {

	public function testAnErasedContactIsStillNotMailed(): void {
		$integriq = new FakeIntegriq();
		$integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-out', 'scope' => 'channel', 'channel' => 'email', 'contactRef' => 'c-1', 'evidence' => ['text' => 'unsubscribe link']]);
		$store = new InMemoryObjectService();
		$store->put('messagingConsentRecord', ['contactId' => 'c-1', 'channel' => 'sms', 'state' => 'opted-in', 'recordedAt' => '2026-09-01T00:00:00Z']);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($store);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = '') => $d);
		$logger = new NullLogger();
		$client = FakeIntegriq::client($appConfig, $integriq);
		$consent = new ConsentService($container, $appConfig, $logger, $client, new ContactAddressLookup($container, $appConfig, $logger));
		$integration = new ClientManagementIntegration($consent, $logger, $client);

		$schemaMap = $this->createMock(SchemaMapService::class);
		$schemaMap->method('resolveEntityType')->willReturnCallback(static fn (?string $id) => ($id === '17' ? 'contact' : 'lead'));
		$listener = new ContactErasedListener($integration, $schemaMap);

		$lead = new ObjectEntity();
		$lead->setUuid('l-1');
		$lead->setSchema('99');
		$listener->handle(new ObjectDeletedEvent($lead));
		self::assertSame([], $integriq->changeEvents, 'a deleted lead is not a contact erasure');

		$contact = new ObjectEntity();
		$contact->setUuid('c-1');
		$contact->setSchema('17');
		$listener->handle(new ObjectDeletedEvent($contact));

		$request = $integriq->changeEvents[0]->toRequest();
		self::assertSame('erase-contact', $request['state']);
		self::assertSame('c-1', $request['contactRef']);
		self::assertSame([], $store->ofSchema('messagingConsentRecord'), 'pipelinq history is still deleted');

		$row = $integriq->rowsFor('jan@example.nl')[0];
		self::assertSame('opted-out', $row['state']);
		self::assertSame('', $row['contactRef']);
		self::assertSame([], $row['evidence']);

		// A new contact with the same address is still refused a service mail.
		$decision = $client->decideOne(channel: 'email', category: 'service', requiresConsent: false, address: 'jan@example.nl', contactRef: 'c-9');
		self::assertFalse($decision['send']);
		self::assertSame('opted-out', $decision['code']);
	}//end testAnErasedContactIsStillNotMailed()
}//end class
