<?php

/**
 * Pipelinq CallOriginator.
 *
 * Posts an originate request to a CTI platform and turns the reply into a call result.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti\Adapter
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;

/**
 * Places an outbound call through a CTI platform's HTTP API.
 *
 * Built by the adapter that owns the HTTP client factory and logger.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
final class CallOriginator {
	/**
	 * Constructor.
	 *
	 * @param IClientService  $clientService HTTP client factory.
	 * @param LoggerInterface $logger        The logger.
	 */
	public function __construct(
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Post the request; a failure is logged as a warning and reported on the result.
	 *
	 * @param OriginateRequest $request The originate request.
	 * @param CtiVendor        $vendor  The platform placing the call.
	 *
	 * @return CtiCallResult The outcome, with the new call id when the reply carries one.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function place(OriginateRequest $request, CtiVendor $vendor): CtiCallResult {
		try {
			$client = $this->clientService->newClient();
			$response = $client->post($request->endpoint->url, $request->options);
			$callId = $this->callIdIn(contents: (string)$response->getBody(), endpoint: $request->endpoint);
			return CtiCallResult::placed(externalCallId: $callId, platform: $vendor->platform);
		} catch (\Throwable $e) {
			return $this->failed(error: $e, vendor: $vendor);
		}
	}//end place()

	/**
	 * Log a failed originate as a warning and report it on the result.
	 *
	 * @param \Throwable $error  What went wrong.
	 * @param CtiVendor  $vendor The platform that failed to place the call.
	 *
	 * @return CtiCallResult The failed result.
	 */
	private function failed(\Throwable $error, CtiVendor $vendor): CtiCallResult {
		$this->logger->warning(
			$vendor->label . ' originate failed',
			['exception' => $error->getMessage()]
		);
		return CtiCallResult::refused(
			error: $vendor->label . ' originate failed: ' . $error->getMessage(),
			platform: $vendor->platform
		);
	}//end failed()

	/**
	 * The call id in a reply body: the first of the endpoint's call-id fields that is set.
	 *
	 * @param string            $contents The raw reply body.
	 * @param OriginateEndpoint $endpoint The endpoint that replied.
	 *
	 * @return string|null The call id, or null when the reply names none.
	 */
	private function callIdIn(string $contents, OriginateEndpoint $endpoint): ?string {
		$body = json_decode($contents, true);
		if (is_array($body) === false) {
			return null;
		}

		foreach ($endpoint->callIdKeys as $key) {
			if (isset($body[$key]) === true) {
				return (string)$body[$key];
			}
		}

		return null;
	}//end callIdIn()
}//end class
