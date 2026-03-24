<?php

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

class PublicKennisbankController extends Controller
{
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

            $result   = $objectService->findAll(register: $config['register'], schema: $config['kennisartikel_schema'], filters: $filters);
            $articles = array_map(callback: [$this, 'stripInternalFields'], array: ($result['results'] ?? []));
            return new JSONResponse(data: ['results' => $articles, 'total' => ($result['total'] ?? count(value: $articles))]);
        } catch (\Exception $e) {
            $this->logger->error('Public kennisbank error: '.$e->getMessage());
            return new JSONResponse(data: ['error' => 'Failed to fetch articles'], statusCode: 500);
        }
    }//end index()

    /**
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

            $article = $objectService->findOne(register: $config['register'], schema: $config['kennisartikel_schema'], id: $id);
            if ($article === null || ($article['status'] ?? '') !== 'gepubliceerd' || ($article['visibility'] ?? '') !== 'openbaar') {
                return new JSONResponse(data: ['error' => 'Article not found'], statusCode: 404);
            }

            return new JSONResponse(data: $this->stripInternalFields(article: $article));
        } catch (\Exception $e) {
            $this->logger->error('Public kennisbank show error: '.$e->getMessage());
            return new JSONResponse(data: ['error' => 'Failed to fetch article'], statusCode: 500);
        }
    }//end show()

    private function stripInternalFields(array $article): array
    {
        unset($article['author'], $article['lastUpdatedBy'], $article['zaaktypeLinks']);
        return $article;
    }//end stripInternalFields()

    private function getObjectService(): object
    {
        if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        }

        throw new \RuntimeException('OpenRegister service is not available.');
    }//end getObjectService()
}//end class
