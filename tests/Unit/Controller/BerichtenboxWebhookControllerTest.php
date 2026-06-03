<?php

/**
 * Unit tests for BerichtenboxWebhookController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\BerichtenboxWebhookController;
use OCA\Pipelinq\Service\BerichtenboxService;
use OCA\Pipelinq\Service\LogiusConnector;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test subclass that injects a fixed raw body.
 */
class TestableWebhookController extends BerichtenboxWebhookController
{
    /**
     * The injected raw body.
     *
     * @var string
     */
    public string $injectedBody = '';

    /**
     * Return the injected raw body instead of reading php://input.
     *
     * @return string The raw body.
     */
    protected function rawBody(): string
    {
        return $this->injectedBody;
    }//end rawBody()
}//end class

/**
 * Tests for the Logius webhook controller.
 */
class BerichtenboxWebhookControllerTest extends TestCase
{
    /**
     * The core service mock.
     *
     * @var BerichtenboxService
     */
    private BerichtenboxService $service;

    /**
     * The Logius connector mock.
     *
     * @var LogiusConnector
     */
    private LogiusConnector $logius;

    /**
     * The request mock.
     *
     * @var IRequest
     */
    private IRequest $request;

    /**
     * The controller under test.
     *
     * @var TestableWebhookController
     */
    private TestableWebhookController $controller;

    /**
     * Set up the controller with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = $this->createMock(BerichtenboxService::class);
        $this->logius  = $this->createMock(LogiusConnector::class);
        $this->request = $this->createMock(IRequest::class);

        $this->controller = new TestableWebhookController(
            $this->request,
            $this->service,
            $this->logius,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * A valid read-receipt is processed and returns 200.
     *
     * @return void
     */
    public function testReadReceiptValidSignature(): void
    {
        $this->controller->injectedBody = json_encode(['logiusMessageId' => 'bbk-1', 'readAt' => '2026-06-01T00:00:00Z']);
        $this->logius->method('verifyWebhookSignature')->willReturn(true);
        $this->service->expects($this->once())->method('handleReadReceipt');

        $response = $this->controller->readReceipt();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['success' => true], $response->getData());
    }//end testReadReceiptValidSignature()

    /**
     * An invalid signature is rejected with 401 and never processed.
     *
     * @return void
     */
    public function testReadReceiptInvalidSignature(): void
    {
        $this->controller->injectedBody = json_encode(['logiusMessageId' => 'bbk-1', 'readAt' => 'x']);
        $this->logius->method('verifyWebhookSignature')->willReturn(false);
        $this->service->expects($this->never())->method('handleReadReceipt');

        $response = $this->controller->readReceipt();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testReadReceiptInvalidSignature()

    /**
     * A valid inbound reply returns the created contactmoment id.
     *
     * @return void
     */
    public function testInboundReplyValid(): void
    {
        $this->controller->injectedBody = json_encode(
            ['parentMessageId' => 'p-1', 'logiusReplyId' => 'r-1', 'bodyText' => 'Hallo']
        );
        $this->logius->method('verifyWebhookSignature')->willReturn(true);
        $this->service->method('handleInboundReply')->willReturn(['createdContactmomentId' => 'cm-9']);

        $response = $this->controller->inboundReply();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['success' => true, 'contactmomentId' => 'cm-9'], $response->getData());
    }//end testInboundReplyValid()

    /**
     * An inbound reply with an invalid signature is rejected with 401.
     *
     * @return void
     */
    public function testInboundReplyInvalidSignature(): void
    {
        $this->controller->injectedBody = json_encode(['parentMessageId' => 'p-1', 'bodyText' => 'x']);
        $this->logius->method('verifyWebhookSignature')->willReturn(false);
        $this->service->expects($this->never())->method('handleInboundReply');

        $response = $this->controller->inboundReply();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testInboundReplyInvalidSignature()

    /**
     * A processing error during reply handling returns 400.
     *
     * @return void
     */
    public function testInboundReplyProcessingError(): void
    {
        $this->controller->injectedBody = json_encode(['parentMessageId' => 'p-1', 'bodyText' => 'x']);
        $this->logius->method('verifyWebhookSignature')->willReturn(true);
        $this->service->method('handleInboundReply')->willThrowException(new \RuntimeException('boom'));

        $response = $this->controller->inboundReply();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testInboundReplyProcessingError()
}//end class
