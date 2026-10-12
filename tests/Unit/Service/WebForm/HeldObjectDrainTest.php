<?php

/**
 * Unit tests for HeldObjectDrain.
 *
 * The held enquiries are shaped as the real `enquiry` schema stores them, the
 * forms are the shipped seed forms, and the submit goes through the real
 * WebFormIntakeService into a fake with FormSubmitService's signature.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\WebForm
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-held-website-messages-are-drained-into-the-destination-their-form-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\Pipelinq\Service\SystemServiceAccount;
use OCA\Pipelinq\Service\WebForm\HeldObjectDrain;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeContainer;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeFormSubmitService;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeObjectStore;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\ObjectServiceContract;
use OCA\Pipelinq\Tests\Unit\Service\WebForm\Fakes\FakeRefusal;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Every open enquiry is delivered where its form sends, refused, or reported unmapped; nothing is guessed.
 */
class HeldObjectDrainTest extends TestCase {

	use ObjectServiceContract;

	/**
	 * The store of the last built drain.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The submit fake of the last built drain.
	 *
	 * @var FakeFormSubmitService
	 */
	private FakeFormSubmitService $submitter;

	/**
	 * Four held enquiries: two mapped, one with an unknown source, one already converted.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private static function enquiries(): array {
		return [
			['@self' => ['id' => 'e-1'], 'title' => 'Login broken', 'message' => 'Cannot log in', 'contactEmail' => 'a@example.com', 'source' => 'website-support', 'status' => 'new', 'receivedAt' => '2026-10-01T10:00:00+00:00'],
			['@self' => ['id' => 'e-2'], 'title' => 'Reseller', 'organisation' => 'Acme', 'contactEmail' => 'b@acme.example', 'source' => 'website-partner', 'status' => 'new'],
			['@self' => ['id' => 'e-3'], 'title' => 'Job', 'message' => 'Hiring?', 'source' => 'website-careers', 'status' => 'new'],
			['@self' => ['id' => 'e-4'], 'title' => 'Old', 'message' => 'Done', 'source' => 'website-support', 'status' => 'converted'],
		];
	}//end enquiries()

	/**
	 * The drain over the shipped forms and the held enquiries.
	 *
	 * @param Throwable|null $submitThrow What the submit throws, when set.
	 * @param Throwable|null $deleteThrow What the delete throws, when set.
	 *
	 * @return HeldObjectDrain The drain.
	 */
	private function drain(?Throwable $submitThrow = null, ?Throwable $deleteThrow = null): HeldObjectDrain {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '20', 'webForm_schema' => '40'][$key] ?? $default
		);
		$this->store = new FakeObjectStore(rows: ['40' => WebFormIntakeServiceTest::shippedForms(), '26' => self::enquiries()], deleteThrow: $deleteThrow);
		$this->submitter = new FakeFormSubmitService(throw: $submitThrow);
		$container = new FakeContainer(
			services: [
				'OCA\OpenRegister\Service\Form\FormSubmitService' => $this->submitter,
			]
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));
		$account = new SystemServiceAccount($users, $this->createMock(IUserSession::class), $this->createMock(ISecureRandom::class));
		$finder = new WebFormFinder($config, $this->contractOver($this->store));

		return new HeldObjectDrain(
			$this->contractOver($this->store),
			$finder,
			new WebFormIntakeService($finder, $container, $account, $this->createMock(LoggerInterface::class)),
			$account
		);
	}//end drain()

	/**
	 * Without apply nothing is written; the report says where each open enquiry would go.
	 *
	 * @return void
	 */
	public function testDryRunWritesNothing(): void {
		$report = $this->drain()->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: false);

		$this->assertSame([], $this->submitter->calls);
		$this->assertSame([], $this->store->deleted);
		$this->assertSame(['e-1', 'e-2'], array_column($report['delivered'], 'id'));
		$this->assertSame(['ticket', 'ticket'], array_column($report['delivered'], 'destination'));
		$this->assertSame([['id' => 'e-3', 'key' => 'website-careers']], $report['unmapped']);
		$this->assertSame(3, $report['pending']);
	}//end testDryRunWritesNothing()

	/**
	 * With apply each mapped enquiry is submitted into its form's destination and then deleted; the converted one is left.
	 *
	 * @return void
	 */
	public function testApplyDeliversAndRemoves(): void {
		$report = $this->drain()->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: true);

		$this->assertCount(2, $this->submitter->calls);
		$this->assertSame('drain:e-1', $this->submitter->calls[0]['idempotencyKey']);
		$this->assertSame('Cannot log in', $this->submitter->calls[0]['payload']['message']);
		$this->assertArrayNotHasKey('@self', $this->submitter->calls[0]['payload']);
		$this->assertSame('website', $this->submitter->calls[0]['writes'][0]['mapping']['fixed']['channel']);
		$this->assertSame(['e-1', 'e-2'], array_column($this->store->deleted, 'uuid'));
		$this->assertTrue($this->store->deleted[0]['rbac']);
		$this->assertSame(['TICKET-1', 'TICKET-2'], array_column($report['delivered'], 'reference'));
		$this->assertSame(1, $report['pending'], 'Only the unmapped enquiry is still held');
	}//end testApplyDeliversAndRemoves()

	/**
	 * A refused enquiry is reported with its codes and stays held.
	 *
	 * @return void
	 */
	public function testRefusedStaysHeld(): void {
		$drain = $this->drain(submitThrow: new FakeRefusal('Refused.', 422, [['property' => 'reporterEmail', 'code' => 'format']]));
		$report = $drain->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: true);

		$this->assertSame([], $report['delivered']);
		$this->assertSame(['e-1', 'e-2'], array_column($report['refused'], 'id'));
		$this->assertSame(['format'], $report['refused'][0]['codes']);
		$this->assertSame(422, $report['refused'][0]['status']);
		$this->assertSame([], $this->store->deleted);
		$this->assertSame(3, $report['pending']);
	}//end testRefusedStaysHeld()

	/**
	 * Delivered but not deleted still counts as pending, so the schema is not removed over it.
	 *
	 * @return void
	 */
	public function testDeliveredButNotRemovedIsPending(): void {
		$report = $this->drain(deleteThrow: new RuntimeException('locked'))
			->drain(register: '20', schema: '26', keyProperty: 'source', openFilter: ['status' => 'new'], apply: true);

		$this->assertSame('locked', $report['delivered'][0]['notRemoved']);
		$this->assertSame(3, $report['pending']);
	}//end testDeliveredButNotRemovedIsPending()
}//end class
