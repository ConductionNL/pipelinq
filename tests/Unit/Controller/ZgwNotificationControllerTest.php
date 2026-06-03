<?php

/**
 * Unit tests for ZgwNotificationController (NRC inbox bearer authentication).
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ZgwNotificationController;
use OCA\Pipelinq\Service\NrcNotificationHandler;
use OCA\Pipelinq\Service\ZgwObjectRepository;
use OCA\Pipelinq\Service\ZgwSecretResolver;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for ZgwNotificationController.
 */
class ZgwNotificationControllerTest extends TestCase
{
    /**
     * Build the controller with a request whose Authorization header is $auth.
     *
     * @param string                 $auth     The Authorization header value.
     * @param ZgwObjectRepository    $repo     The repository mock.
     * @param ZgwSecretResolver      $resolver The secret resolver mock.
     * @param NrcNotificationHandler $handler  The handler mock.
     *
     * @return ZgwNotificationController The controller under test.
     */
    private function makeController(
        string $auth,
        ZgwObjectRepository $repo,
        ZgwSecretResolver $resolver,
        NrcNotificationHandler $handler
    ): ZgwNotificationController {
        $request = $this->createMock(IRequest::class);
        $request->method('getHeader')->willReturnCallback(
            static fn(string $name): string => ($name === 'Authorization') ? $auth : ''
        );

        return new ZgwNotificationController(
            $request,
            $repo,
            $resolver,
            $handler,
            $this->createMock(LoggerInterface::class)
        );
    }//end makeController()

    /**
     * A missing Authorization header yields 401 and never dispatches.
     *
     * @return void
     */
    public function testMissingBearerReturns401(): void
    {
        $handler = $this->createMock(NrcNotificationHandler::class);
        $handler->expects($this->never())->method('handle');

        $controller = $this->makeController(
            '',
            $this->createMock(ZgwObjectRepository::class),
            $this->createMock(ZgwSecretResolver::class),
            $handler
        );

        $response = $controller->inbox();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testMissingBearerReturns401()

    /**
     * A bearer that matches no abonnement secret yields 401.
     *
     * @return void
     */
    public function testUnknownBearerReturns401(): void
    {
        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('findBy')->willReturn([['callbackAuthKluisRef' => 'vault://x']]);

        $resolver = $this->createMock(ZgwSecretResolver::class);
        $resolver->method('resolve')->willReturn('the-real-secret');

        $handler = $this->createMock(NrcNotificationHandler::class);
        $handler->expects($this->never())->method('handle');

        $controller = $this->makeController('Bearer wrong-secret', $repo, $resolver, $handler);

        $response = $controller->inbox();
        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testUnknownBearerReturns401()

    /**
     * A bearer matching an active abonnement secret yields 202 and dispatches.
     *
     * @return void
     */
    public function testValidBearerReturns202AndDispatches(): void
    {
        $abonnement = ['id' => 'abon1', 'callbackAuthKluisRef' => 'vault://zgw/z/nrc'];

        $repo = $this->createMock(ZgwObjectRepository::class);
        $repo->method('findBy')->willReturn([$abonnement]);

        $resolver = $this->createMock(ZgwSecretResolver::class);
        $resolver->method('resolve')->willReturn('matching-secret');

        $handler = $this->createMock(NrcNotificationHandler::class);
        $handler->expects($this->once())->method('handle')
            ->with($this->equalTo($abonnement), $this->isType('array'));

        $controller = $this->makeController('Bearer matching-secret', $repo, $resolver, $handler);

        $response = $controller->inbox();
        $this->assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
    }//end testValidBearerReturns202AndDispatches()
}//end class
