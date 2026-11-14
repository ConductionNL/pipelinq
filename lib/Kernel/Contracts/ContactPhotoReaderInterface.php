<?php

/**
 * Contract of ContactPhotoReader
 *
 * The public surface of ContactPhotoReader that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Pipelinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Kernel\Contracts;

/**
 * Contract of ContactPhotoReader.
 *
 * @category Kernel
 * @package  OCA\Pipelinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/pipelinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ContactPhotoReaderInterface {

	/**
	 * Nextcloud's optical character recognition task type
	 * (OCP\TaskProcessing\TaskTypes\ImageToTextOpticalCharacterRecognition::ID).
	 *
	 * @var string
	 */
	public const TASK_TYPE = 'core:image2text:ocr';

	/**
	 * The largest photo read, in bytes: a phone photo of a card fits easily.
	 *
	 * @var int
	 */
	public const MAX_BYTES = 10485760;

	/**
	 * The photo was read; `text` holds what was recognised.
	 *
	 * @var string
	 */
	public const STATUS_DONE = 'done';

	/**
	 * No provider serves the recognition task type for this user.
	 *
	 * @var string
	 */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/**
	 * The recognition task ran and failed, or could not be run.
	 *
	 * @var string
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * The task succeeded but found no text on the photo.
	 *
	 * @var string
	 */
	public const STATUS_EMPTY = 'empty';

	/**
	 * Whether a recognition provider serves this user.
	 *
	 * @param string|null $userId The user the task would run for.
	 *
	 * @return bool True when `core:image2text:ocr` is an available task type.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
	 */
	public function isAvailable(?string $userId): bool;

	/**
	 * Recognise the text on a photo, keeping nothing afterwards.
	 *
	 * @param string $bytes  The photo.
	 * @param string $userId The user the photo is read for.
	 *
	 * @return array{status: string, text: string} One of the STATUS_ constants and the recognised text.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
	 */
	public function read(string $bytes, string $userId): array;
}//end interface
