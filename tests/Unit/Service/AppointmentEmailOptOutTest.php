<?php

/**
 * Appointment mail asks integriq as `reminder` before it is sent.
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
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-pipelinq-asks-integriq-before-every-non-exempt-message-req-cii-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCA\Pipelinq\Service\UnsubscribeMail;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\RecordingMessage;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The reminder to an opted-out contact is not sent and the booking says why.
 */
class AppointmentEmailOptOutTest extends TestCase {

	private FakeIntegriq $integriq;

	/** @var array<int, RecordingMessage> */
	private array $sent = [];

	/** @var array<int, array<string, mixed>> */
	private array $saved = [];

	private function service(): AppointmentEmailService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'register' => 'pipelinq',
				'consent.store' => 'integriq',
				default => (str_ends_with($key, '_schema') === true ? $key : $default),
			}
		);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturnCallback(fn () => new RecordingMessage());
		$mailer->method('send')->willReturnCallback(
			function ($message): array {
				$this->sent[] = $message;
				return [];
			}
		);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $u) => 'https://pipelinq.example'.$u);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []) => vsprintf($text, $p));

		$rows = [
			'b-1' => ['uuid' => 'b-1', 'customerId' => 'c-1', 'serviceId' => 's-1', 'startAt' => '2026-10-06T09:00:00Z', 'endAt' => '2026-10-06T09:30:00Z'],
			'c-1' => ['uuid' => 'c-1', 'email' => 'jan@example.nl', 'name' => 'Jan'],
			's-1' => ['uuid' => 's-1', 'name' => 'Paspoort'],
		];
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('find')->willReturnCallback(
			function ($id) use ($rows) {
				if (isset($rows[(string)$id]) === false) {
					return null;
				}

				$entity = $this->createMock(ObjectEntityInterface::class);
				$entity->method('jsonSerialize')->willReturn($rows[(string)$id]);
				$entity->method('getObject')->willReturn($rows[(string)$id]);
				return $entity;
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function ($object) {
				$this->saved[] = $object;
				return $this->createMock(ObjectEntityInterface::class);
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id) => new UnsubscribeHeaders());

		$service = new AppointmentEmailService(
			appConfig: $appConfig,
			mailer: $mailer,
			urlGenerator: $urls,
			l10n: $l10n,
			logger: new NullLogger(),
			objectService: $objects,
			integriq: FakeIntegriq::client($appConfig, $this->integriq),
			unsubscribeMail: new UnsubscribeMail($container, $l10n, new NullLogger()),
		);
		return $service;
	}//end service()

	protected function setUp(): void {
		$this->integriq = new FakeIntegriq();
	}//end setUp()

	public function testAReminderToAnOptedOutContactIsNotSentAndTheBookingSaysWhy(): void {
		$this->integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-out', 'scope' => 'instance', 'channel' => '']);
		$service = $this->service();

		self::assertFalse($service->sendReminder('b-1'));
		self::assertSame([], $this->sent);
		self::assertSame('opted-out', $service->lastRefusal()['code']);
		self::assertSame('reminder', $this->integriq->decisionEvents[0]->getCategory());
		self::assertSame('opted-out', end($this->saved)['mailNotSentReason']);
	}//end testAReminderToAnOptedOutContactIsNotSentAndTheBookingSaysWhy()

	public function testANormalReminderCarriesTheLinkAndTheHeaders(): void {
		$service = $this->service();

		self::assertTrue($service->sendReminder('b-1'));
		$message = $this->sent[0];
		self::assertStringContainsString('https://integriq.example/', $message->plain);
		self::assertStringStartsWith('<https://integriq.example/', $message->headers['List-Unsubscribe']);
		self::assertSame('List-Unsubscribe=One-Click', $message->headers['List-Unsubscribe-Post']);
		self::assertNull($service->lastRefusal());
	}//end testANormalReminderCarriesTheLinkAndTheHeaders()

	public function testWithoutIntegriqAConfirmationIsRefused(): void {
		$this->integriq->mode = FakeIntegriq::MODE_UNHANDLED;
		$service = $this->service();

		self::assertFalse($service->sendConfirmation('b-1'));
		self::assertSame('authority-unavailable', $service->lastRefusal()['code']);
	}//end testWithoutIntegriqAConfirmationIsRefused()
}//end class
