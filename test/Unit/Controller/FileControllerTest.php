<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\FileController;
use App\Entity\File;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use App\Services\RagServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ResponseFactory;

#[AllowMockObjectsWithoutExpectations]
final class FileControllerTest extends TestCase
{
    private SessionInterface $session;

    private EntityManagerInterface $entityManager;

    private Filesystem $filesystem;

    private RagServiceInterface $ragService;

    private FileController $controller;

    private ResponseFactory $responseFactory;

    private User $user;

    private \Doctrine\ORM\EntityRepository $userRepository;

    private ?\App\Repository\FileRepository $fileRepository = null;

    protected function setUp(): void
    {
        $this->session = $this->createMock(SessionInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->ragService = $this->createMock(RagServiceInterface::class);
        $this->responseFactory = new ResponseFactory();

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn('user-123');
        $this->userRepository = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $this->userRepository->method('find')->willReturn($this->user);

        $this->entityManager->method('getRepository')->willReturnCallback(function (string $class) {
            if ($class === User::class) {
                return $this->userRepository;
            }
            if ($class === File::class) {
                return $this->fileRepository;
            }

            return null;
        });

        $this->entityManager->method('getReference')
            ->with(User::class, $this->anything())
            ->willReturn($this->user);

        $this->controller = new FileController(
            $this->entityManager,
            $this->filesystem,
            new \App\Services\Settings([
                'files' => [
                    'upload' => [
                        'path' => 'uploads',
                        'acceptedExt' => '.txt',
                        'forbidden_extensions' => [],
                        'allowed_mime_types' => ['text/plain'],
                    ],
                ],
            ]),
            $this->ragService,
        );
    }

    public function testListReturns403WhenNotLogged(): void
    {
        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn(null);

        $request = $this->createRequestWithSession();
        $response = $this->responseFactory->createResponse();

        $result = $this->controller->list($request, $response);

        $this->assertSame(403, $result->getStatusCode());
    }

    public function testListReturnsFilesList(): void
    {
        $userId = 'user-123';
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $this->fileRepository->expects($this->once())
            ->method('listByUser')
            ->with($userId)
            ->willReturn([]);

        $result = $this->controller->list(
            $this->createRequestWithSession(),
            $this->responseFactory->createResponse(),
        );
        self::assertSame('application/json', $result->getHeaderLine('Content-Type'));
        self::assertSame(['files' => [], 'acceptedExt' => '.txt'],
            json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testListSerializesOnlyPublicFileMetadata(): void
    {
        $file = new File();
        $file->setFileId('file-1');
        $file->setFilename('<report>.txt');
        $file->setMimeType('text/plain');
        $file->setSizeBytes(1234);
        $file->setFilePath('/private/path');
        $file->onPrePersist();
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $this->fileRepository->expects(self::once())->method('listByUser')->with('user-123')->willReturn([$file]);
        $result = $this->controller->list($this->createRequestWithSession(), $this->responseFactory->createResponse());
        self::assertSame(['files' => [[
            'fileId' => 'file-1', 'filename' => '<report>.txt', 'mimeType' => 'text/plain',
            'sizeBytes' => 1234, 'createdAt' => $file->getCreatedAt()->format(DATE_ATOM),
        ]], 'acceptedExt' => '.txt'], json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testCountReturnsNumber(): void
    {
        $userId = 'user-123';
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $this->fileRepository->expects($this->once())
            ->method('countByUserId')
            ->with($userId)
            ->willReturn(5);

        $result = $this->controller->count(
            $this->createRequestWithSession(),
            $this->responseFactory->createResponse(),
        );

        $this->assertSame('5', (string) $result->getBody());
    }

    public function testUploadSavesFile(): void
    {
        $userId = 'user-123';
        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getClientFilename')->willReturn('test.txt');
        $uploadedFile->method('getClientMediaType')->willReturn('text/plain');
        $uploadedFile->method('getSize')->willReturn(123);
        $uploadedFile->method('getStream')->willReturn($stream);

        $stream->expects($this->exactly(2))->method('rewind');
        $stream->method('getContents')->willReturn('file content');

        $request = $this->createRequestWithSession(['file' => $uploadedFile]);

        $this->filesystem->expects($this->once())
            ->method('write')
            ->with($this->stringContains('uploads/user-123/'), 'file content');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->fileRepository->method('listByUser')->with($userId)->willReturn([]);
        $this->controller->upload($request, $this->responseFactory->createResponse());
    }

    public function testDeleteRemovesFile(): void
    {
        $userId = 'user-123';
        $fileId = 'file-789';
        $file = $this->createMock(File::class);
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $this->user->method('getId')->willReturn($userId);
        $file->method('getUser')->willReturn($this->user);
        $file->method('getFileId')->willReturn('internal-id');
        $file->method('getFilePath')->willReturn('internal-id');

        $this->fileRepository->method('findOneBy')->willReturn($file);
        $this->fileRepository->method('listByUser')->with($userId)->willReturn([]);

        $request = $this->createRequestWithSession(attributeId: $fileId);

        $this->filesystem->method('fileExists')->willReturn(true);
        $this->filesystem->expects($this->once())
            ->method('delete')
            ->with('internal-id');

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($file);
        $this->entityManager->expects($this->once())->method('flush');
        $this->controller->delete($request, $this->responseFactory->createResponse());
    }

    public function testServeReturnsFileContent(): void
    {
        $userId = 'user-123';
        $id = 'file-uuid';
        $path = 'uploads/user-123/file-uuid.png';
        $file = $this->createMock(File::class);
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')->with(Auth::USERID)->willReturn($userId);

        $file->method('getFilePath')->willReturn($path);
        $this->fileRepository->method('findOneBy')->with(['fileId' => $id, 'user' => $this->user])->willReturn($file);
        $this->filesystem->method('fileExists')->with($path)->willReturn(true);
        $this->filesystem->method('mimeType')->with($path)->willReturn('image/png');
        $this->filesystem->method('read')->with($path)->willReturn('binary data');

        $request = $this->createRequestWithSession(attributeId: $id);
        $response = $this->controller->serve($request, $this->responseFactory->createResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertSame('binary data', (string) $response->getBody());
    }

    public function testServeReturnsPdfWithDisplayName(): void
    {
        $userId = 'user-123';
        $id = 'file-uuid.pdf';
        $path = 'generated/user-123/file-uuid.pdf';
        $file = $this->createMock(File::class);
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')->with(Auth::USERID)->willReturn($userId);

        $file->method('getFilePath')->willReturn($path);
        $file->method('getFilename')->willReturn('mon-rapport.pdf');
        $file->method('fileType')->willReturn(File::FILE_TYPE_PDF);

        $this->fileRepository->method('findOneBy')->with(['fileId' => $id, 'user' => $this->user])->willReturn($file);
        $this->filesystem->method('fileExists')->with($path)->willReturn(true);
        $this->filesystem->method('mimeType')->with($path)->willReturn('application/pdf');
        $this->filesystem->method('read')->with($path)->willReturn('pdf data');

        $request = $this->createRequestWithSession(attributeId: $id);
        $response = $this->controller->serve($request, $this->responseFactory->createResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="mon-rapport.pdf"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame("sandbox; default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testServeForeignAndMissingFilesReturnIdenticalResponses(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $this->fileRepository->expects(self::exactly(2))->method('findOneBy')
            ->willReturnCallback(function (array $criteria): ?File {
                self::assertSame($this->user, $criteria['user']);
                self::assertContains($criteria['fileId'], ['foreign-file', 'missing-file']);
                return null;
            });
        $this->filesystem->expects(self::never())->method('fileExists');
        $this->filesystem->expects(self::never())->method('mimeType');
        $this->filesystem->expects(self::never())->method('read');

        $foreign = $this->controller->serve(
            $this->createRequestWithSession(attributeId: 'foreign-file'), $this->responseFactory->createResponse(),
        );
        $missing = $this->controller->serve(
            $this->createRequestWithSession(attributeId: 'missing-file'), $this->responseFactory->createResponse(),
        );

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame($foreign->getStatusCode(), $missing->getStatusCode());
        self::assertSame($foreign->getHeaders(), $missing->getHeaders());
        self::assertSame('', (string) $foreign->getBody());
        self::assertSame((string) $foreign->getBody(), (string) $missing->getBody());
    }

    public function testServeWithoutIdentityDoesNotQueryFiles(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn(null);
        $this->entityManager->expects(self::never())->method('getRepository');
        $this->filesystem->expects(self::never())->method('read');

        $response = $this->controller->serve(
            $this->createRequestWithSession(attributeId: 'private-file'), $this->responseFactory->createResponse(),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
    }

    public function testServeHeadReturnsProtectedHeadersWithoutReadingContent(): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $file = new File();
        $file->setFilename('legacy.html');
        $file->setFilePath('uploads/legacy.html');
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $this->fileRepository->expects(self::once())->method('findOneBy')
            ->with(['fileId' => 'private-file', 'user' => $this->user])->willReturn($file);
        $this->filesystem->method('fileExists')->with('uploads/legacy.html')->willReturn(true);
        $this->filesystem->method('mimeType')->with('uploads/legacy.html')->willReturn('text/html');
        $this->filesystem->expects(self::once())->method('fileSize')->with('uploads/legacy.html')->willReturn(123);
        $this->filesystem->expects(self::never())->method('read');
        $request = $this->createRequestWithSession(attributeId: 'private-file');
        $request->method('getMethod')->willReturn('HEAD');

        $response = $this->controller->serve($request, $this->responseFactory->createResponse());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame('123', $response->getHeaderLine('Content-Length'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertSame('attachment; filename="legacy.html"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("sandbox; default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testUploadRagSavesFileAndAddsToVectorStore(): void
    {
        $userId = 'user-123';
        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $stream = $this->createMock(StreamInterface::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getClientFilename')->willReturn('test.txt');
        $uploadedFile->method('getClientMediaType')->willReturn('text/plain');
        $uploadedFile->method('getSize')->willReturn(123);
        $uploadedFile->method('getStream')->willReturn($stream);

        $stream->expects($this->exactly(2))->method('rewind');
        $stream->method('getContents')
            ->willReturn('file content. very long content to split.');

        $this->ragService->expects($this->once())->method('createFromFile');

        $this->filesystem->expects($this->once())->method('write');
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->controller->uploadRag(
            $this->createRequestWithSession(['file' => $uploadedFile]),
            $this->responseFactory->createResponse(),
        );

        $this->assertSame(201, $result->getStatusCode());
    }

    public static function svgUploads(): iterable
    {
        yield ['evil.SVG', 'text/plain', 'plain'];
        yield ['evil.svgz', 'application/gzip', 'plain'];
        yield ['evil.txt', 'image/svg+xml', 'plain'];
        yield ['evil.txt', 'text/plain', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'];
        yield ['evil.png', 'image/png', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('svgUploads')]
    public function testRejectsSvgInBothUploads(string $name, string $mime, string $content): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $upload = new \Slim\Psr7\UploadedFile(
            \GuzzleHttp\Psr7\Utils::streamFor($content), $name, $mime, strlen($content), UPLOAD_ERR_OK
        );
        $this->filesystem->expects($this->never())->method('write');
        $this->entityManager->expects($this->never())->method('persist');
        $this->ragService->expects($this->never())->method('createFromFile');
        foreach (['upload', 'uploadRag'] as $method) {
            $result = $this->controller->$method(
                $this->createRequestWithSession(['file' => $upload]), $this->responseFactory->createResponse()
            );
            self::assertSame(400, $result->getStatusCode());
        }
    }

    public static function activeFiles(): iterable
    {
        yield ['old.svg', 'image/svg+xml'];
        yield ['old.SVG', 'image/png'];
        yield ['old.html', 'text/html'];
        yield ['old.xml', 'application/xml'];
        yield ['old.xhtml', 'application/xhtml+xml'];
        yield ['old.js', 'application/javascript'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('activeFiles')]
    public function testActiveFilesAreSandboxedDownloads(string $name, string $mime): void
    {
        $this->session->method('get')->with(Auth::USERID)->willReturn('user-123');
        $file = new File();
        $file->setFilename($name);
        $file->setFilePath('uploads/' . $name);
        $this->fileRepository = $this->createMock(\App\Repository\FileRepository::class);
        $this->fileRepository->method('findOneBy')->willReturn($file);
        $this->filesystem->method('fileExists')->willReturn(true);
        $this->filesystem->method('mimeType')->willReturn($mime);
        $this->filesystem->method('read')->willReturn('<svg onload="alert(1)"/>');
        $response = $this->controller->serve($this->createRequestWithSession(), $this->responseFactory->createResponse());
        self::assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment;', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("sandbox; default-src 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    private function createRequestWithSession(
        array $uploadedFiles = [],
        ?string $attributeId = null,
    ): ServerRequestInterface {
        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getAttribute')
            ->willReturnCallback(function (string $name) use ($attributeId) {
                return match ($name) {
                    JwtSessionMiddleware::SESSION_ATTRIBUTE => $this->session,
                    'id' => $attributeId,
                    default => null,
                };
            });

        $request->method('getUploadedFiles')->willReturn($uploadedFiles);

        return $request;
    }
}
