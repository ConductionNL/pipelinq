<?php

/**
 * Unit tests for StufController, focusing on inbound-secret verification.
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
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\StufController;
use OCA\Pipelinq\Service\Stuf\StufAdapterService;
use OCA\Pipelinq\Service\Stuf\StufEndpointRepository;
use OCA\Pipelinq\Service\Stuf\StufInboundProcessor;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for StufController.
 */
class StufControllerTest extends TestCase
{

    /**
     * The request mock.
     *
     * @var IRequest&\PHPUnit\Framework\MockObject\MockObject
     */
    private IRequest $request;

    /**
     * The inbound processor mock.
     *
     * @var StufInboundProcessor&\PHPUnit\Framework\MockObject\MockObject
     */
    private StufInboundProcessor $inboundProcessor;

    /**
     * The app config mock.
     *
     * @var IAppConfig&\PHPUnit\Framework\MockObject\MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Build the controller with the configured inbound secret and presented header.
     *
     * @param string $configuredSecret The admin-configured shared secret.
     * @param string $presentedSecret  The X-Stuf-Secret header value.
     *
     * @return StufController The controller under test.
     */
    private function controller(string $configuredSecret, string $presentedSecret): StufController
    {
        $this->request          = $this->createMock(IRequest::class);
        $this->inboundProcessor = $this->createMock(StufInboundProcessor::class);
        $this->appConfig        = $this->createMock(IAppConfig::class);

        $this->appConfig->method('getValueString')->willReturn($configuredSecret);
        $this->request->method('getHeader')->willReturnCallback(
            static function (string $name) use ($presentedSecret): string {
                return $name === 'X-Stuf-Secret' ? $presentedSecret : '';
            }
        );
        $this->request->method('getParam')->willReturn('<soapenv:Envelope/>');

        $l10n = $this->createMock(IL10N::class);
        $l10n->method('t')->willReturnArgument(0);

        return new StufController(
            request: $this->request,
            adapter: $this->createMock(StufAdapterService::class),
            endpointRepo: $this->createMock(StufEndpointRepository::class),
            inboundProcessor: $this->inboundProcessor,
            appConfig: $this->appConfig,
            l10n: $l10n,
            logger: $this->createMock(LoggerInterface::class)
        );
    }//end controller()

    /**
     * Inbound reception fails closed when no shared secret is configured.
     *
     * @return void
     */
    public function testInboundFailsClosedWhenSecretUnset(): void
    {
        $controller = $this->controller(configuredSecret: '', presentedSecret: 'anything');
        $this->inboundProcessor->expects($this->never())->method('process');

        $response = $controller->inkomend();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testInboundFailsClosedWhenSecretUnset()

    /**
     * Inbound reception rejects a request presenting the wrong secret.
     *
     * @return void
     */
    public function testInboundRejectsWrongSecret(): void
    {
        $controller = $this->controller(configuredSecret: 'correct-secret', presentedSecret: 'wrong-secret');
        $this->inboundProcessor->expects($this->never())->method('process');

        $response = $controller->inkomend();

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testInboundRejectsWrongSecret()

    /**
     * Inbound reception processes the envelope when the secret matches.
     *
     * @return void
     */
    public function testInboundAcceptsMatchingSecret(): void
    {
        $controller = $this->controller(configuredSecret: 'correct-secret', presentedSecret: 'correct-secret');
        $this->inboundProcessor->expects($this->once())
            ->method('process')
            ->willReturn(['matchedMapping' => true, 'zaakIdentificatie' => 'ZAAK-1']);

        $response = $controller->inkomend();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['received' => true], $response->getData());
    }//end testInboundAcceptsMatchingSecret()
}//end class
