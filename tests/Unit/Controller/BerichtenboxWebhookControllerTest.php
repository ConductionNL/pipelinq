<?php

/**
 * Unit tests for BerichtenboxWebhookController — signature failures,
 * happy-path read-receipt + inbound-reply.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-receipt-005
 * @spec openspec/changes/burgerportaal-mijnoverheid-bridge/specs/berichtenbox/spec.md#req-inbound-006
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
 * Tests for BerichtenboxWebhookController.
 *
 * Subclass exposes readRawBody so we can inject the body without
 * touching php://input.
 */
class BerichtenboxWebhookControllerTest extends TestCase
{
    /**
     * Build a subclass that returns a fixed raw body.
     *
     * @param IRequest            $request Request mock.
     * @param BerichtenboxService $bb      Berichtenbox service.
     * @param LogiusConnector     $logius  Logius connector.
     * @param string              $body    Body to return.
     *
     * @return BerichtenboxWebhookController
     */
    private function buildController(
        IRequest $request,
        BerichtenboxService $bb,
        LogiusConnector $logius,
        string $body
    ): BerichtenboxWebhookController {
        return new class($request, $bb, $logius, $this->createMock(LoggerInterface::class), $body)
            extends BerichtenboxWebhookController
        {
            public function __construct(
                IRequest $request,
                BerichtenboxService $berichtenbox,
                LogiusConnector $logius,
                LoggerInterface $logger,
                private readonly string $body
            ) {
                parent::__construct($request, $berichtenbox, $logius, $logger);
            }
            protected function readRawBody(): string
            {
                return $this->body;
            }
        };
    }//end buildController()

    /**
     * readReceipt with invalid signature → 422.
     *
     * @return void
     */
    public function testReadReceiptInvalidSignature(): void
    {
        $logius = $this->createMock(LogiusConnector::class);
        $logius->method('handleWebhookSignature')->willReturn(false);

        $bb = $this->createMock(BerichtenboxService::class);
        $bb->expects($this->never())->method('handleReadReceipt');

        $request = $this->createMock(IRequest::class);
        $controller = $this->buildController($request, $bb, $logius, '{}');
        $response = $controller->readReceipt();
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testReadReceiptInvalidSignature()

    /**
     * readReceipt with valid signature + payload → 200 + updated:true.
     *
     * @return void
     */
    public function testReadReceiptSuccess(): void
    {
        $logius = $this->createMock(LogiusConnector::class);
        $logius->method('handleWebhookSignature')->willReturn(true);

        $bb = $this->createMock(BerichtenboxService::class);
        $bb->expects($this->once())
            ->method('handleReadReceipt')
            ->with('logius-1', '2026-06-01T00:00:00Z')
            ->willReturn(true);

        $request    = $this->createMock(IRequest::class);
        $body       = json_encode(['logiusMessageId' => 'logius-1', 'readAt' => '2026-06-01T00:00:00Z']);
        $controller = $this->buildController($request, $bb, $logius, $body);
        $response   = $controller->readReceipt();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertTrue($data['ok']);
        $this->assertTrue($data['updated']);
    }//end testReadReceiptSuccess()

    /**
     * readReceipt with missing required fields → 400.
     *
     * @return void
     */
    public function testReadReceiptMissingFields(): void
    {
        $logius = $this->createMock(LogiusConnector::class);
        $logius->method('handleWebhookSignature')->willReturn(true);
        $bb = $this->createMock(BerichtenboxService::class);
        $request = $this->createMock(IRequest::class);

        $controller = $this->buildController($request, $bb, $logius, '{}');
        $response   = $controller->readReceipt();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testReadReceiptMissingFields()

    /**
     * inboundReply happy-path → 200 + contactmomentId.
     *
     * @return void
     */
    public function testInboundReplySuccess(): void
    {
        $logius = $this->createMock(LogiusConnector::class);
        $logius->method('handleWebhookSignature')->willReturn(true);

        $bb = $this->createMock(BerichtenboxService::class);
        $bb->expects($this->once())
            ->method('handleInboundReply')
            ->willReturn([
                'uuid' => 'r-1',
                'createdContactmomentId' => 'cm-1',
            ]);

        $request    = $this->createMock(IRequest::class);
        $body       = json_encode([
            'parentMessageId' => 'msg-1',
            'logiusReplyId'   => 'lr-1',
            'bodyText'        => 'Hi',
        ]);
        $controller = $this->buildController($request, $bb, $logius, $body);
        $response   = $controller->inboundReply();
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $data = $response->getData();
        $this->assertSame('cm-1', $data['contactmomentId']);
    }//end testInboundReplySuccess()

    /**
     * inboundReply with handler exception → 400.
     *
     * @return void
     */
    public function testInboundReplyHandlerFailure(): void
    {
        $logius = $this->createMock(LogiusConnector::class);
        $logius->method('handleWebhookSignature')->willReturn(true);

        $bb = $this->createMock(BerichtenboxService::class);
        $bb->method('handleInboundReply')
            ->willThrowException(new \RuntimeException('boom'));

        $request    = $this->createMock(IRequest::class);
        $body       = json_encode([
            'parentMessageId' => 'msg-1',
            'logiusReplyId'   => 'lr-1',
            'bodyText'        => 'Hi',
        ]);
        $controller = $this->buildController($request, $bb, $logius, $body);
        $response   = $controller->inboundReply();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testInboundReplyHandlerFailure()
}//end class
