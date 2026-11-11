<?php

/**
 * Golden tests for the two Logius Berichtenbox webhooks: the signature gate,
 * the payload checks and every answer.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/berichtenbox/spec.md#REQ-RECEIPT-005
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use ArrayObject;
use OCA\Pipelinq\Controller\BerichtenboxWebhookController;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every refusal and answer of readReceipt() and inboundReply().
 */
class BerichtenboxWebhookGoldenTest extends TestCase {
	/**
	 * Everything the doubles saw, in order.
	 *
	 * @var ArrayObject<int, array<int, mixed>>
	 */
	private ArrayObject $calls;

	/**
	 * Set up the call log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->calls = new ArrayObject();
	}//end setUp()

	/**
	 * An unsigned call is refused with 422 and logged with its address, and
	 * the body is never handled; a signed non-object body is a 400.
	 *
	 * @return void
	 */
	public function testSignatureAndPayloadGate(): void {
		foreach (['readReceipt', 'inboundReply'] as $action) {
			$this->calls = new ArrayObject();
			$response = $this->controller(body: '{"logiusMessageId":"m"}', signed: false)->$action();
			$this->assertSame([422, ['error' => 'Invalid webhook signature']], [$response->getStatus(), $response->getData()], $action);
			$this->assertSame(
				[['signature', '{"logiusMessageId":"m"}'], ['warning', 'Berichtenbox ' . $action . ' webhook: invalid signature.', ['ip' => '203.0.113.9']]],
				$this->calls->getArrayCopy(),
				$action
			);

			foreach (['not json', '"text"', '7'] as $body) {
				$response = $this->controller(body: $body)->$action();
				$this->assertSame([400, ['error' => 'Invalid payload']], [$response->getStatus(), $response->getData()], $action);
			}
		}
	}//end testSignatureAndPayloadGate()

	/**
	 * The read receipt: its required fields, its answer, and a failing
	 * handler deferred with a warning.
	 *
	 * @return void
	 */
	public function testReadReceipt(): void {
		foreach (['{"logiusMessageId":"m"}', '{"readAt":"t"}', '{"logiusMessageId":"","readAt":"t"}'] as $body) {
			$response = $this->controller(body: $body)->readReceipt();
			$this->assertSame([400, ['error' => 'logiusMessageId and readAt are required']], [$response->getStatus(), $response->getData()]);
		}

		$response = $this->controller(body: '{"logiusMessageId":"m-1","readAt":"2026-06-01"}')->readReceipt();
		$this->assertSame([200, ['ok' => true, 'updated' => false]], [$response->getStatus(), $response->getData()]);

		$this->calls = new ArrayObject();
		$response = $this->controller(body: '{"logiusMessageId":"boom","readAt":"2026-06-01"}')->readReceipt();
		$this->assertSame([200, ['ok' => false, 'deferred' => true]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['warning', 'Berichtenbox readReceipt handler failed.', ['exception' => 'receipt store down', 'logiusMessageId' => 'boom']], array_slice($this->calls->getArrayCopy(), -1)[0]);
	}//end testReadReceipt()

	/**
	 * The inbound reply: its required fields, attachments that are not a
	 * list, its answer, and a failing handler refused with its reason.
	 *
	 * @return void
	 */
	public function testInboundReply(): void {
		foreach (['{"logiusReplyId":"r","bodyText":"b"}', '{"parentMessageId":"p","bodyText":"b"}', '{"parentMessageId":"p","logiusReplyId":"r","bodyText":""}'] as $body) {
			$response = $this->controller(body: $body)->inboundReply();
			$this->assertSame([400, ['error' => 'parentMessageId, logiusReplyId and bodyText are required']], [$response->getStatus(), $response->getData()]);
		}

		$this->calls = new ArrayObject();
		$response = $this->controller(body: '{"parentMessageId":"p","logiusReplyId":"r","bodyText":"Dank","attachments":"x"}')->inboundReply();
		$this->assertSame([200, ['ok' => true, 'interactionId' => 'cm-9', 'replyId' => 'reply-1']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['reply', 'p', 'r', 'Dank', []], array_slice($this->calls->getArrayCopy(), -1)[0]);

		$response = $this->controller(body: '{"parentMessageId":"p","logiusReplyId":"r","bodyText":"Dank","attachments":[{"filename":"a.pdf"}]}')->inboundReply();
		$this->assertSame(['reply', 'p', 'r', 'Dank', [['filename' => 'a.pdf']]], array_slice($this->calls->getArrayCopy(), -1)[0]);

		$this->calls = new ArrayObject();
		$response = $this->controller(body: '{"parentMessageId":"gone","logiusReplyId":"r","bodyText":"Dank"}')->inboundReply();
		$this->assertSame([400, ['error' => 'Reply processing failed: no such message']], [$response->getStatus(), $response->getData()]);
		$this->assertSame(['warning', 'Berichtenbox inboundReply handler failed.', ['exception' => 'no such message', 'parentMessageId' => 'gone']], array_slice($this->calls->getArrayCopy(), -1)[0]);
	}//end testInboundReply()

	/**
	 * Build the controller over recording doubles.
	 *
	 * @param string $body   The raw request body.
	 * @param bool   $signed Whether the signature checks out.
	 *
	 * @return BerichtenboxWebhookController
	 */
	private function controller(string $body, bool $signed = true): BerichtenboxWebhookController {
		$calls = $this->calls;
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('203.0.113.9');

		$logius = $this->createMock(LogiusConnector::class);
		$logius->method('handleWebhookSignature')->willReturnCallback(static function (IRequest $request, string $raw) use ($calls, $signed): bool {
			$calls[] = ['signature', $raw];
			return $signed;
		});

		$service = $this->createMock(BerichtenboxService::class);
		$service->method('handleReadReceipt')->willReturnCallback(static function (string $id, string $readAt): bool {
			if ($id === 'boom') {
				throw new RuntimeException('receipt store down');
			}

			return false;
		});
		$service->method('handleInboundReply')->willReturnCallback(static function (string $parentMessageId, string $logiusReplyId, string $bodyText, array $attachments) use ($calls): array {
			$calls[] = ['reply', $parentMessageId, $logiusReplyId, $bodyText, $attachments];
			if ($parentMessageId === 'gone') {
				throw new RuntimeException('no such message');
			}

			return ['createdInteractionId' => 'cm-9', 'uuid' => 'reply-1'];
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(static function (string $message, array $context = []) use ($calls): void {
			$calls[] = ['warning', $message, $context];
		});

		return new class($request, $service, $logius, $logger, $body) extends BerichtenboxWebhookController {
			public function __construct(
				IRequest $request,
				BerichtenboxService $berichtenbox,
				LogiusConnector $logius,
				LoggerInterface $logger,
				private readonly string $body,
			) {
				parent::__construct($request, $berichtenbox, $logius, $logger);
			}

			protected function readRawBody(): string {
				return $this->body;
			}
		};
	}//end controller()
}//end class
