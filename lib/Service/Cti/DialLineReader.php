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

use OCA\Pipelinq\Kernel\Contracts\DialLineReaderInterface;
use OCA\Pipelinq\Service\CtiService;

/**
 * The agent's own dial line: on or off, the extension, and whether it is busy.
 *
 * The extension is the one the telephony platform last reported for the
 * agent in `ctiAgentPresence`, as AgentPresenceReader reads it.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-click-to-dial-outbound-req-cti-003
 */
class DialLineReader implements DialLineReaderInterface {
	/**
	 * The presence states during which no new call may be placed.
	 */
	private const BUSY_STATES = ['on-call', 'wrap-up'];

	/**
	 * Constructor.
	 *
	 * @param CtiService $ctiService Reads the telephony configuration.
	 * @param AgentPresenceReader $presence Reads the agent's presence row.
	 */
	public function __construct(
		private CtiService $ctiService,
		private AgentPresenceReader $presence,
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

		$presence = $this->presence->presenceOf(userId: $userId);
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
}//end class
