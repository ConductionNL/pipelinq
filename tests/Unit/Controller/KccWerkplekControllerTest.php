<?php

/**
 * Unit tests for KccWerkplekController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\KccWerkplekController;
use OCA\Pipelinq\Service\KccWerkplekService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for KccWerkplekController.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class KccWerkplekControllerTest extends TestCase
{
    /**
     * The request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * The werkplek service mock.
     *
     * @var KccWerkplekService&MockObject
     */
    private KccWerkplekService $service;

    /**
     * The user session mock.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession $userSession;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request     = $this->createMock(IRequest::class);
        $this->service     = $this->createMock(KccWerkplekService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger      = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return KccWerkplekController
     */
    private function buildController(): KccWerkplekController
    {
        return new KccWerkplekController(
            request: $this->request,
            kccWerkplekService: $this->service,
            userSession: $this->userSession,
            logger: $this->logger,
        );
    }//end buildController()

    /**
     * Stub the session to return a user with the given UID.
     *
     * @param string $uid The user UID.
     *
     * @return void
     */
    private function withUser(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end withUser()

    /**
     * state() returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testStateRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->state();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testStateRequiresAuthentication()

    /**
     * state() returns the aggregated payload from the service.
     *
     * @return void
     */
    public function testStateReturnsWorkspaceState(): void
    {
        $this->withUser('jan');
        $this->service->expects($this->once())
            ->method('getWorkspaceState')
            ->with('jan')
            ->willReturn(['workload' => 2, 'queueCounts' => []]);

        $response = $this->buildController()->state();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(2, $response->getData()['workload']);
    }//end testStateReturnsWorkspaceState()

    /**
     * state() returns a generic 500 (no leaked message) on service failure.
     *
     * @return void
     */
    public function testStateReturnsGenericErrorOnFailure(): void
    {
        $this->withUser('jan');
        $this->service->method('getWorkspaceState')
            ->willThrowException(new \RuntimeException('boom secret detail'));
        $this->logger->expects($this->once())->method('error');

        $response = $this->buildController()->state();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Operation failed', $response->getData()['message']);
    }//end testStateReturnsGenericErrorOnFailure()

    /**
     * setAvailability() returns 401 when unauthenticated.
     *
     * @return void
     */
    public function testSetAvailabilityRequiresAuthentication(): void
    {
        $this->userSession->method('getUser')->willReturn(null);

        $response = $this->buildController()->setAvailability();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSetAvailabilityRequiresAuthentication()

    /**
     * setAvailability() rejects a non-boolean isAvailable with 400.
     *
     * @return void
     */
    public function testSetAvailabilityRejectsNonBoolean(): void
    {
        $this->withUser('jan');
        $this->request->method('getParam')->with('isAvailable', null)->willReturn('yes');

        $response = $this->buildController()->setAvailability();

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSetAvailabilityRejectsNonBoolean()

    /**
     * setAvailability() forwards the boolean and the SESSION uid to the service.
     *
     * @return void
     */
    public function testSetAvailabilityUsesSessionUid(): void
    {
        $this->withUser('jan');
        $this->request->method('getParam')->with('isAvailable', null)->willReturn(false);
        $this->service->expects($this->once())
            ->method('setAvailability')
            ->with('jan', false)
            ->willReturn(['isAvailable' => false, 'maxConcurrent' => 3, 'skills' => []]);

        $response = $this->buildController()->setAvailability();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertFalse($response->getData()['isAvailable']);
    }//end testSetAvailabilityUsesSessionUid()
}//end class
