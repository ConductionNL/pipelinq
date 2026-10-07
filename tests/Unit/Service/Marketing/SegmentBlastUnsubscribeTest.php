<?php

/**
 * A segment blast after the cutover carries integriq's link, in the body and
 * in List-Unsubscribe set through OpenRegister's helper.
 *
 * Wired from the caller: BlastService::resolveAudience() with a real
 * ComplianceService and a fake integriq, then MailTransportService with the
 * instance transport.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service\Marketing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/opt-out-before-send/specs/consent-in-integriq/spec.md#requirement-every-non-exempt-pipelinq-mail-carries-an-unsubscribe-link-req-cii-004
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service\Marketing;

use OCA\OpenRegister\Service\Notification\UnsubscribeHeaders;
use OCA\Pipelinq\Service\ArticleService;
use OCA\Pipelinq\Service\BlastService;
use OCA\Pipelinq\Service\ComplianceService;
use OCA\Pipelinq\Service\ContactAddressLookup;
use OCA\Pipelinq\Service\IntegriqMarketingConsent;
use OCA\Pipelinq\Service\Marketing\ListObjectStore;
use OCA\Pipelinq\Service\Marketing\MailTransportService;
use OCA\Pipelinq\Service\Marketing\PhysicalAddressRenderer;
use OCA\Pipelinq\Service\Marketing\SegmentSignalService;
use OCA\Pipelinq\Service\SegmentService;
use OCA\Pipelinq\Service\UnsubscribeMail;
use OCA\Pipelinq\Tests\Unit\Support\FakeIntegriq;
use OCA\Pipelinq\Tests\Unit\Support\FakeSlugResolver;
use OCA\Pipelinq\Tests\Unit\Support\InMemoryObjectService;
use OCA\Pipelinq\Tests\Unit\Support\RecordingMessage;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * Segment blast link, end to end.
 */
class SegmentBlastUnsubscribeTest extends TestCase {

	public function testASegmentBlastHasIntegriqsLinkInTheBodyAndTheHeaders(): void {
		$integriq = new FakeIntegriq();
		$integriq->seed(['address' => 'jan@example.nl', 'state' => 'opted-in', 'scope' => 'channel', 'channel' => 'email', 'lawfulBasis' => 'consent']);
		$store = new InMemoryObjectService();
		$logger = new NullLogger();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'register' => 'pipelinq',
				'consent_record_schema' => 'consentRecord',
				'consent.store' => 'integriq',
				default => $default,
			}
		);
		$appConfig->method('getValueBool')->willReturnCallback(static fn (string $a, string $k, bool $d = false) => $d);

		$segments = $this->createMock(SegmentService::class);
		$segments->method('getMembersForBlast')->willReturn([['contactId' => 'c-1', 'email' => 'jan@example.nl']]);

		$l10n = $this->createMock(IL10N::class);
		$container = $this->createMock(ContainerInterface::class);
		$compliance = null;
		$unsubscribeMail = null;
		$container->method('get')->willReturnCallback(
			function (string $id) use (&$compliance, &$unsubscribeMail, $store) {
				return match ($id) {
					ComplianceService::class => $compliance,
					UnsubscribeMail::class => $unsubscribeMail,
					UnsubscribeMail::HEADER_HELPER => new UnsubscribeHeaders(new NullLogger()),
					default => $store,
				};
			}
		);
		$unsubscribeMail = new UnsubscribeMail($container, $l10n, $logger);
		$compliance = new ComplianceService(
			$container,
			$appConfig,
			$segments,
			$logger,
			$this->createMock(SegmentSignalService::class),
			new IntegriqMarketingConsent(FakeIntegriq::client($appConfig, $integriq), new ContactAddressLookup($container, $appConfig, $logger))
		);

		$sent = [];
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(static fn () => new RecordingMessage());
		$mailer->method('send')->willReturnCallback(
			function ($message) use (&$sent): array {
				$sent[] = $message;
				return [];
			}
		);
		$mailTransport = new MailTransportService(
			$container,
			$appConfig,
			$mailer,
			new ArticleService($this->createMock(ListObjectStore::class)),
			FakeSlugResolver::connectorRegister(),
			$logger,
			new PhysicalAddressRenderer(),
		);
		$blast = new BlastService($container, $appConfig, $segments, $mailTransport, $logger);

		$audience = (new ReflectionMethod($blast, 'resolveAudience'))->invoke($blast, 'seg-1', '', 'email');
		$member = $audience['members'][0];
		self::assertStringStartsWith('https://integriq.example/', $member['unsubscribeUrl']);

		$delivery = ['uuid' => 'd-1', 'email' => 'jan@example.nl', 'contactId' => 'c-1', 'unsubscribeUrl' => $member['unsubscribeUrl']];
		$template = ['subject' => 'Nieuws', 'bodyHtml' => '<p>Hallo</p><a href="{{unsubscribe_link}}">Afmelden</a>', 'senderEmail' => 'noreply@example.test'];
		$transport = ['uuid' => 't-1', 'kind' => 'instance', 'dailyLimit' => 0, 'sentToday' => 0];
		$store->put('mailTransport', $transport);

		self::assertTrue($mailTransport->sendOneDelivery($delivery, $template, $transport));
		self::assertStringContainsString('href="'.$member['unsubscribeUrl'].'"', $sent[0]->html);
		self::assertSame('<'.$member['unsubscribeUrl'].'>', $sent[0]->headers['List-Unsubscribe']);
		self::assertSame('List-Unsubscribe=One-Click', $sent[0]->headers['List-Unsubscribe-Post']);
	}//end testASegmentBlastHasIntegriqsLinkInTheBodyAndTheHeaders()
}//end class
