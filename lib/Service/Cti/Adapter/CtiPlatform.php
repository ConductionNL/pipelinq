<?php

/**
 * Pipelinq CtiPlatform.
 *
 * Names a CTI platform on its call results and log lines.
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
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service\Cti\Adapter;

use OCA\Pipelinq\Service\Cti\Result\CtiCallResult;

/**
 * The identifier and display name of one CTI platform, and the call results it reports.
 *
 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
 */
final class CtiPlatform {
	/**
	 * Constructor.
	 *
	 * @param string $platform The platform identifier carried on call results.
	 * @param string $label    The display name used in log lines and errors.
	 */
	public function __construct(
		public readonly string $platform,
		public readonly string $label,
	) {
	}//end __construct()

	/**
	 * An accepted originate request on this platform.
	 *
	 * @param string|null $externalCallId The platform's call id, when the reply carries one.
	 *
	 * @return CtiCallResult The successful result.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function placed(?string $externalCallId): CtiCallResult {
		return new CtiCallResult(
			success: true,
			externalCallId: $externalCallId,
			platform: $this->platform,
		);
	}//end placed()

	/**
	 * A refused or failed originate request on this platform.
	 *
	 * @param string $error Why the call was not placed.
	 *
	 * @return CtiCallResult The failed result.
	 *
	 * @spec openspec/changes/archive/2026-06-14-cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public function refused(string $error): CtiCallResult {
		return new CtiCallResult(
			success: false,
			error: $error,
			platform: $this->platform,
		);
	}//end refused()
}//end class
