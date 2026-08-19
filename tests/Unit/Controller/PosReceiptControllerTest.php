<?php

/**
 * Unit tests for PosReceiptController.
 *
 * Verifies the wire contract of the receipt surface:
 *   - Every action demands a session user (401) and forwards the acting UID to
 *     ReceiptDeliveryService, which is what makes the per-object access check
 *     possible at all — a controller that dropped the UID would silently turn
 *     the guard into "no guard".
 *   - A caller who may not see the transaction gets 403; a missing transaction
 *     404; a non-receiptable one 422.
 *   - `email` forwards the client-supplied recipient so the service can REJECT
 *     it when it differs from the customer's stored address; the accepted
 *     response reports the customer address, never the requested one.
 *   - `print` returns the base64 ESC/POS payload plus the audit log id.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PosReceiptController;
use OCA\Pipelinq\Service\ReceiptDeliveryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for PosReceiptController.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PosReceiptControllerTest extends TestCase
{

    private PosReceiptController $controller;

    /**
     * @var ReceiptDeliveryService&MockObject
     */
    private ReceiptDeliveryService $service;

    /**
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * @var IUserSession&MockObject
     */
    private IUserSession $session;

    protected function setUp(): void
    {
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(ReceiptDeliveryService::class);
        $this->session = $this->createMock(IUserSession::class);

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        $this->controller = new PosReceiptController(
            $this->request,
            $this->service,
            $this->session,
            $l10n,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Make the session resolve to a user.
     *
     * @param string $uid The user id.
     *
     * @return void
     */
    private function loginAs(string $uid='cashier'): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->session->method('getUser')->willReturn($user);
    }//end loginAs()

    /**
     * Stub the request params.
     *
     * @param array<string, string> $params The params.
     *
     * @return void
     */
    private function withParams(array $params): void
    {
        $this->request->method('getParam')
            ->willReturnCallback(
                static fn (string $name, mixed $default=null): mixed => ($params[$name] ?? $default)
            );
    }//end withParams()

    // ---- preview -----------------------------------------------------------

    /**
     * @return void
     */
    public function testPreviewRequiresAuthentication(): void
    {
        $this->session->method('getUser')->willReturn(null);
        $this->service->expects($this->never())->method('preview');

        $response = $this->controller->preview(id: 'tx-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        $this->assertSame('Authentication required', $response->getData()['error']);
    }//end testPreviewRequiresAuthentication()

    /**
     * @return void
     */
    public function testPreviewReturnsRenderedReceipt(): void
    {
        $this->loginAs();
        $this->withParams([]);

        $this->service->method('preview')->willReturn(
            [
                'text'          => 'RECEIPT',
                'html'          => '<p>RECEIPT</p>',
                'isInvoice'     => false,
                'reference'     => 'POS-2026-0001',
                'customerEmail' => 'customer@example.com',
            ]
        );

        $response = $this->controller->preview(id: 'tx-1');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertArrayHasKey('receipt', $data);
        $this->assertSame('RECEIPT', $data['receipt']['text']);
        $this->assertSame('<p>RECEIPT</p>', $data['receipt']['html']);
        $this->assertFalse($data['receipt']['isInvoice']);
        $this->assertSame('POS-2026-0001', $data['receipt']['reference']);
    }//end testPreviewReturnsRenderedReceipt()

    /**
     * The acting UID reaches the service — without it the per-object access
     * check cannot distinguish the owning cashier from an arbitrary caller.
     *
     * @return void
     */
    public function testPreviewForwardsActingUserAndTemplate(): void
    {
        $this->loginAs(uid: 'cashier-7');
        $this->withParams(['template' => 'tpl-1']);

        $this->service->expects($this->once())
            ->method('preview')
            ->with('tx-1', 'tpl-1', 'cashier-7')
            ->willReturn(
                [
                    'text'          => 'X',
                    'html'          => 'X',
                    'isInvoice'     => false,
                    'reference'     => 'R',
                    'customerEmail' => '',
                ]
            );

        $response = $this->controller->preview(id: 'tx-1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }//end testPreviewForwardsActingUserAndTemplate()

    /**
     * A transaction the caller may not access is 403, not 200 with content.
     *
     * @return void
     */
    public function testPreviewMaps403WhenCallerMayNotAccessTransaction(): void
    {
        $this->loginAs(uid: 'other-cashier');
        $this->withParams([]);
        $this->service->method('preview')
            ->willThrowException(new OCSForbiddenException('Not your transaction'));

        $response = $this->controller->preview(id: 'someone-elses-tx');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertSame('Not your transaction', $data['error']);
        $this->assertArrayNotHasKey('receipt', $data);
    }//end testPreviewMaps403WhenCallerMayNotAccessTransaction()

    /**
     * @return void
     */
    public function testPreviewMaps404WhenTransactionMissing(): void
    {
        $this->loginAs();
        $this->withParams([]);
        $this->service->method('preview')
            ->willThrowException(new OCSNotFoundException('Transaction not found'));

        $response = $this->controller->preview(id: 'missing');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('Transaction not found', $response->getData()['error']);
    }//end testPreviewMaps404WhenTransactionMissing()

    // ---- email -------------------------------------------------------------

    /**
     * @return void
     */
    public function testEmailRequiresAuthentication(): void
    {
        $this->session->method('getUser')->willReturn(null);
        $this->service->expects($this->never())->method('emailReceipt');

        $response = $this->controller->email(id: 'tx-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testEmailRequiresAuthentication()

    /**
     * The receipt goes to the address on the customer record. The response
     * reports THAT address, so a client cannot infer a successful send to an
     * address of its own choosing.
     *
     * @return void
     */
    public function testEmailSendsToTheCustomerAddressOnRecord(): void
    {
        $this->loginAs();
        $this->withParams([]);

        $this->service->method('emailReceipt')->willReturn(
            [
                'status'         => 'success',
                'emailRecipient' => 'customer@example.com',
                'logId'          => 'log-1',
            ]
        );

        $response = $this->controller->email(id: 'tx-1');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('success', $data['receipt']['status']);
        $this->assertSame('customer@example.com', $data['receipt']['emailRecipient']);
        $this->assertSame('log-1', $data['receipt']['logId']);
    }//end testEmailSendsToTheCustomerAddressOnRecord()

    /**
     * The client-supplied recipient is FORWARDED to the service rather than
     * silently dropped or silently honoured: the service is the component that
     * compares it to the customer's stored address. A controller that dropped
     * it would let a mismatching request succeed unnoticed.
     *
     * @return void
     */
    public function testEmailForwardsTheRequestedRecipientForValidation(): void
    {
        $this->loginAs(uid: 'cashier-7');
        $this->withParams(['recipient' => 'attacker@evil.example']);

        $this->service->expects($this->once())
            ->method('emailReceipt')
            ->with('tx-1', null, 'attacker@evil.example', 'cashier-7')
            ->willThrowException(
                new OCSBadRequestException('Receipt can only be sent to the linked customer address')
            );

        $response = $this->controller->email(id: 'tx-1');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
    }//end testEmailForwardsTheRequestedRecipientForValidation()

    /**
     * An arbitrary recipient is rejected with a 4xx and no send is reported.
     *
     * @return void
     */
    public function testEmailRejectsARecipientThatIsNotTheLinkedCustomer(): void
    {
        $this->loginAs();
        $this->withParams(['recipient' => 'attacker@evil.example']);

        $this->service->method('emailReceipt')
            ->willThrowException(
                new OCSBadRequestException('Receipt can only be sent to the linked customer address')
            );

        $response = $this->controller->email(id: 'tx-1');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertArrayNotHasKey('receipt', $data);
        $this->assertSame('Receipt can only be sent to the linked customer address', $data['error']);
    }//end testEmailRejectsARecipientThatIsNotTheLinkedCustomer()

    /**
     * @return void
     */
    public function testEmailMaps403WhenCallerMayNotAccessTransaction(): void
    {
        $this->loginAs(uid: 'other-cashier');
        $this->withParams([]);
        $this->service->method('emailReceipt')
            ->willThrowException(new OCSForbiddenException('Not your transaction'));

        $response = $this->controller->email(id: 'someone-elses-tx');

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
    }//end testEmailMaps403WhenCallerMayNotAccessTransaction()

    /**
     * A transaction that is not confirmed / settled / refunded cannot produce a
     * receipt: 422, not a rendered draft.
     *
     * @return void
     */
    public function testEmailMaps422ForNonReceiptableTransaction(): void
    {
        $this->loginAs();
        $this->withParams([]);
        $this->service->method('emailReceipt')
            ->willThrowException(new OCSBadRequestException('Transaction is not receiptable'));

        $response = $this->controller->email(id: 'draft-tx');

        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertSame('Transaction is not receiptable', $response->getData()['error']);
    }//end testEmailMaps422ForNonReceiptableTransaction()

    /**
     * A transport failure is reported INSIDE a 200 envelope as
     * `status: failed` with an audit log id — the caller must read the body,
     * not the status code, to learn whether the mail left the building.
     *
     * @return void
     */
    public function testEmailReportsTransportFailureInsideTheBody(): void
    {
        $this->loginAs();
        $this->withParams([]);

        $this->service->method('emailReceipt')->willReturn(
            [
                'status'         => 'failed',
                'emailRecipient' => 'customer@example.com',
                'logId'          => 'log-2',
                'error'          => 'Mail delivery failed (no SMTP relay configured).',
            ]
        );

        $response = $this->controller->email(id: 'tx-1');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('failed', $data['receipt']['status']);
        $this->assertArrayHasKey('error', $data['receipt']);
        $this->assertSame('log-2', $data['receipt']['logId']);
    }//end testEmailReportsTransportFailureInsideTheBody()

    // ---- print -------------------------------------------------------------

    /**
     * @return void
     */
    public function testPrintRequiresAuthentication(): void
    {
        $this->session->method('getUser')->willReturn(null);
        $this->service->expects($this->never())->method('printReceipt');

        $response = $this->controller->print(id: 'tx-1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testPrintRequiresAuthentication()

    /**
     * @return void
     */
    public function testPrintReturnsEscPosPayloadAndAuditLogId(): void
    {
        $this->loginAs(uid: 'cashier-7');
        $this->withParams([]);

        $bytes = base64_encode("\x1b@RECEIPT");
        $this->service->expects($this->once())
            ->method('printReceipt')
            ->with('tx-1', null, 'cashier-7')
            ->willReturn(
                [
                    'status'        => 'success',
                    'escposBase64'  => $bytes,
                    'byteLength'    => 9,
                    'printerDevice' => '10.0.0.5:9100',
                    'logId'         => 'log-3',
                ]
            );

        $response = $this->controller->print(id: 'tx-1');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('success', $data['receipt']['status']);
        $this->assertSame($bytes, $data['receipt']['escposBase64']);
        $this->assertSame(9, $data['receipt']['byteLength']);
        $this->assertSame('log-3', $data['receipt']['logId']);
    }//end testPrintReturnsEscPosPayloadAndAuditLogId()

    /**
     * @return void
     */
    public function testPrintMaps403WhenCallerMayNotAccessTransaction(): void
    {
        $this->loginAs(uid: 'other-cashier');
        $this->withParams([]);
        $this->service->method('printReceipt')
            ->willThrowException(new OCSForbiddenException('Not your transaction'));

        $response = $this->controller->print(id: 'someone-elses-tx');
        $data     = $response->getData();

        $this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
        $this->assertArrayNotHasKey('receipt', $data);
    }//end testPrintMaps403WhenCallerMayNotAccessTransaction()

    /**
     * @return void
     */
    public function testPrintMaps500OnUnexpectedFailureWithoutLeakingInternals(): void
    {
        $this->loginAs();
        $this->withParams([]);
        $this->service->method('printReceipt')
            ->willThrowException(new RuntimeException('SQLSTATE[08006] connection refused'));

        $response = $this->controller->print(id: 'tx-1');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('An unexpected error occurred', $response->getData()['error']);
        $this->assertStringNotContainsString('SQLSTATE', (string) $response->getData()['error']);
    }//end testPrintMaps500OnUnexpectedFailureWithoutLeakingInternals()
}//end class
