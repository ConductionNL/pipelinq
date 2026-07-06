<?php

/**
 * Unit tests for MessagingController.
 *
 * Covers the auth guard, per-object guard, outcome→HTTP-status mapping and
 * error hygiene of the outbound messaging send surface.
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
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/specs/outbound-messaging/spec.md#requirement-req-om-004--server-side-send-endpoint
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\MessagingController;
use OCA\Pipelinq\Service\ChannelProviderRepository;
use OCA\Pipelinq\Service\ConsentService;
use OCA\Pipelinq\Service\MessagingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * MessagingController unit coverage.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class MessagingControllerTest extends TestCase
{
    private MessagingService $messagingService;
    private ChannelProviderRepository $providerRepo;
    private ConsentService $consentService;
    private IUserSession $userSession;
    private MessagingController $controller;

    /**
     * Build the controller with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->messagingService = $this->createMock(MessagingService::class);
        $this->providerRepo     = $this->createMock(ChannelProviderRepository::class);
        $this->consentService   = $this->createMock(ConsentService::class);
        $this->userSession      = $this->createMock(IUserSession::class);

        $this->controller = new MessagingController(
            $this->createMock(IRequest::class),
            $this->messagingService,
            $this->providerRepo,
            $this->consentService,
            $this->userSession,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Stub the session to return a user with the given uid.
     *
     * @param string $uid The uid.
     *
     * @return void
     */
    private function signIn(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end signIn()

    /**
     * An unauthenticated send is rejected with 401.
     *
     * @return void
     */
    public function testSendUnauthorized(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $response = $this->controller->send(contactId: 'c1', channel: 'sms', body: 'hi');
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSendUnauthorized()

    /**
     * An invalid channel is rejected with 400.
     *
     * @return void
     */
    public function testSendInvalidChannel(): void
    {
        $this->signIn('agent-1');
        $response = $this->controller->send(contactId: 'c1', channel: 'carrier-pigeon', body: 'hi');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSendInvalidChannel()

    /**
     * A caller without access to the contact is refused before dispatch (404).
     *
     * @return void
     */
    public function testSendUnauthorizedContactRejectedBeforeDispatch(): void
    {
        $this->signIn('agent-1');
        $this->messagingService->method('loadContact')->willReturn(null);
        $this->messagingService->expects($this->never())->method('send');

        $response = $this->controller->send(contactId: 'c-nope', channel: 'sms', body: 'hi');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testSendUnauthorizedContactRejectedBeforeDispatch()

    /**
     * A sent outcome maps to HTTP 200 with the envelope.
     *
     * @return void
     */
    public function testSendSuccess(): void
    {
        $this->signIn('agent-1');
        $this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
        $this->messagingService->method('send')->willReturn(['status' => 'sent', 'messageId' => 'm1']);

        $response = $this->controller->send(contactId: 'c1', channel: 'sms', body: 'hi');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('sent', $response->getData()['status']);
        $this->assertSame('m1', $response->getData()['messageId']);
    }//end testSendSuccess()

    /**
     * A consent-missing outcome maps to HTTP 422 (business refusal).
     *
     * @return void
     */
    public function testSendConsentMissingMaps422(): void
    {
        $this->signIn('agent-1');
        $this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
        $this->messagingService->method('send')->willReturn(['status' => 'consent-missing']);

        $response = $this->controller->send(contactId: 'c1', channel: 'whatsapp', templateId: 'tpl-1');
        $this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
        $this->assertSame('consent-missing', $response->getData()['status']);
    }//end testSendConsentMissingMaps422()

    /**
     * A failed outcome maps to HTTP 502 and never leaks a vendor error.
     *
     * @return void
     */
    public function testSendFailureMaps502(): void
    {
        $this->signIn('agent-1');
        $this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
        $this->messagingService->method('send')->willReturn(['status' => 'failed']);

        $response = $this->controller->send(contactId: 'c1', channel: 'sms', body: 'hi');
        $this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
        $this->assertArrayNotHasKey('error', $response->getData());
    }//end testSendFailureMaps502()

    /**
     * Consent recording requires evidence + legal basis.
     *
     * @return void
     */
    public function testConsentRequiresEvidenceAndLegalBasis(): void
    {
        $this->signIn('agent-1');
        $response = $this->controller->consent(contactId: 'c1', channel: 'whatsapp', action: 'opt-in');
        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testConsentRequiresEvidenceAndLegalBasis()

    /**
     * A valid opt-in is recorded and the new state returned.
     *
     * @return void
     */
    public function testConsentOptInRecorded(): void
    {
        $this->signIn('agent-1');
        $this->messagingService->method('loadContact')->willReturn(['uuid' => 'c1']);
        $this->consentService->expects($this->once())->method('recordOptIn');
        $this->consentService->method('latestState')->willReturn('opted-in');

        $response = $this->controller->consent(
            contactId: 'c1',
            channel: 'whatsapp',
            action: 'opt-in',
            source: 'manual',
            evidence: 'customer confirmed by phone',
            legalBasis: 'consent'
        );

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame('recorded', $response->getData()['status']);
        $this->assertSame('opted-in', $response->getData()['state']);
    }//end testConsentOptInRecorded()

    /**
     * testProvider returns 404 for an unknown provider.
     *
     * @return void
     */
    public function testProviderNotFound(): void
    {
        $this->providerRepo->method('findById')->willReturn(null);
        $response = $this->controller->testProvider(id: 'nope');
        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
    }//end testProviderNotFound()

    /**
     * testProvider runs the connectivity test for a known provider.
     *
     * @return void
     */
    public function testProviderRunsTest(): void
    {
        $this->providerRepo->method('findById')->willReturn(['uuid' => 'p1', 'sourceId' => 'messagebird-sms']);
        $this->messagingService->method('runProviderTest')->willReturn(['reachable' => true, 'mock' => true]);

        $response = $this->controller->testProvider(id: 'p1');
        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertTrue($response->getData()['reachable']);
        $this->assertTrue($response->getData()['mock']);
    }//end testProviderRunsTest()
}//end class
