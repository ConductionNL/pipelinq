<?php

/**
 * Unit tests for AppointmentEmailService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-07-email-confirmation-reminder/specs/appointment-booking/spec.md#req-apt-006
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\AppointmentEmailService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AppointmentEmailService.
 *
 * Covers signed-link token shape (HMAC-SHA256, 30-day expiry, deterministic
 * structure that the portal reschedule/cancel endpoints — member 05 — will
 * verify with the same secret). The full send pipeline is exercised
 * indirectly through ReminderDispatchJobTest.
 */
class AppointmentEmailServiceTest extends TestCase {

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $appConfig;

	/**
	 * Mock DI container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface $container;

	/**
	 * Mock mailer.
	 *
	 * @var IMailer&MockObject
	 */
	private IMailer $mailer;

	/**
	 * Mock URL generator.
	 *
	 * @var IURLGenerator&MockObject
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * Mock localisation.
	 *
	 * @var IL10N&MockObject
	 */
	private IL10N $l10n;

	/**
	 * Mock logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Stored secret per app-config setValueString call.
	 *
	 * @var string
	 */
	private string $storedSecret = '';

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Persist any secret the service sets so subsequent reads see it.
		$this->appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				if ($key === AppointmentEmailService::LINK_SECRET_KEY) {
					$this->storedSecret = $value;
				}

				return true;
			}
		);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				if ($key === AppointmentEmailService::LINK_SECRET_KEY) {
					return ($this->storedSecret !== '' ? $this->storedSecret : $default);
				}

				return $default;
			}
		);
	}//end setUp()

	/**
	 * Build the service under test.
	 *
	 * @return AppointmentEmailService
	 */
	private function buildService(): AppointmentEmailService {
		return new AppointmentEmailService($this->appConfig,
			$this->mailer,
			$this->urlGenerator,
			$this->l10n,
			$this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);
	}//end buildService()

	/**
	 * Signed token has the expected four-part shape: bookingId.action.expiresAt.sig.
	 *
	 * @return void
	 */
	public function testSignedTokenHasFourParts(): void {
		$service = $this->buildService();
		$expiresAt = (time() + 86400);
		$token = $service->signLinkToken(
			bookingId: 'booking-123',
			action: 'reschedule',
			expiresAt: $expiresAt
		);

		$parts = explode('.', $token);
		$this->assertCount(4, $parts);
		$this->assertSame('booking-123', $parts[0]);
		$this->assertSame('reschedule', $parts[1]);
		$this->assertSame((string)$expiresAt, $parts[2]);
		$this->assertNotEmpty($parts[3]);
		// The signature is a hex-encoded SHA-256 (64 chars).
		$this->assertSame(64, strlen($parts[3]));
	}//end testSignedTokenHasFourParts()

	/**
	 * The signature is stable for the same payload — required for the portal
	 * (member 05) to recompute and compare it.
	 *
	 * @return void
	 */
	public function testSigningIsDeterministic(): void {
		$service = $this->buildService();
		$expiresAt = (time() + 86400);
		$a = $service->signLinkToken(bookingId: 'b', action: 'cancel', expiresAt: $expiresAt);
		$b = $service->signLinkToken(bookingId: 'b', action: 'cancel', expiresAt: $expiresAt);
		$this->assertSame($a, $b);
	}//end testSigningIsDeterministic()

	/**
	 * Different inputs MUST yield different signatures (no collisions).
	 *
	 * @return void
	 */
	public function testSigningChangesWithInputs(): void {
		$service = $this->buildService();
		$expiresAt = (time() + 86400);
		$base = $service->signLinkToken(bookingId: 'b', action: 'cancel', expiresAt: $expiresAt);
		$diffId = $service->signLinkToken(bookingId: 'c', action: 'cancel', expiresAt: $expiresAt);
		$diffAct = $service->signLinkToken(bookingId: 'b', action: 'reschedule', expiresAt: $expiresAt);
		$diffExp = $service->signLinkToken(bookingId: 'b', action: 'cancel', expiresAt: ($expiresAt + 1));
		$this->assertNotSame($base, $diffId);
		$this->assertNotSame($base, $diffAct);
		$this->assertNotSame($base, $diffExp);
	}//end testSigningChangesWithInputs()

	/**
	 * `sendConfirmation` bails on an empty booking id without touching the
	 * mailer (defensive guard).
	 *
	 * @return void
	 */
	public function testSendConfirmationSkipsOnEmptyBookingId(): void {
		$this->mailer->expects($this->never())->method('createMessage');
		$service = $this->buildService();
		$this->assertFalse($service->sendConfirmation(bookingId: ''));
	}//end testSendConfirmationSkipsOnEmptyBookingId()

	/**
	 * `sendReminder` bails on an empty booking id without touching the mailer.
	 *
	 * @return void
	 */
	public function testSendReminderSkipsOnEmptyBookingId(): void {
		$this->mailer->expects($this->never())->method('createMessage');
		$service = $this->buildService();
		$this->assertFalse($service->sendReminder(bookingId: ''));
	}//end testSendReminderSkipsOnEmptyBookingId()
}//end class
