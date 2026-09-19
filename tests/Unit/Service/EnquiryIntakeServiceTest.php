<?php

/**
 * Verifies that the website enquiry intake refuses what it must refuse.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
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

namespace OCA\Pipelinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\EnquiryIntakeService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The whitelist is the only thing standing between an anonymous visitor and
 * the CRM's own state, so these are the tests that must fail if it is removed.
 */
class EnquiryIntakeServiceTest extends TestCase {

	private ObjectServiceInterface&MockObject $objectService;

	/**
	 * What the last saveObject() call was handed. Null until one happens, which
	 * is what the refusal tests assert on.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $stored = null;

	/**
	 * Build a service whose store writes into $this->stored.
	 *
	 * @param string $registerId Register id in app config.
	 * @param string $schemaId   Enquiry schema id in app config.
	 *
	 * @return EnquiryIntakeService The service under test.
	 */
	private function service(string $registerId = 'reg-1', string $schemaId = 'sch-1'): EnquiryIntakeService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($registerId, $schemaId): string {
				if ($key === 'register') {
					return $registerId;
				}

				if ($key === 'enquiry_schema') {
					return $schemaId;
				}

				return $default;
			}
		);

		$entity = $this->createMock(ObjectEntityInterface::class);
		$entity->method('getUuid')->willReturn('uuid-1');

		$this->objectService = $this->createMock(ObjectServiceInterface::class);
		$this->objectService->method('saveObject')->willReturnCallback(
			function (array $object) use ($entity): ObjectEntityInterface {
				$this->stored = $object;

				return $entity;
			}
		);

		return new EnquiryIntakeService(
			$appConfig,
			$this->createMock(LoggerInterface::class),
			$this->objectService
		);
	}//end service()

	/**
	 * A valid submission, with every field the website sends.
	 *
	 * @param array<string, string> $overrides Fields to change or add.
	 *
	 * @return array<string, string> The payload.
	 */
	private function payload(array $overrides = []): array {
		return array_merge(
			[
				'title' => 'Question about OpenRegister',
				'source' => 'website-support',
				'contactName' => 'Jane Doe',
				'contactEmail' => 'jane@example.test',
				'organisation' => 'Acme BV',
				'message' => 'How do I install this?',
			],
			$overrides
		);
	}//end payload()

	/**
	 * The happy path, asserted on what was STORED rather than on the return
	 * value. A write that validated its payload away would still return a uuid.
	 *
	 * @return void
	 */
	public function testStoresWhatTheVisitorTyped(): void {
		$uuid = $this->service()->submit(payload: $this->payload());

		$this->assertSame('uuid-1', $uuid);
		$this->assertIsArray($this->stored);
		$this->assertSame('Jane Doe', $this->stored['contactName']);
		$this->assertSame('jane@example.test', $this->stored['contactEmail']);
		$this->assertSame('Acme BV', $this->stored['organisation']);
		$this->assertSame('How do I install this?', $this->stored['message']);
	}//end testStoresWhatTheVisitorTyped()

	/**
	 * The endpoint owns the state fields, whatever the caller sent.
	 *
	 * @return void
	 */
	public function testDiscardsSubmittedState(): void {
		$this->service()->submit(
			payload: $this->payload(
				[
					'status' => 'converted',
					'lead' => 'lead-uuid-that-exists',
					'client' => 'client-uuid',
					'contact' => 'contact-uuid',
					'handledBy' => 'admin',
					'handledAt' => '2020-01-01T00:00:00+00:00',
				]
			)
		);

		$this->assertSame('new', $this->stored['status'], 'A submitter must not be able to set status');
		foreach (['lead', 'client', 'contact', 'handledBy', 'handledAt'] as $field) {
			$this->assertArrayNotHasKey(
				$field,
				$this->stored,
				"A submitter must not be able to set {$field}"
			);
		}
	}//end testDiscardsSubmittedState()

	/**
	 * receivedAt is the server's clock, not the submitter's.
	 *
	 * @return void
	 */
	public function testStampsReceivedAtServerSide(): void {
		$this->service()->submit(payload: $this->payload(['receivedAt' => '1999-01-01T00:00:00+00:00']));

		$this->assertNotSame('1999-01-01T00:00:00+00:00', $this->stored['receivedAt']);
		$this->assertNotFalse(strtotime((string)$this->stored['receivedAt']));
	}//end testStampsReceivedAtServerSide()

	/**
	 * An unknown source is refused, and nothing is written.
	 *
	 * @return void
	 */
	public function testRefusesUnknownSource(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		try {
			$service->submit(payload: $this->payload(['source' => 'not-a-real-form']));
		} finally {
			$this->assertNull($this->stored, 'A refused submission must write nothing');
		}
	}//end testRefusesUnknownSource()

	/**
	 * A missing source is refused the same way as an unknown one.
	 *
	 * @return void
	 */
	public function testRefusesMissingSource(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		try {
			$service->submit(payload: $this->payload(['source' => '']));
		} finally {
			$this->assertNull($this->stored);
		}
	}//end testRefusesMissingSource()

	/**
	 * Every allowed source is actually accepted. A allowlist nobody has watched
	 * ACCEPT is as untested as one nobody has watched refuse.
	 *
	 * @return void
	 */
	public function testAcceptsEveryAllowedSource(): void {
		$sources = [
			'website-support',
			'website-contact',
			'website-partners',
			'website-demo',
			'website-install',
		];
		foreach ($sources as $source) {
			$this->stored = null;
			$this->service()->submit(payload: $this->payload(['source' => $source]));
			$this->assertSame($source, $this->stored['source'], "Source should be accepted: {$source}");
		}
	}//end testAcceptsEveryAllowedSource()

	/**
	 * A filled honeypot is a bot, and is refused before anything else happens.
	 *
	 * @return void
	 */
	public function testRefusesFilledHoneypot(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		try {
			$service->submit(payload: $this->payload(['website' => 'http://spam.example']));
		} finally {
			$this->assertNull($this->stored);
		}
	}//end testRefusesFilledHoneypot()

	/**
	 * A submission with no message and no email gives nobody anything to do.
	 *
	 * @return void
	 */
	public function testRefusesSubmissionWithNothingToActOn(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		try {
			$service->submit(payload: ['title' => 'Hello', 'source' => 'website-contact']);
		} finally {
			$this->assertNull($this->stored);
		}
	}//end testRefusesSubmissionWithNothingToActOn()

	/**
	 * An email address alone is enough: the form may have no message box.
	 *
	 * @return void
	 */
	public function testAcceptsEmailWithoutMessage(): void {
		$this->service()->submit(
			payload: [
				'title' => 'Demo request',
				'source' => 'website-demo',
				'contactEmail' => 'jane@example.test',
			]
		);

		$this->assertSame('jane@example.test', $this->stored['contactEmail']);
	}//end testAcceptsEmailWithoutMessage()

	/**
	 * An oversized message is refused whole rather than silently shortened.
	 *
	 * @return void
	 */
	public function testRefusesOversizedMessage(): void {
		$service = $this->service();

		$this->expectException(InvalidArgumentException::class);
		try {
			$service->submit(payload: $this->payload(['message' => str_repeat('a', 20001)]));
		} finally {
			$this->assertNull($this->stored);
		}
	}//end testRefusesOversizedMessage()

	/**
	 * A titleless submission still lands somewhere readable, because title is
	 * the schema's one required property.
	 *
	 * @return void
	 */
	public function testFallsBackToAReadableTitle(): void {
		$this->service()->submit(payload: $this->payload(['title' => '']));

		$this->assertSame('Acme BV', $this->stored['title']);
	}//end testFallsBackToAReadableTitle()

	/**
	 * An unconfigured register is a server fault, not a bad submission, and the
	 * two must not collapse into the same answer for the caller.
	 *
	 * @return void
	 */
	public function testThrowsRuntimeWhenRegisterIsNotConfigured(): void {
		$this->expectException(RuntimeException::class);
		$this->service(registerId: '')->submit(payload: $this->payload());
	}//end testThrowsRuntimeWhenRegisterIsNotConfigured()
}//end class
