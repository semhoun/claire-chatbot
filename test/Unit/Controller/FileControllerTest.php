<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\FileController;
use App\Entity\File;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Services\Auth;
use App\Services\Session\SessionInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Views\Twig;

#[AllowMockObjectsWithoutExpectations]
final class FileControllerTest extends TestCase
{
    private Twig $twig;

    private SessionInterface $session;

    private EntityManagerInterface $entityManager;

    private Filesystem $filesystem;

    private ContainerInterface $container;

    private FileController $controller;

    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->twig = $this->createMock(Twig::class);
        $this->session = $this->createMock(SessionInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->filesystem = $this->createMock(Filesystem::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->responseFactory = new ResponseFactory();

        $this->controller = new FileController(
            $this->twig,
            $this->entityManager,
            $this->filesystem,
            $this->container,
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
        $repository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $repository->expects($this->once())
            ->method('listByUser')
            ->with($userId)
            ->willReturn([]);

        $this->entityManager->method('getRepository')
            ->with(File::class)
            ->willReturn($repository);

        $this->twig->expects($this->once())
            ->method('render')
            ->with(
                $this->isInstanceOf(\Psr\Http\Message\ResponseInterface::class),
                'partials/files_list.twig',
                ['files' => []],
            )
            ->willReturn($this->responseFactory->createResponse());

        $this->controller->list(
            $this->createRequestWithSession(),
            $this->responseFactory->createResponse(),
        );
    }

    public function testCountReturnsNumber(): void
    {
        $userId = 'user-123';
        $repository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $repository->expects($this->once())
            ->method('countByUserId')
            ->with($userId)
            ->willReturn(5);

        $this->entityManager->method('getRepository')
            ->with(File::class)
            ->willReturn($repository);

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
        $user = $this->createMock(User::class);
        $repository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getClientFilename')->willReturn('test.txt');
        $uploadedFile->method('getClientMediaType')->willReturn('text/plain');
        $uploadedFile->method('getSize')->willReturn(123);
        $uploadedFile->method('getStream')->willReturn($stream);

        $stream->expects($this->once())->method('rewind');
        $stream->method('getContents')->willReturn('file content');

        $request = $this->createRequestWithSession(['file' => $uploadedFile]);

        $this->entityManager->method('getReference')
            ->with(User::class, $userId)
            ->willReturn($user);

        $this->filesystem->expects($this->once())
            ->method('write')
            ->with($this->isString(), 'file content');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');
        $this->entityManager->method('getRepository')
            ->with(File::class)
            ->willReturn($repository);

        $repository->method('listByUser')->with($userId)->willReturn([]);
        $this->twig->method('render')->willReturn($this->responseFactory->createResponse());

        $this->controller->upload($request, $this->responseFactory->createResponse());
    }

    public function testDeleteRemovesFile(): void
    {
        $userId = 'user-123';
        $fileId = 'file-789';
        $file = $this->createMock(File::class);
        $user = $this->createMock(User::class);
        $repository = $this->createMock(\App\Repository\FileRepository::class);

        $this->session->method('get')
            ->with(Auth::USERID)
            ->willReturn($userId);

        $user->method('getId')->willReturn($userId);
        $file->method('getUser')->willReturn($user);
        $file->method('getFileId')->willReturn('internal-id');

        $repository->method('find')->with($fileId)->willReturn($file);
        $repository->method('listByUser')->with($userId)->willReturn([]);

        $this->entityManager->method('getRepository')
            ->with(File::class)
            ->willReturn($repository);

        $request = $this->createRequestWithSession(attributeId: $fileId);

        $this->filesystem->expects($this->once())
            ->method('delete')
            ->with('internal-id');

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($file);
        $this->entityManager->expects($this->once())->method('flush');
        $this->twig->method('render')->willReturn($this->responseFactory->createResponse());

        $this->controller->delete($request, $this->responseFactory->createResponse());
    }

    public function testUploadRagSavesFileAndAddsToVectorStore(): void
    {
        $uploadedFile = $this->createMock(UploadedFileInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $embedder = $this->createMock(EmbeddingsProviderInterface::class);
        $store = $this->createMock(VectorStoreInterface::class);

        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);
        $uploadedFile->method('getClientFilename')->willReturn('test.txt');
        $uploadedFile->method('getClientMediaType')->willReturn('text/plain');
        $uploadedFile->method('getSize')->willReturn(123);
        $uploadedFile->method('getStream')->willReturn($stream);

        $stream->expects($this->once())->method('rewind');
        $stream->method('getContents')
            ->willReturn('file content. very long content to split.');

        $this->container->method('get')->willReturnMap([
            [EmbeddingsProviderInterface::class, $embedder],
            [VectorStoreInterface::class, $store],
        ]);

        $embedder->method('embedDocuments')->willReturn([]);
        $store->expects($this->once())->method('addDocuments')->with([]);

        $this->filesystem->expects($this->once())->method('write');
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->controller->uploadRag(
            $this->createRequestWithSession(['file' => $uploadedFile]),
            $this->responseFactory->createResponse(),
        );

        $this->assertSame(201, $result->getStatusCode());
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
