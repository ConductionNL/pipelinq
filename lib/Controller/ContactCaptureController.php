<?php

/**
 * Pipelinq contact capture controller.
 *
 * POST /api/contact-capture/parse: pasted text, or a photo of a business
 * card, in; proposed contact fields out. GET /api/contact-capture/status says
 * whether photos can be read on this server. Neither reads or stores an
 * object, so they need a signed-in user and no per-object check (D4). A photo
 * is read through ContactPhotoReader, which keeps nothing (D2).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use finfo;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\ContactCaptureServiceInterface;
use OCA\Pipelinq\Kernel\Contracts\ContactPhotoReaderInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Proposes contact fields for pasted text or a card photo.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
 */
class ContactCaptureController extends Controller {

	/**
	 * The image types a card photo may have, as read from the file itself.
	 *
	 * @var list<string>
	 */
	public const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/tiff', 'image/bmp', 'image/heic', 'image/heif'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                         $request     The request.
	 * @param ContactCaptureServiceInterface   $capture     The field proposal.
	 * @param IUserSession                     $userSession The caller.
	 * @param ContactPhotoReaderInterface|null $photos      The card photo reader; without it photos are refused as unavailable.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
	 */
	public function __construct(
		IRequest $request,
		private readonly ContactCaptureServiceInterface $capture,
		private readonly IUserSession $userSession,
		private readonly ?ContactPhotoReaderInterface $photos = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Propose fields for the posted `text`, or for the text on the posted `file` (a card photo).
	 *
	 * @return JSONResponse The proposal; 400 without text, 413 for more than a signature's worth or too large a photo,
	 *                      415 for a file that is not an image, 409 when photos cannot be read on this server,
	 *                      422 when no text was found on the photo, 502 when recognition failed, 401 without a caller.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
	 */
	#[NoAdminRequired]
	public function parse(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Sign in to read contact details.'], Http::STATUS_UNAUTHORIZED);
		}

		$file = $this->request->getUploadedFile('file');
		if (is_array($file) === true) {
			return $this->parsePhoto(file: $file, userId: $user->getUID());
		}

		$text = $this->request->getParam('text', '');
		if (is_string($text) === false || trim($text) === '') {
			return new JSONResponse(['error' => 'Paste the text to read.'], Http::STATUS_BAD_REQUEST);
		}

		if (mb_strlen($text, 'UTF-8') > ContactCaptureServiceInterface::MAX_LENGTH) {
			return new JSONResponse(['error' => 'The text is too long to be a signature or a card.'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		}

		return new JSONResponse($this->capture->parse(text: $text, userId: $user->getUID()));
	}//end parse()

	/**
	 * Whether photos can be read on this server, for the dialog to offer or explain the photo option.
	 *
	 * @return JSONResponse `{photo: bool, maxPhotoBytes: int}`; 401 without a caller.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-no-recognition-provider
	 */
	#[NoAdminRequired]
	public function status(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Sign in to read contact details.'], Http::STATUS_UNAUTHORIZED);
		}

		$photo = ($this->photos !== null && $this->photos->isAvailable(userId: $user->getUID()) === true);

		return new JSONResponse(['photo' => $photo, 'maxPhotoBytes' => ContactPhotoReaderInterface::MAX_BYTES]);
	}//end status()

	/**
	 * Read an uploaded card photo and propose fields for its text.
	 *
	 * @param array<string, mixed> $file   The `$_FILES` entry.
	 * @param string               $userId The caller.
	 *
	 * @return JSONResponse The proposal with the recognised `text`, or the refusal.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-a-sales-rep-scans-a-card-at-a-fair
	 */
	private function parsePhoto(array $file, string $userId): JSONResponse {
		$bytes = $this->photoBytes(file: $file);
		if ($bytes instanceof JSONResponse) {
			return $bytes;
		}

		if ($this->photos === null) {
			return $this->unavailable();
		}

		$read = $this->photos->read(bytes: $bytes, userId: $userId);
		$text = $read['text'];

		return match ($read['status']) {
			ContactPhotoReaderInterface::STATUS_DONE => new JSONResponse(['text' => $text] + $this->capture->parse(text: $text, userId: $userId)),
			ContactPhotoReaderInterface::STATUS_UNAVAILABLE => $this->unavailable(),
			ContactPhotoReaderInterface::STATUS_EMPTY => new JSONResponse(
				['error' => 'No text was found on the photo.', 'reason' => 'empty'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			),
			default => new JSONResponse(['error' => 'The photo could not be read.', 'reason' => 'failed'], Http::STATUS_BAD_GATEWAY),
		};
	}//end parsePhoto()

	/**
	 * The bytes of an uploaded photo, or the refusal: too large, not uploaded, or not an image by its content.
	 *
	 * @param array<string, mixed> $file The `$_FILES` entry.
	 *
	 * @return string|JSONResponse The photo, or a 413, 400 or 415.
	 *
	 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
	 */
	private function photoBytes(array $file): string|JSONResponse {
		$tooLarge = new JSONResponse(['error' => 'The photo is too large.'], Http::STATUS_REQUEST_ENTITY_TOO_LARGE);
		$error    = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
		$size     = (int) ($file['size'] ?? 0);
		if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) === true || $size > ContactPhotoReaderInterface::MAX_BYTES) {
			return $tooLarge;
		}

		$path = (string) ($file['tmp_name'] ?? '');
		if ($error !== UPLOAD_ERR_OK || $path === '' || is_readable($path) === false) {
			return new JSONResponse(['error' => 'The photo could not be uploaded.'], Http::STATUS_BAD_REQUEST);
		}

		$bytes = (string) file_get_contents($path);
		if (strlen($bytes) > ContactPhotoReaderInterface::MAX_BYTES) {
			return $tooLarge;
		}

		$type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
		if (in_array($type, self::PHOTO_TYPES, true) === false) {
			return new JSONResponse(['error' => 'Choose a photo: the file is not an image.'], Http::STATUS_UNSUPPORTED_MEDIA_TYPE);
		}

		return $bytes;
	}//end photoBytes()

	/**
	 * The answer when no recognition provider serves the caller.
	 *
	 * @return JSONResponse A 409 the dialog explains.
	 */
	private function unavailable(): JSONResponse {
		return new JSONResponse(['error' => 'Photos cannot be read on this server.', 'reason' => 'unavailable'], Http::STATUS_CONFLICT);
	}//end unavailable()
}//end class
