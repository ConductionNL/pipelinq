<?php

/**
 * Verifies the node that resolves the addressbook identity a client or contact needs.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Flow
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/website-enquiry-intake/specs/website-enquiry-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Flow;

use OCA\Pipelinq\Flow\PipelinqFlowNodeListener;
use OCA\Pipelinq\Flow\ProvisionContactIdentityNode;
use OCA\Pipelinq\Service\ContactVcardService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

/**
 * This node exists because `contactsUid` is REQUIRED on client and contact and
 * its own description says it is "never minted locally". These tests hold that
 * line: the node must ask the vCard service, and must refuse rather than
 * invent a value or pass the item along without one.
 */
class ProvisionContactIdentityNodeTest extends TestCase {

	private ContactVcardService&MockObject $vcards;

	private ProvisionContactIdentityNode $node;

	/**
	 * Build the node with a mocked vCard service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->vcards = $this->createMock(ContactVcardService::class);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app.svg');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$this->node = new ProvisionContactIdentityNode($this->vcards, $urls);
	}//end setUp()

	/**
	 * One item carrying the given record.
	 *
	 * @param array<string, mixed> $json The record.
	 *
	 * @return array<int, array> One item.
	 */
	private function items(array $json): array {
		return [['json' => $json]];
	}

	/**
	 * The type string the shipped flow names. A rename here silently unwires
	 * every flow declaring the old one.
	 *
	 * @return void
	 */
	public function testIdentifiesItselfAsTheTypeTheShippedFlowNames(): void {
		$this->assertSame('pipelinq.provision-contact-identity', $this->node->getId());
	}//end testIdentifiesItselfAsTheTypeTheShippedFlowNames()

	/**
	 * Without registration the node is unreachable and a flow naming it is
	 * refused at save.
	 *
	 * @return void
	 */
	public function testTheListenerContributesTheNode(): void {
		$this->assertContains(
			ProvisionContactIdentityNode::class,
			PipelinqFlowNodeListener::nodeClasses()
		);
	}//end testTheListenerContributesTheNode()

	/**
	 * The uid comes from the vCard service and is written to the configured key.
	 *
	 * @return void
	 */
	public function testPutsTheResolvedUidOnTheItem(): void {
		$this->vcards->expects($this->once())
			->method('provisionContactFromForm')
			->willReturn(['contactsUid' => 'uid-123', 'name' => 'Acme BV', 'email' => '', 'phone' => '']);

		$out = $this->node->execute(
			$this->items(['organisation' => 'Acme BV']),
			['objectType' => 'client', 'nameFrom' => 'organisation', 'output' => 'clientContactsUid'],
			[]
		);

		$this->assertSame('uid-123', $out[0]['json']['clientContactsUid']);
		$this->assertSame('Acme BV', $out[0]['json']['organisation'], 'the incoming record survives');
	}//end testPutsTheResolvedUidOnTheItem()

	/**
	 * A client is an ORGANISATION to the matcher, which is what makes it match
	 * on ORG rather than falling through to a person.
	 *
	 * @return void
	 */
	public function testAClientIsProvisionedAsAnOrganisation(): void {
		$seen = [];
		$this->vcards->method('provisionContactFromForm')
			->willReturnCallback(function (array $form, string $type) use (&$seen): array {
				$seen = ['form' => $form, 'type' => $type];

				return ['contactsUid' => 'uid-1', 'name' => '', 'email' => '', 'phone' => ''];
			});

		$this->node->execute(
			$this->items(['organisation' => 'Acme BV']),
			['objectType' => 'client', 'nameFrom' => 'organisation', 'output' => 'u'],
			[]
		);

		$this->assertSame('organization', $seen['form']['type']);
		$this->assertSame('client', $seen['type']);
	}//end testAClientIsProvisionedAsAnOrganisation()

	/**
	 * A contact is a person, so it matches on email.
	 *
	 * @return void
	 */
	public function testAContactIsProvisionedAsAPerson(): void {
		$seen = [];
		$this->vcards->method('provisionContactFromForm')
			->willReturnCallback(function (array $form, string $type) use (&$seen): array {
				$seen = ['form' => $form, 'type' => $type];

				return ['contactsUid' => 'uid-2', 'name' => '', 'email' => '', 'phone' => ''];
			});

		$this->node->execute(
			$this->items(['contactName' => 'Jane Doe', 'contactEmail' => 'jane@x.test']),
			[
				'objectType' => 'contact',
				'nameFrom' => 'contactName',
				'emailFrom' => 'contactEmail',
				'output' => 'u',
			],
			[]
		);

		$this->assertSame('person', $seen['form']['type']);
		$this->assertSame('jane@x.test', $seen['form']['email']);
	}//end testAContactIsProvisionedAsAPerson()

