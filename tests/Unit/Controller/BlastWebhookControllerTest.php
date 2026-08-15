<?php

/**
 * Unit tests for BlastWebhookController.
 *
 * Covers:
 * - missing/invalid signature returns 422 (verbose fail-closed)
 * - valid HMAC signature accepts the event + enqueues it
 * - Twilio form-encoded path enqueues with provider="twilio"
 * - SES SNS envelope is unwrapped and enqueued
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\BackgroundJob\BlastSendJob;
use OCA\Pipelinq\Controller\BlastWebhookController;
use OCA\Pipelinq\Service\WebhookProcessorService;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for BlastWebhookController.
 *
 * @spec openspec/changes/marketing-segmentation-and-blast-05-jobs-and-webhooks/tasks.md#webhooks
 */
class BlastWebhookControllerTest extends TestCase {
	private IRequest $request;
	private IAppConfig $appConfig;
	private BlastSendJob $blastSendJob;
	private WebhookProcessorService $webhookProcessor;
	private LoggerInterface $logger;

	/**
	 * In-memory secret store.
	 *
	 * @var array<string, string>
	 */
	private array $appConfigStore = [];

	/**
	 * Set up — mock collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->blastSendJob = $this->createMock(BlastSendJob::class);
		$this->webhookProcessor = $this->createMock(WebhookProcessorService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				return $this->appConfigStore[$key] ?? $default;
			}
		);
	}//end setUp()

	/**
	 * Build a controller variant whose readRawBody returns the given string.
	 *
	 * @param string $rawBody Raw body to return from php://input.
	 *
	 * @return BlastWebhookController
	 */
	private function buildController(string $rawBody = ''): BlastWebhookController {
		return new class($this->request, $this->appConfig, $this->blastSendJob, $this->webhookProcessor, $this->logger, $rawBody) extends BlastWebhookController {
			private string $stubBody;

			public function __construct(
				IRequest $request,
				IAppConfig $appConfig,
				BlastSendJob $blastSendJob,
				WebhookProcessorService $webhookProcessor,
				LoggerInterface $logger,
				string $stubBody,
			) {
				parent::__construct($request, $appConfig, $blastSendJob, $webhookProcessor, $logger);
				$this->stubBody = $stubBody;
			}

			protected function readRawBody(): string {
				return $this->stubBody;
			}
		};
	}//end buildController()

