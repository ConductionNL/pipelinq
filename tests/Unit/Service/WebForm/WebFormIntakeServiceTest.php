<?php

/**
 * Unit tests for WebFormIntakeService.
 *
 * The forms are read through the real WebFormFinder over an in-memory store;
 * the submit goes to a fake with OpenRegister FormSubmitService's signature
 * that records what it was handed. The seed forms are the real ones from
 * lib/Settings/register.d/26-web-form.json, so the tests exercise what ships.
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
 * @spec openspec/changes/website-forms-submit-into-tickets/specs/website-enquiry-intake/spec.md#requirement-a-website-form-submits-into-the-destination-it-names
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\WebForm;

use OCA\Pipelinq\Service\SystemServiceAccount;
use OCA\Pipelinq\Service\WebForm\WebFormFinder;
use OCA\Pipelinq\Service\WebForm\WebFormIntakeService;
use OCA\Pipelinq\Service\WebForm\WebFormSubmitException;
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

/**
 * A submit creates the object its form names, or is refused, and never writes an enquiry.
 */
class WebFormIntakeServiceTest extends TestCase {

	use ObjectServiceContract;

	/**
	 * The webForm schema id in this test's app config.
	 */
	private const FORM_SCHEMA = '40';

	/**
	 * The submit fake of the last built service.
	 *
	 * @var FakeFormSubmitService
	 */
	private FakeFormSubmitService $submitter;

	/**
	 * The store of the last built service.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The system account the service writes as.
	 *
	 * @var IUser
	 */
	private IUser $systemUser;

	/**
	 * The seed forms that ship, plus a lead form, as stored rows.
	 *
	 * @return array<int, array<string, mixed>> The forms.
	 */
	public static function shippedForms(): array {
		$fragment = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/26-web-form.json'),
			true
		);
		$forms = [];
		foreach ($fragment['components']['objects'] as $index => $object) {
			$self = $object['@self'];
			unset($object['@self']);
			$object['@self'] = ['id' => 'form-' . $index, 'slug' => $self['slug']];
			$forms[] = $object;
		}

		$forms[] = [
			'@self' => ['id' => 'form-lead'],
			'title' => 'Partner programme',
			'key' => 'partner-programme',
			'status' => 'published',
			'destination' => ['register' => 'pipelinq', 'schema' => 'lead'],
			'mapping' => [
				'fields' => [
					['field' => 'title', 'property' => 'title', 'type' => 'text'],
					['field' => 'message', 'property' => 'message', 'type' => 'textarea'],
				],
				'fixed' => ['source' => 'website'],
			],
		];
		$forms[] = [
			'@self' => ['id' => 'form-draft'],
			'title' => 'Half made',
			'key' => 'half-made',
			'status' => 'draft',
			'destination' => ['register' => 'pipelinq', 'schema' => 'ticket'],
		];