	/**
	 * 🔴 The regression that shipped silently on a live rig: provisioning the
	 * COMPANY with the person's email made the matcher search EMAIL first and
	 * return the PERSON's vCard, so client and contact came back with the same
	 * uid and one addressbook identity stood for both. The company identity is
	 * matched on its name; a field the config does not name is never invented.
	 *
	 * @return void
	 */
	public function testDoesNotSendAnEmailItWasNotGiven(): void {
		$seen = [];
		$this->vcards->method('provisionContactFromForm')
			->willReturnCallback(function (array $form) use (&$seen): array {
				$seen = $form;

				return ['contactsUid' => 'uid-3', 'name' => '', 'email' => '', 'phone' => ''];
			});

		$this->node->execute(
			// The record HOLDS an email; the config does not name it.
			$this->items(['organisation' => 'Acme BV', 'contactEmail' => 'jane@x.test']),
			['objectType' => 'client', 'nameFrom' => 'organisation', 'output' => 'u'],
			[]
		);

		$this->assertSame('', $seen['email'], 'an unnamed field must not reach the matcher');
		$this->assertSame('Acme BV', $seen['name']);
	}//end testDoesNotSendAnEmailItWasNotGiven()

	/**
	 * Null means Contacts was unavailable or the write failed. Carrying on
	 * would hand the next write an item with no uid and a validation error
	 * naming the wrong thing.
	 *
	 * @return void
	 */
	public function testThrowsWhenTheIdentityCannotBeProvisioned(): void {
		$this->vcards->method('provisionContactFromForm')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->node->execute(
			$this->items(['organisation' => 'Acme BV']),
			['objectType' => 'client', 'nameFrom' => 'organisation', 'output' => 'u'],
			[]
		);
	}//end testThrowsWhenTheIdentityCannotBeProvisioned()

	/**
	 * An empty uid is the same failure wearing a different shape, and a blank
	 * string would pass a naive truthiness check downstream.
	 *
	 * @return void
	 */
	public function testThrowsOnAnEmptyUid(): void {
		$this->vcards->method('provisionContactFromForm')
			->willReturn(['contactsUid' => '  ', 'name' => '', 'email' => '', 'phone' => '']);

		$this->expectException(RuntimeException::class);
		$this->node->execute(
			$this->items(['organisation' => 'Acme BV']),
			['objectType' => 'client', 'nameFrom' => 'organisation', 'output' => 'u'],
			[]
		);
	}//end testThrowsOnAnEmptyUid()

	/**
	 * Nothing to identify anyone by is refused before Contacts is touched.
	 *
	 * @return void
	 */
	public function testRefusesAnItemWithNoNameAndNoEmail(): void {
		$this->vcards->expects($this->never())->method('provisionContactFromForm');

		$this->expectException(RuntimeException::class);
		$this->node->execute(
			$this->items(['message' => 'hello']),
			['objectType' => 'client', 'nameFrom' => 'organisation', 'output' => 'u'],
			[]
		);
	}//end testRefusesAnItemWithNoNameAndNoEmail()

	/**
	 * ContactVcardService only provisions for client and contact.
	 *
	 * @return void
	 */
	public function testRefusesAnUnknownObjectType(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['objectType' => 'lead', 'nameFrom' => 'x', 'output' => 'u']);
	}//end testRefusesAnUnknownObjectType()

	/**
	 * Without an output key the uid has nowhere to go and the next step fails
	 * on a missing property instead of on the real cause.
	 *
	 * @return void
	 */
	public function testRefusesAMissingOutputKey(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig(['objectType' => 'client', 'nameFrom' => 'organisation']);
	}//end testRefusesAMissingOutputKey()

	/**
	 * `validateConfig()` only runs on SAVE, and a flow materialised from an
	 * `x-openregister-flows` declaration reaches `execute()` unvalidated, so
	 * execute must check again rather than assume.
	 *
	 * @return void
	 */
	public function testExecuteRevalidatesTheConfig(): void {
		$this->vcards->expects($this->never())->method('provisionContactFromForm');

		$this->expectException(UnexpectedValueException::class);
		$this->node->execute($this->items(['organisation' => 'Acme BV']), ['objectType' => 'nonsense'], []);
	}//end testExecuteRevalidatesTheConfig()

	/**
	 * The palette entry is filled in, or the builder shows a nameless step.
	 *
	 * @return void
	 */
	public function testThePaletteEntryIsFilledIn(): void {
		$this->assertNotSame('', trim($this->node->getDisplayName()));
		$this->assertNotSame('', trim($this->node->getDescription()));
		$this->assertNotSame('', trim($this->node->getIcon()));
	}//end testThePaletteEntryIsFilledIn()
}//end class
