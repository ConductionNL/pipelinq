<?php

/**
 * Pipelinq Health Controller
 *
 * AppHost adopter by COMPOSITION, not inheritance: the OpenRegister AppHost
 * observability engine is resolved lazily out of the DI container by FQCN
 * string, and its result is rendered as the ADR-006
 * `{status, app, version, checks}` envelope. Health-check execution, the
 * status-code policy and the CORS decision come from the engine (declared in
 * the `observability.health` block of `src/manifest.json`); the envelope and
 * the OpenRegister-absent fallback are owned here.
 *
 * ⚠️ This class MUST NOT `extends` — nor name in any resolved position — a
 * class from another app. Nextcloud's router `ReflectionClass()`es every file
 * in `lib/Controller/` while MATCHING a route, so an unresolvable parent makes
 * EVERY route in pipelinq return HTTP 500, not just this one. `extends` is
 * resolved by the autoloader, not the container, so no amount of lazy DI
 * registration can rescue it. pipelinq does not declare
 * `<app>openregister</app>` in appinfo/info.xml, so the parent was
 * unresolvable on any instance without OpenRegister. See decidesk#377 /
 * decidesk#388.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Container\ContainerInterface;

/**
 * Public, declarative health endpoint backed by the AppHost engine.
 *
 * The engine collaborators are pulled from the container by FQCN string at
 * dispatch time, so pipelinq never binds an OpenRegister class at
 * class-declaration time.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
 */
class HealthController extends Controller {

	/**
	 * FQCN of the AppHost observability manifest loader.
	 *
	 * Referenced as a string, never imported: the class only exists when
	 * openregister is installed.
	 *
	 * @var string
	 */
	private const MANIFEST_LOADER = 'OCA\\OpenRegister\\AppHost\\Observability\\ManifestLoader';

	/**
	 * FQCN of the AppHost declarative health-check executor.
	 *
	 * Referenced as a string, never imported: the class only exists when
	 * openregister is installed.
	 *
	 * @var string
	 */
	private const HEALTH_EXECUTOR = 'OCA\\OpenRegister\\AppHost\\Observability\\HealthCheckExecutor';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param IConfig $config The Nextcloud config service (fallback version).
	 * @param ContainerInterface $container DI container — resolves the AppHost engine lazily.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly IConfig $config,
		private readonly ContainerInterface $container,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

	}//end __construct()

	/**
	 * GET /api/health — declarative health check (ADR-006), public probe.
	 *
	 * Runs the manifest-declared checks through the AppHost engine and renders
	 * the `{status, app, version, checks}` envelope with the status code the
	 * engine's policy resolved. CORS headers are emitted only when the
	 * manifest opts in, exactly as the engine does.
	 *
	 * When the AppHost engine cannot be resolved — openregister absent or
	 * disabled — the endpoint still answers (the whole point of a health
	 * probe): `status: degraded`, `checks.openregister: unavailable`, HTTP 200.
	 *
	 * @return JSONResponse `{status, app, version, checks}` with HTTP code per policy.
	 *
	 * @spec openspec/changes/adopt-apphost/tasks.md#task-2.3
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$engine = $this->engineResult();
		if ($engine === null) {
			return new JSONResponse(
				[
					'status' => 'degraded',
					'app' => $this->appName,
					'version' => $this->config->getAppValue(Application::APP_ID, 'installed_version', ''),
					'checks' => ['openregister' => 'unavailable'],
				],
				Http::STATUS_OK
			);
		}

		$response = new JSONResponse(
			[
				'status' => $engine['status'],
				'app' => $this->appName,
				'version' => $engine['version'],
				'checks' => $engine['checks'],
			],
			$engine['httpStatus']
		);

		if ($engine['cors'] === true) {
			$response->addHeader('Access-Control-Allow-Origin', '*');
			$response->addHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
		}

		return $response;
	}//end index()

	/**
	 * Run the AppHost observability engine for this app.
	 *
	 * @return array{status: string, version: string, checks: array<string, string>, httpStatus: int, cors: bool}|null
	 *                                                                                                                 Null when the engine is unavailable (openregister absent/disabled).
	 */
	private function engineResult(): ?array {
		try {
			$manifestLoader = $this->container->get(self::MANIFEST_LOADER);
			$executor = $this->container->get(self::HEALTH_EXECUTOR);

			$appId = $this->appName;
			$manifest = $manifestLoader->load(appId: $appId);
			$result = $executor->execute(manifest: $manifest);

			return [
				'status' => (string)$result->status,
				'version' => (string)$manifestLoader->appVersion(appId: $appId),
				'checks' => (array)$result->checks,
				'httpStatus' => (int)$result->httpStatusCode,
				'cors' => ($manifest->cors === true),
			];
		} catch (\Throwable $e) {
			return null;
		}//end try

	}//end engineResult()
}//end class
