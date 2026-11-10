<?php

/**
 * Pipelinq BearerJsonPost.
 *
 * HTTP options of a JSON POST authorised with a bearer token.
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

/**
 * Builds the client options of a JSON POST that carries a bearer token.
 *
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
 */
final class BearerJsonPost {
	/**
	 * The client options: bearer authorisation, JSON content type and body, 10 s timeout.
	 *
	 * @param string              $token The bearer token.
	 * @param array<string,mixed> $body  The JSON body.
	 *
	 * @return array<string,mixed> The client options.
	 *
	 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-1.1
	 */
	public static function options(string $token, array $body): array {
		return [
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type' => 'application/json',
			],
			'body' => json_encode($body),
			'timeout' => 10,
		];
	}//end options()
}//end class
