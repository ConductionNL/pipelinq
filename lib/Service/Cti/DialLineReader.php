<?php

/**
 * Pipelinq DialLineReader.
 *
 * Says whether the signed-in agent can place a call through the telephony
 * platform, and from which extension.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service\Cti
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/specify-capability-rows/tasks.md#task-36.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\DialLineReaderInterface;
use OCA\Pipelinq\Service\BackgroundServiceAccount;
use OCA\Pipelinq\Service\CtiService;
use OCA\Pipelinq\Util\SerialisedToArray;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The agent's own dial line: on or off, the extension, and whether it is busy.
 *
 * The extension is the one the telephony platform last reported for the
 * agent in `ctiAgentPresence`. Those rows are written by the presence
 * webhook, not by a user, so the read runs as the background service account
 * and the row's `user_id` is compared again here: an agent only ever learns
 * their own extension.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
 */
class DialLineReader implements DialLineReaderInterface {
	use SerialisedToArray;

	/**
	 * The presence states during which no new call may be placed.
	 */
	private const BUSY_STATES = ['on-call', 'wrap-up'];

	/**
	 * Constructor.
	 *
	 * @param CtiService $ctiService Reads the telephony configuration.
	 * @param IAppConfig $appConfig Reads which register and schema hold the presence rows.
	 * @param ContainerInterface $container Finds OpenRegister's object service at run time.
	 * @param BackgroundServiceAccount $account The account the presence read runs as.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private CtiService $ctiService,
		private IAppConfig $appConfig,
		private ContainerInterface $container,
		private BackgroundServiceAccount $account,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The agent's dial line.
	 *
	 * Off when no platform is configured, when the administrator switched
	 * click-to-dial off, or when no extension is known for the agent.
	 *
	 * @param string $userId The agent's Nextcloud uid.
	 *
	 * @return array{enabled: bool, extension: string, busy: bool}
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
	 */
	public function forAgent(string $userId): array {
		$off = ['enabled' => false, 'extension' => '', 'busy' => false];

		$config = $this->ctiService->loadConfig();
		if ($userId === '' || (string)($config['platform'] ?? '') === '' || ($config['click_to_dial_enabled'] ?? true) === false) {
			return $off;
		}

		$presence = $this->presenceOf(userId: $userId);
		$extension = trim((string)($presence['extension'] ?? ''));
		if ($extension === '') {
			return $off;
		}

		return [
			'enabled' => true,
			'extension' => $extension,
			'busy' => in_array((string)($presence['presence_state'] ?? ''), self::BUSY_STATES, true),
		];
	}//end forAgent()

	/**
	 * The agent's presence row, or an empty array when there is none or it cannot be read.
	 *
	 * @param string $userId The agent's Nextcloud uid.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
	 */
	private function presenceOf(string $userId): array {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(AppIdentity::APP_ID, 'ctiAgentPresence_schema', '');
		if ($register === '' || $schema === '' || $this->account->status()['usable'] !== true) {
			return [];
		}

		try {
			$rows = $this->account->runAs(
				operation: fn (): array => (array)$this->container->get('OCA\\OpenRegister\\Service\\ObjectService')->findAll(
					config: [
						'filters' => ['register' => $register, 'schema' => $schema, 'user_id' => $userId],
						'limit' => 1,
					],
					_rbac: true,
					_multitenancy: false,
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('DialLineReader: the agent presence could not be read', ['exception' => $e->getMessage()]);
			return [];
		}

		foreach ($rows as $row) {
			$data = $this->toArray(value: $row);
			// Compared again: a store that ignored the filter must not hand an agent a colleague's extension.
			if (($data['user_id'] ?? null) === $userId) {
				return $data;
			}
		}

		return [];
	}//end presenceOf()
}//end class
