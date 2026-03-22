<?php

/**
 * Unit tests for PublicKennisbankController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\PublicKennisbankController;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PublicKennisbankController.
 */
class PublicKennisbankControllerTest extends TestCase
{
    /**
     * The request mock.
     *
     * @var IRequest&MockObject
     */
    private IRequest $request;

    /**
     * The container mock.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * The app manager mock.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager $appManager;

    /**
     * The settings service mock.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService $settingsService;

    /**
     * The logger mock.
     *
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface $logger;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->request         = $this->createMock(IRequest::class);
        $this->container       = $this->createMock(ContainerInterface::class);
        $this->appManager      = $this->createMock(IAppManager::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->logger          = $this->createMock(LoggerInterface::class);
    }//end setUp()

    /**
     * Build the controller under test.
     *
     * @return PublicKennisbankController
     */
    private function buildController(): PublicKennisbankController
    {
        return new PublicKennisbankController(
            request: $this->request,
            container: $this->container,
            appManager: $this->appManager,
            settingsService: $this->settingsService,
            logger: $this->logger,
        );
    }//end buildController()

    /**
     * Test that index returns empty results when not configured.
     *
     * @return void
     */
    public function testIndexReturnsEmptyWhenNotConfigured(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn([]);

        $response = $this->buildController()->index();
        $data     = $response->getData();

        $this->assertInstanceOf(JSONResponse::class, $response);
        $this->assertSame([], $data['results']);
        $this->assertSame(0, $data['total']);
    }//end testIndexReturnsEmptyWhenNotConfigured()

    /**
     * Test that index returns articles from the object service.
     *
     * @return void
     */
    public function testIndexReturnsArticles(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn(['register' => 'r', 'kennisartikel_schema' => 's']);
        $this->request->method('getParam')->willReturn('');

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findAll'])->getMock();
        $objectServiceMock->method('findAll')->willReturn(['results' => [['id' => '1', 'title' => 'Article']], 'total' => 1]);
        $this->container->method('get')->willReturn($objectServiceMock);

        $data = $this->buildController()->index()->getData();

        $this->assertSame(1, $data['total']);
        $this->assertSame('Article', $data['results'][0]['title']);
    }//end testIndexReturnsArticles()

    /**
     * Test that index strips internal fields from results.
     *
     * @return void
     */
    public function testIndexStripsInternalFields(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn(['register' => 'r', 'kennisartikel_schema' => 's']);
        $this->request->method('getParam')->willReturn('');

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findAll'])->getMock();
        $objectServiceMock->method('findAll')->willReturn(['results' => [['id' => '1', 'title' => 'A', 'author' => 'secret', 'lastUpdatedBy' => 'u', 'zaaktypeLinks' => []]]]);
        $this->container->method('get')->willReturn($objectServiceMock);

        $article = $this->buildController()->index()->getData()['results'][0];

        $this->assertArrayNotHasKey('author', $article);
        $this->assertArrayNotHasKey('lastUpdatedBy', $article);
        $this->assertArrayNotHasKey('zaaktypeLinks', $article);
    }//end testIndexStripsInternalFields()

    /**
     * Test that index returns 500 on exception.
     *
     * @return void
     */
    public function testIndexReturns500OnException(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willThrowException(new \RuntimeException('fail'));

        $response = $this->buildController()->index();

        $this->assertSame(500, $response->getStatus());
    }//end testIndexReturns500OnException()

    /**
     * Test that show returns 404 for nonexistent article.
     *
     * @return void
     */
    public function testShowReturns404ForMissingArticle(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn(['register' => 'r', 'kennisartikel_schema' => 's']);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findOne'])->getMock();
        $objectServiceMock->method('findOne')->willReturn(null);
        $this->container->method('get')->willReturn($objectServiceMock);

        $this->assertSame(404, $this->buildController()->show(id: 'missing')->getStatus());
    }//end testShowReturns404ForMissingArticle()

    /**
     * Test that show returns public article successfully.
     *
     * @return void
     */
    public function testShowReturnsPublicArticle(): void
    {
        $this->appManager->method('getInstalledApps')->willReturn(['openregister']);
        $this->settingsService->method('getSettings')->willReturn(['register' => 'r', 'kennisartikel_schema' => 's']);

        $objectServiceMock = $this->getMockBuilder(\stdClass::class)->addMethods(['findOne'])->getMock();
        $objectServiceMock->method('findOne')->willReturn(['id' => 'abc', 'title' => 'Public', 'status' => 'gepubliceerd', 'visibility' => 'openbaar']);
        $this->container->method('get')->willReturn($objectServiceMock);

        $response = $this->buildController()->show(id: 'abc');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Public', $response->getData()['title']);
    }//end testShowReturnsPublicArticle()
}//end class
