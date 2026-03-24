<?php

/**
 * Pipelinq PublicKennisbankController.
 *
 * Controller for public (no-auth) knowledge base article access.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Public controller for knowledge base article listing and detail views.
 */
class PublicKennisbankController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest           $request         The request.
     * @param ContainerInterface $container       The DI container.
     * @param IAppManager        $appManager      The app manager.
     * @param SettingsService    $settingsService The settings service.
     * @param LoggerInterface    $logger          The logger.
     */
    public function __construct(
        IRequest $request,
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * List published public knowledge base articles.
     *
     * @return JSONResponse The list of articles.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=kennisbank)
     */
    public function index(): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->settingsService->getSettings();
            if (empty($config['register']) === true || empty($config['kennisartikel_schema']) === true) {
                return new JSONResponse(data: ['results' => [], 'total' => 0]);
            }

            $filters = ['status' => 'gepubliceerd', 'visibility' => 'openbaar', '_limit' => 20];
            $search  = $this->request->getParam('_search', '');
            if ($search !== '') {
                $filters['_search'] = $search;
            }

            $result   = $objectService->findAll(
                register: $config['register'],
                schema: $config['kennisartikel_schema'],
                filters: $filters
            );
            $articles = array_map(
                callback: [$this, 'stripInternalFields'],
                array: ($result['results'] ?? [])
            );
            return new JSONResponse(
                data: ['results' => $articles, 'total' => ($result['total'] ?? count(value: $articles))]
            );
        } catch (\Exception $e) {
            $this->logger->error('Public kennisbank error: '.$e->getMessage());
            return new JSONResponse(data: ['error' => 'Failed to fetch articles'], statusCode: 500);
        }//end try
    }//end index()

    /**
     * Show a single published public knowledge base article.
     *
     * @param string $id The article ID.
     *
     * @return JSONResponse The article data.
     *
     * @PublicPage
     * @NoCSRFRequired
     * @BruteForceProtection(action=kennisbank)
     */
    public function show(string $id): JSONResponse
    {
        try {
            $objectService = $this->getObjectService();
            $config        = $this->settingsService->getSettings();
            if (empty($config['register']) === true || empty($config['kennisartikel_schema']) === true) {
                return new JSONResponse(data: ['error' => 'Not configured'], statusCode: 404);
            }

            $article    = $objectService->findOne(
                register: $config['register'],
                schema: $config['kennisartikel_schema'],
                id: $id
            );
            $status     = ($article['status'] ?? '');
            $visibility = ($article['visibility'] ?? '');
            if ($article === null || $status !== 'gepubliceerd' || $visibility !== 'openbaar') {
                return new JSONResponse(data: ['error' => 'Article not found'], statusCode: 404);
            }

            return new JSONResponse(data: $this->stripInternalFields(article: $article));
        } catch (\Exception $e) {
            $this->logger->error('Public kennisbank show error: '.$e->getMessage());
            return new JSONResponse(data: ['error' => 'Failed to fetch article'], statusCode: 500);
        }//end try
    }//end show()

    /**
     * Strip internal fields from an article for public display.
     *
     * @param array $article The article data.
     *
     * @return array The article with internal fields removed.
     */
    private function stripInternalFields(array $article): array
    {
        unset($article['author'], $article['lastUpdatedBy'], $article['zaaktypeLinks']);
        return $article;
    }//end stripInternalFields()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return object The object service.
     *
     * @throws \RuntimeException If OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }//end getObjectService()
}//end class
