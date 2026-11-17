<?php

/**
 * Unit tests for ContactCaptureController.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ContactCaptureController;
use OCA\Pipelinq\Kernel\Contracts\ContactPhotoReaderInterface;
use OCA\Pipelinq\Service\ContactCaptureService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The parse route: a signed-in user, some text or a card photo, nothing stored;
 * and the status route the dialog asks whether photos can be read.
 *
 * @covers \OCA\Pipelinq\Controller\ContactCaptureController
 */
class ContactCaptureControllerTest extends TestCase {

	/**
	 * The proposal double.
	 *
	 * @var ContactCaptureService&MockObject
	 */
	private ContactCaptureService&MockObject $capture;

	/**
	 * Set up the double.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->capture = $this->createMock(ContactCaptureService::class);
	}//end setUp()

	/**
	 * The controller for a request.
	 *
	 * @param mixed $text   The posted text.
	 * @param bool  $signed Whether a user is signed in.
	 *
	 * @return ContactCaptureController The controller.
	 */
	private function controller(mixed $text, bool $signed = true): ContactCaptureController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('text', '')->willReturn($text);
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($signed === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('rep');
		}

		$session->method('getUser')->willReturn($user);

		return new ContactCaptureController(request: $request, capture: $this->capture, userSession: $session);
	}//end controller()

	/**
	 * The proposal for the text is answered for the signed-in user.
	 *
	 * @return void
	 */
	public function testTheProposalIsAnswered(): void {
		$proposal = ['fields' => ['email' => 'a@b.nl'], 'emails' => ['a@b.nl'], 'phones' => [], 'unassigned' => [], 'labelledBy' => 'rules'];
		$this->capture->expects($this->once())->method('parse')->with('a@b.nl', 'rep')->willReturn($proposal);

		$response = $this->controller(text: 'a@b.nl')->parse();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($proposal, $response->getData());
	}//end testTheProposalIsAnswered()

	/**
	 * No text, blank text or a non-string is a 400 and nothing is parsed.
	 *
	 * @return void
	 */
	public function testNoTextIs400(): void {
		$this->capture->expects($this->never())->method('parse');

		foreach (['', '   ', ['a']] as $text) {
			$response = $this->controller(text: $text)->parse();
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'Paste the text to read.'], $response->getData());
		}
	}//end testNoTextIs400()

	/**
	 * More than a signature's worth of text is a 413.
	 *
	 * @return void
	 */
	public function testTooMuchTextIs413(): void {
		$this->capture->expects($this->never())->method('parse');

		$response = $this->controller(text: str_repeat('é', ContactCaptureService::MAX_LENGTH + 1))->parse();

		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $response->getStatus());
		$this->assertSame(Http::STATUS_OK, $this->controllerAccepting(text: str_repeat('é', ContactCaptureService::MAX_LENGTH))->parse()->getStatus());
	}//end testTooMuchTextIs413()

	/**
	 * Without a signed-in user nothing is parsed.
	 *
	 * @return void
	 */
	public function testWithoutAUserIs401(): void {
		$this->capture->expects($this->never())->method('parse');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(text: 'a@b.nl', signed: false)->parse()->getStatus());
	}//end testWithoutAUserIs401()

	/**
	 * A controller whose service answers an empty proposal.
	 *
	 * @param string $text The posted text.
	 *
	 * @return ContactCaptureController The controller.
	 */
	private function controllerAccepting(string $text): ContactCaptureController {
		$this->capture = $this->createMock(ContactCaptureService::class);
		$this->capture->method('parse')->willReturn(['fields' => [], 'emails' => [], 'phones' => [], 'unassigned' => [], 'labelledBy' => 'rules']);
		return $this->controller(text: $text);
	}//end controllerAccepting()
	/**
	 * A one-pixel PNG.
	 *
	 * @var string
	 */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

	/**
	 * Temporary upload files to remove after the test.
	 *
	 * @var list<string>
	 */
	private array $uploads = [];

	/**
	 * Remove the temporary uploads.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->uploads as $path) {
			if (is_file($path) === true) {
				unlink($path);
			}
		}
	}//end tearDown()

	/**
	 * A `$_FILES` entry for bytes written to a temporary file.
	 *
	 * @param string $bytes The uploaded bytes.
	 * @param int    $error The upload error code.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function upload(string $bytes, int $error = UPLOAD_ERR_OK): array {
		$path = (string) tempnam(sys_get_temp_dir(), 'pq-card');
		file_put_contents($path, $bytes);
		$this->uploads[] = $path;

		return ['name' => 'card.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => $error, 'size' => strlen($bytes)];
	}//end upload()

	/**
	 * The controller for a photo upload.
	 *
	 * @param array<string, mixed>|null        $file   The `$_FILES` entry.
	 * @param ContactPhotoReaderInterface|null $photos The reader.
	 * @param bool                             $signed Whether a user is signed in.
	 *
	 * @return ContactCaptureController The controller.
	 */
	private function photoController(?array $file, ?ContactPhotoReaderInterface $photos, bool $signed = true): ContactCaptureController {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('file')->willReturn($file);
		$request->method('getParam')->willReturn('');
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($signed === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('rep');
		}

		$session->method('getUser')->willReturn($user);

		return new ContactCaptureController(request: $request, capture: $this->capture, userSession: $session, photos: $photos);
	}//end photoController()

	/**
	 * A reader double answering one read.
	 *
	 * @param array{status: string, text: string}|null $read What read() answers; null expects no read.
	 *
	 * @return ContactPhotoReaderInterface&MockObject The reader.
	 */
	private function reader(?array $read): ContactPhotoReaderInterface&MockObject {
		$reader = $this->createMock(ContactPhotoReaderInterface::class);
		if ($read === null) {
			$reader->expects($this->never())->method('read');
			return $reader;
		}

		$reader->expects($this->once())->method('read')->with(base64_decode(self::PNG), 'rep')->willReturn($read);
		return $reader;
	}//end reader()

	/**
	 * A read photo's text goes through the same proposal, and the text comes back with it.
	 *
	 * @return void
	 */
	public function testAPhotoIsReadIntoTheProposal(): void {
		$proposal = ['fields' => ['email' => 'anna@voorbeeld.nl'], 'emails' => ['anna@voorbeeld.nl'], 'phones' => [], 'unassigned' => ['Anna de Vries'], 'labelledBy' => 'rules'];
		$this->capture->expects($this->once())->method('parse')->with("Anna de Vries\nanna@voorbeeld.nl", 'rep')->willReturn($proposal);
		$reader = $this->reader(read: ['status' => 'done', 'text' => "Anna de Vries\nanna@voorbeeld.nl"]);

		$response = $this->photoController(file: $this->upload(bytes: base64_decode(self::PNG)), photos: $reader)->parse();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['text' => "Anna de Vries\nanna@voorbeeld.nl"] + $proposal, $response->getData());
	}//end testAPhotoIsReadIntoTheProposal()

	/**
	 * Without a recognition provider, or without a reader at all, a photo is a 409 the dialog explains.
	 *
	 * @return void
	 */
	public function testAPhotoWithoutAProviderIs409(): void {
		$this->capture->expects($this->never())->method('parse');
		$png = base64_decode(self::PNG);

		$response = $this->photoController(file: $this->upload(bytes: $png), photos: $this->reader(read: ['status' => 'unavailable', 'text' => '']))->parse();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['error' => 'Photos cannot be read on this server.', 'reason' => 'unavailable'], $response->getData());

		$this->assertSame(Http::STATUS_CONFLICT, $this->photoController(file: $this->upload(bytes: $png), photos: null)->parse()->getStatus());
	}//end testAPhotoWithoutAProviderIs409()

	/**
	 * A failed recognition is a 502 and a photo without text a 422; neither is parsed.
	 *
	 * @return void
	 */
	public function testAFailedOrEmptyReadIsRefused(): void {
		$this->capture->expects($this->never())->method('parse');
		$png = base64_decode(self::PNG);

		$failed = $this->photoController(file: $this->upload(bytes: $png), photos: $this->reader(read: ['status' => 'failed', 'text' => '']))->parse();
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $failed->getStatus());
		$this->assertSame('failed', $failed->getData()['reason']);

		$empty = $this->photoController(file: $this->upload(bytes: $png), photos: $this->reader(read: ['status' => 'empty', 'text' => '']))->parse();
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $empty->getStatus());
		$this->assertSame('empty', $empty->getData()['reason']);
	}//end testAFailedOrEmptyReadIsRefused()

	/**
	 * Too large a photo is a 413, a file that is not an image a 415 and a broken upload a 400; none is read.
	 *
	 * @return void
	 */
	public function testABadUploadIsNeverRead(): void {
		$this->capture->expects($this->never())->method('parse');

		$large = $this->upload(bytes: base64_decode(self::PNG));
		$large['size'] = (ContactPhotoReaderInterface::MAX_BYTES + 1);
		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $this->photoController(file: $large, photos: $this->reader(read: null))->parse()->getStatus());

		$ini = $this->upload(bytes: '', error: UPLOAD_ERR_INI_SIZE);
		$this->assertSame(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, $this->photoController(file: $ini, photos: $this->reader(read: null))->parse()->getStatus());

		$text = $this->photoController(file: $this->upload(bytes: "BEGIN:VCARD\nFN:Anna\nEND:VCARD\n"), photos: $this->reader(read: null))->parse();
		$this->assertSame(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, $text->getStatus());
		$this->assertSame(['error' => 'Choose a photo: the file is not an image.'], $text->getData());

		$partial = $this->upload(bytes: base64_decode(self::PNG), error: UPLOAD_ERR_PARTIAL);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->photoController(file: $partial, photos: $this->reader(read: null))->parse()->getStatus());

		$missing = ['name' => 'card.png', 'tmp_name' => '/nonexistent/pq-card', 'error' => UPLOAD_ERR_OK, 'size' => 10];
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->photoController(file: $missing, photos: $this->reader(read: null))->parse()->getStatus());
	}//end testABadUploadIsNeverRead()

	/**
	 * Without a signed-in user a photo is not read either.
	 *
	 * @return void
	 */
	public function testAPhotoWithoutAUserIs401(): void {
		$response = $this->photoController(file: $this->upload(bytes: base64_decode(self::PNG)), photos: $this->reader(read: null), signed: false)->parse();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testAPhotoWithoutAUserIs401()

	/**
	 * The status says whether photos can be read for the caller, and the size limit.
	 *
	 * @return void
	 */
	public function testTheStatusSaysWhetherPhotosCanBeRead(): void {
		$reader = $this->createMock(ContactPhotoReaderInterface::class);
		$reader->expects($this->exactly(2))->method('isAvailable')->with('rep')->willReturnOnConsecutiveCalls(true, false);

		$this->assertSame(['photo' => true, 'maxPhotoBytes' => 10485760], $this->photoController(file: null, photos: $reader)->status()->getData());
		$this->assertSame(['photo' => false, 'maxPhotoBytes' => 10485760], $this->photoController(file: null, photos: $reader)->status()->getData());
		$this->assertSame(['photo' => false, 'maxPhotoBytes' => 10485760], $this->photoController(file: null, photos: null)->status()->getData());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->photoController(file: null, photos: $reader, signed: false)->status()->getStatus());
	}//end testTheStatusSaysWhetherPhotosCanBeRead()
}//end class