	/**
	 * Missing signature header → 422 Unprocessable Entity.
	 *
	 * @return void
	 */
	public function testSendgridReturns422WhenSignatureMissing(): void {
		$this->request->method('getHeader')->willReturn('');
		$this->blastSendJob->expects($this->never())->method('enqueueWebhookEvent');

		$response = $this->buildController(rawBody: '[]')->sendgrid();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testSendgridReturns422WhenSignatureMissing()

	/**
	 * Valid HMAC signature → 200 + enqueue called for the SendGrid batch.
	 *
	 * @return void
	 */
	public function testSendgridAcceptsValidSignature(): void {
		$secret = 'top-secret';
		$rawBody = '[{"event":"delivered","sg_message_id":"sg-1","timestamp":1700000000}]';
		$sig = hash_hmac('sha256', $rawBody, $secret);

		$this->appConfigStore['blast.webhook_secret.sendgrid'] = $secret;
		$this->request->method('getHeader')->willReturnCallback(
			function (string $header) use ($sig): string {
				if ($header === 'X-Pipelinq-Signature') {
					return $sig;
				}

				return '';
			}
		);

		$this->webhookProcessor->method('normaliseSendGrid')
			->willReturn(['eventType' => 'delivered', 'providerId' => 'sg-1']);

		$this->blastSendJob->expects($this->once())
			->method('enqueueWebhookEvent')
			->with($this->equalTo('sendgrid'),
				$this->callback(function (array $event): bool {
					return ($event['eventType'] ?? '') === 'delivered';
				}),
			);

		$response = $this->buildController(rawBody: $rawBody)->sendgrid();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testSendgridAcceptsValidSignature()

	/**
	 * Twilio POSTs form-encoded params; controller enqueues with channel=sms.
	 *
	 * @return void
	 */
	public function testTwilioEnqueuesFormParams(): void {
		$secret = 'twilio-secret';
		$rawBody = ''; // Twilio uses form-encoded; raw body matters only for HMAC.
		$sig = hash_hmac('sha256', $rawBody, $secret);

		$this->appConfigStore['blast.webhook_secret.twilio'] = $secret;

		$this->request->method('getHeader')->willReturnCallback(
			function (string $header) use ($sig): string {
				if ($header === 'X-Pipelinq-Signature') {
					return $sig;
				}

				return '';
			}
		);
		$this->request->method('getParams')->willReturn([
			'MessageSid' => 'SM-1',
			'MessageStatus' => 'delivered',
		]);

		$this->blastSendJob->expects($this->once())
			->method('enqueueWebhookEvent')
			->with($this->equalTo('twilio'),
				$this->callback(function (array $event): bool {
					return ($event['channel'] ?? '') === 'sms'
						&& ($event['providerId'] ?? '') === 'SM-1'
						&& ($event['eventType'] ?? '') === 'delivered';
				}),
			);

		$response = $this->buildController(rawBody: $rawBody)->twilio();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testTwilioEnqueuesFormParams()

	/**
	 * SES SNS envelope wraps the inner event in `Message`. Controller unwraps
	 * and enqueues a normalised bounce event.
	 *
	 * @return void
	 */
	public function testSesUnwrapsSnsEnvelopeAndEnqueuesBounce(): void {
		$sesInner = [
			'notificationType' => 'Bounce',
			'mail' => ['messageId' => 'ses-msg-1', 'timestamp' => '2026-06-07T12:00:00Z'],
			'bounce' => [
				'bounceType' => 'Permanent',
				'bouncedRecipients' => [['emailAddress' => 'user@example.com', 'diagnosticCode' => 'rejected']],
			],
		];
		$envelope = ['Message' => json_encode($sesInner)];
		$rawBody = json_encode($envelope);

		$secret = 'ses-secret';
		$sig = hash_hmac('sha256', $rawBody, $secret);

		$this->appConfigStore['blast.webhook_secret.ses'] = $secret;
		$this->request->method('getHeader')->willReturnCallback(
			function (string $header) use ($sig): string {
				if ($header === 'X-Pipelinq-Signature') {
					return $sig;
				}

				return '';
			}
		);

		$this->blastSendJob->expects($this->once())
			->method('enqueueWebhookEvent')
			->with($this->equalTo('ses'),
				$this->callback(function (array $event): bool {
					return ($event['eventType'] ?? '') === 'bounce'
						&& ($event['bounceType'] ?? '') === 'hard';
				}),
			);

		$response = $this->buildController(rawBody: $rawBody)->ses();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}//end testSesUnwrapsSnsEnvelopeAndEnqueuesBounce()

	/**
	 * Tampered body with otherwise-valid header → signature mismatch → 422.
	 *
	 * @return void
	 */
	public function testSesRejectsTamperedBody(): void {
		$secret = 'ses-secret';
		$originalBody = '{"original":"payload"}';
		$sigForOriginal = hash_hmac('sha256', $originalBody, $secret);
		$tamperedBody = '{"tampered":"payload"}';

		$this->appConfigStore['blast.webhook_secret.ses'] = $secret;
		$this->request->method('getHeader')->willReturnCallback(
			function (string $header) use ($sigForOriginal): string {
				if ($header === 'X-Pipelinq-Signature') {
					return $sigForOriginal;
				}

				return '';
			}
		);

		$this->blastSendJob->expects($this->never())->method('enqueueWebhookEvent');

		$response = $this->buildController(rawBody: $tamperedBody)->ses();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testSesRejectsTamperedBody()
}//end class