		return $forms;
	}//end shippedForms()

	/**
	 * The service over the shipped forms.
	 *
	 * @param \Throwable|null $throw What the submit throws, when set.
	 *
	 * @return WebFormIntakeService The service.
	 */
	private function service(?\Throwable $throw = null): WebFormIntakeService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => '20', 'webForm_schema' => self::FORM_SCHEMA][$key] ?? $default
		);
		$this->store = new FakeObjectStore(rows: [self::FORM_SCHEMA => self::shippedForms()]);
		$this->submitter = new FakeFormSubmitService(throw: $throw);
		$container = new FakeContainer(
			services: [
				'OCA\OpenRegister\Service\Form\FormSubmitService' => $this->submitter,
			]
		);

		$this->systemUser = $this->createMock(IUser::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->systemUser);
		$account = new SystemServiceAccount($users, $this->createMock(IUserSession::class), $this->createMock(ISecureRandom::class));

		return new WebFormIntakeService(
			new WebFormFinder($config, $this->contractOver($this->store)),
			$container,
			$account,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * The support form creates a ticket with channel website, and answers with its reference.
	 *
	 * @return void
	 */
	public function testSupportFormCreatesATicket(): void {
		$answer = $this->service()->submitByKey(
			key: 'website-support',
			payload: ['title' => 'Login broken', 'message' => 'I cannot log in.', 'contactEmail' => 'jane@example.com', 'source' => 'website-support']
		);

		$this->assertSame('TICKET-1', $answer['reference']);
		$this->assertSame('2026-10-11T09:00:00+00:00', $answer['receivedAt']);
		$this->assertCount(1, $this->submitter->calls);
		$call = $this->submitter->calls[0];
		$this->assertSame('ticket', $call['writes'][0]['schema']);
		$this->assertSame('website', $call['writes'][0]['mapping']['fixed']['channel']);
		$this->assertSame('request', $call['writes'][0]['mapping']['fixed']['ticketType']);
		$this->assertSame('jane@example.com', $call['payload']['contactEmail']);
		$this->assertSame('pipelinq-web-form:form-0', $call['scope']);
		$this->assertTrue($call['subject'] === $this->systemUser, 'The submit is written as pipelinq\'s system account');
	}//end testSupportFormCreatesATicket()

	/**
	 * No enquiry is written: the only write is the one submit, into the form's destination.
	 *
	 * @return void
	 */
	public function testNoEnquiryIsWritten(): void {
		$this->service()->submitByKey(key: 'website-contact', payload: ['message' => 'Hello']);

		foreach ($this->submitter->calls as $call) {
			foreach ($call['writes'] as $write) {
				$this->assertNotSame('enquiry', $write['schema']);
			}
		}

		$this->assertSame([], $this->store->deleted);
	}//end testNoEnquiryIsWritten()

	/**
	 * A form whose destination is lead creates a lead, and nothing else.
	 *
	 * @return void
	 */
	public function testLeadFormCreatesALead(): void {
		$answer = $this->service()->submitByKey(key: 'partner-programme', payload: ['title' => 'Reseller', 'message' => 'We want to resell.']);

		$this->assertSame('LEAD-1', $answer['reference']);
		$this->assertCount(1, $this->submitter->calls);
		$this->assertSame(['lead'], array_column($this->submitter->calls[0]['writes'], 'schema'));
	}//end testLeadFormCreatesALead()

	/**
	 * A filled honeypot stores nothing and the answer carries no reference.
	 *
	 * @return void
	 */
	public function testFilledHoneypotStoresNothing(): void {
		$service = $this->service();
		try {
			$service->submitByKey(key: 'website-support', payload: ['message' => 'Buy now', 'website' => 'http://spam.example']);
			$this->fail('A filled honeypot must be refused');
		} catch (WebFormSubmitException $e) {
			$this->assertSame(400, $e->getStatus());
			$this->assertSame(WebFormIntakeService::REFUSED, $e->getMessage());
		}

		$this->assertSame([], $this->submitter->calls);
	}//end testFilledHoneypotStoresNothing()

	/**
	 * The control for the honeypot test: the same submit with the honeypot empty is accepted.
	 *
	 * @return void
	 */
	public function testEmptyHoneypotIsAccepted(): void {
		$this->service()->submitByKey(key: 'website-support', payload: ['message' => 'Buy now', 'website' => '']);

		$this->assertCount(1, $this->submitter->calls);
	}//end testEmptyHoneypotIsAccepted()

	/**
	 * A key with no published form is refused with the same message as every refusal; a draft form does not count.
	 *
	 * @return void
	 */
	public function testUnknownOrDraftKeyIsRefused(): void {
		foreach (['website-careers', 'half-made', ''] as $key) {
			try {
				$this->service()->submitByKey(key: $key, payload: ['message' => 'Hi']);
				$this->fail('Key ' . $key . ' must be refused');
			} catch (WebFormSubmitException $e) {
				$this->assertSame(400, $e->getStatus());
				$this->assertSame(WebFormIntakeService::REFUSED, $e->getMessage());
			}

			$this->assertSame([], $this->submitter->calls);
		}
	}//end testUnknownOrDraftKeyIsRefused()

	/**
	 * A submit without a message and without an email address gives nobody anything to act on.
	 *
	 * @return void
	 */
	public function testEmptySubmitIsRefused(): void {
		$service = $this->service();
		$this->expectException(WebFormSubmitException::class);
		try {
			$service->submitByKey(key: 'website-support', payload: ['contactName' => 'Jane', 'message' => '   ']);
		} finally {
			$this->assertSame([], $this->submitter->calls);
		}
	}//end testEmptySubmitIsRefused()

	/**
	 * An answer over the limit is refused whole, never cut.
	 *
	 * @return void
	 */
	public function testOversizedAnswerIsRefused(): void {
		$service = $this->service();
		$this->expectException(WebFormSubmitException::class);
		try {
			$service->submitByKey(key: 'website-support', payload: ['message' => str_repeat('a', 20001)]);
		} finally {
			$this->assertSame([], $this->submitter->calls);
		}
	}//end testOversizedAnswerIsRefused()

	/**
	 * A blank title is filled from the organisation, as the form's fallbacks say; a nested shape is dropped.
	 *
	 * @return void
	 */
	public function testFallbackFillsTheTitleAndNestedShapesAreDropped(): void {
		$this->service()->submitByKey(
			key: 'website-partner',
			payload: ['organisation' => 'Acme BV', 'contactEmail' => 'a@acme.example', 'contactPhone' => ['x' => ['y']]]
		);

		$payload = $this->submitter->calls[0]['payload'];
		$this->assertSame('Acme BV', $payload['title']);
		$this->assertArrayNotHasKey('contactPhone', $payload);
	}//end testFallbackFillsTheTitleAndNestedShapesAreDropped()

	/**
	 * The Idempotency-Key reaches OpenRegister, which repeats the first answer for a retry.
	 *
	 * @return void
	 */
	public function testIdempotencyKeyIsPassedOn(): void {
		$this->service()->submitByKey(key: 'website-support', payload: ['message' => 'Hi'], idempotencyKey: 'abc-123');

		$this->assertSame('abc-123', $this->submitter->calls[0]['idempotencyKey']);
	}//end testIdempotencyKeyIsPassedOn()

	/**
	 * A payload the destination refuses answers 422 with OpenRegister's findings.
	 *
	 * @return void
	 */
	public function testRefusedPayloadKeepsItsFindings(): void {
		$findings = [['property' => 'reporterEmail', 'code' => 'format', 'message' => 'Not an email address.']];
		try {
			$this->service(throw: new FakeRefusal('Please check the answers.', 422, $findings))
				->submitByKey(key: 'website-support', payload: ['message' => 'Hi', 'contactEmail' => 'nope']);
			$this->fail('A refused payload must throw');
		} catch (WebFormSubmitException $e) {
			$this->assertSame(422, $e->getStatus());
			$this->assertSame($findings, $e->getFindings());
		}
	}//end testRefusedPayloadKeepsItsFindings()

	/**
	 * A destination that is down, missing or refuses the account reads "try again later", with nothing stored.
	 *
	 * @return void
	 */
	public function testOutageAndMisconfigurationAnswer503(): void {
		foreach ([new FakeRefusal('down', 503), new FakeRefusal('not allowed', 403), new FakeRefusal('gone', 404), new RuntimeException('db')] as $throw) {
			try {
				$this->service(throw: $throw)->submitByKey(key: 'website-support', payload: ['message' => 'Hi']);
				$this->fail('An outage must throw');
			} catch (WebFormSubmitException $e) {
				$this->assertSame(503, $e->getStatus());
				$this->assertSame(WebFormIntakeService::TRY_LATER, $e->getMessage());
				$this->assertSame([], $e->getFindings());
			}
		}
	}//end testOutageAndMisconfigurationAnswer503()

	/**
	 * Forms that cannot be read (no register configured) answer 503, not a refusal of the visitor.
	 *
	 * @return void
	 */
	public function testUnconfiguredRegisterAnswers503(): void {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('');
		$container = new FakeContainer();
		$users = $this->createMock(IUserManager::class);
		$service = new WebFormIntakeService(
			new WebFormFinder($config, $this->contractOver(new FakeObjectStore())),
			$container,
			new SystemServiceAccount($users, $this->createMock(IUserSession::class), $this->createMock(ISecureRandom::class)),
			$this->createMock(LoggerInterface::class)
		);

		$this->expectExceptionObject(new WebFormSubmitException(WebFormIntakeService::TRY_LATER, 503));
		$service->submitByKey(key: 'website-support', payload: ['message' => 'Hi']);
	}//end testUnconfiguredRegisterAnswers503()

	/**
	 * A published form with no destination is the site owner's problem: 503, nothing submitted.
	 *
	 * @return void
	 */
	public function testFormWithoutDestinationAnswers503(): void {
		$service = $this->service();
		try {
			$service->submit(form: ['id' => 'x', 'status' => 'published'], payload: ['message' => 'Hi']);
			$this->fail('A form without a destination must throw');
		} catch (WebFormSubmitException $e) {
			$this->assertSame(503, $e->getStatus());
		}

		$this->assertSame([], $this->submitter->calls);
	}//end testFormWithoutDestinationAnswers503()
}//end class
