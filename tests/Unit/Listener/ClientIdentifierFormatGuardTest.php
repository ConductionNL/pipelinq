<?php

/**
 * Unit tests for ClientIdentifierFormatGuard.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Pipelinq\Kernel\Contracts\ClientIdentifierServiceInterface;
use OCA\Pipelinq\Listener\ClientIdentifierFormatGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A client save with a malformed identifier is refused; anything else passes.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */
#[CoversClass(ClientIdentifierFormatGuard::class)]
class ClientIdentifierFormatGuardTest extends TestCase {

	/**
	 * The identifiers lists the format rules were asked about.
	 *
	 * @var array<int, mixed>
	 */
	private array $asked = [];

	/**
	 * A guard whose format rules answer the given problems.
	 *
	 * @param array<int, string> $problems What problemsIn() answers.
	 *
	 * @return ClientIdentifierFormatGuard The guard.
	 */
	private function guard(array $problems): ClientIdentifierFormatGuard {
		$rules = $this->createMock(originalClassName: ClientIdentifierServiceInterface::class);
		$rules->method('problemsIn')->willReturnCallback(
			function (mixed $identifiers) use ($problems): array {
				$this->asked[] = $identifiers;
				return $problems;
			}
		);

		return new ClientIdentifierFormatGuard(
			identifiers: $rules,
			appConfig: $this->createConfiguredMock(originalClassName: IAppConfig::class, configuration: ['getValueString' => '7'])
		);
	}//end guard()

	/**
	 * An object of the given schema with the given data.
	 *
	 * @param string               $schema The schema id.
	 * @param array<string, mixed> $data   The data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(string $schema, array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * A malformed RSIN on an update is refused with the problem.
	 *
	 * @return void
	 */
	public function testAMalformedIdentifierOnAnUpdateIsRefused(): void {
		$identifiers = [['scheme' => 'rsin', 'value' => '123456789']];
		$event = new ObjectUpdatingEvent(newObject: $this->object(schema: '7', data: ['identifiers' => $identifiers]));

		$this->guard(problems: ['The RSIN 123456789 is not valid: it fails the eleven test.'])->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: 'The RSIN 123456789 is not valid: it fails the eleven test.', actual: $event->getErrors()['message']);
		$this->assertSame(expected: [$identifiers], actual: $this->asked);
	}//end testAMalformedIdentifierOnAnUpdateIsRefused()

	/**
	 * A create is checked on what it will store, the listener-modified data included.
	 *
	 * @return void
	 */
	public function testACreateIsCheckedOnItsModifiedData(): void {
		$event = new ObjectCreatingEvent($this->object(schema: '7', data: ['name' => 'Bakkerij']));
		$event->setModifiedData(['identifiers' => [['scheme' => 'kvk', 'value' => '1234']]]);

		$this->guard(problems: ['A KvK number has 8 digits.'])->handle($event);

		$this->assertTrue(condition: $event->isPropagationStopped());
		$this->assertSame(expected: ['A KvK number has 8 digits.'], actual: $event->getErrors()['identifiers']);
		$this->assertSame(expected: [[['scheme' => 'kvk', 'value' => '1234']]], actual: $this->asked);
	}//end testACreateIsCheckedOnItsModifiedData()

	/**
	 * Valid identifiers pass.
	 *
	 * @return void
	 */
	public function testValidIdentifiersPass(): void {
		$event = new ObjectUpdatingEvent(newObject: $this->object(
			schema: '7',
			data: ['identifiers' => [['scheme' => 'kvk', 'value' => '12345678']]]
		));

		$this->guard(problems: [])->handle($event);

		$this->assertFalse(condition: $event->isPropagationStopped());
		$this->assertSame(expected: [], actual: $event->getErrors());
	}//end testValidIdentifiersPass()

	/**
	 * Another schema, or a client without identifiers, is not looked at.
	 *
	 * @return void
	 */
	public function testOtherObjectsAreNotLookedAt(): void {
		$lead = new ObjectUpdatingEvent(newObject: $this->object(schema: '8', data: ['identifiers' => 'x']));
		$client = new ObjectUpdatingEvent(newObject: $this->object(schema: '7', data: ['name' => 'Bakkerij']));

		$guard = $this->guard(problems: ['never']);
		$guard->handle($lead);
		$guard->handle($client);

		$this->assertFalse(condition: $lead->isPropagationStopped());
		$this->assertFalse(condition: $client->isPropagationStopped());
		$this->assertSame(expected: [], actual: $this->asked);
	}//end testOtherObjectsAreNotLookedAt()
}//end class
