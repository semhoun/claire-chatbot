<?php



declare(strict_types=1);



namespace App\Test\Unit\Controller;



use App\Controller\FileController;

use App\Entity\File;

use App\Entity\User;

use App\Repository\FileRepository;

use Doctrine\ORM\EntityManagerInterface;

use App\Session\SessionInterface;

use League\Flysystem\Filesystem;

use PHPUnit\Framework\TestCase;

use Psr\Container\ContainerInterface;

use Psr\Http\Message\ResponseInterface;

use Psr\Http\Message\ServerRequestInterface;

use Psr\Http\Message\UploadedFileInterface;

use Slim\Psr7\Factory\ResponseFactory;

use Slim\Views\Twig;

use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

use NeuronAI\RAG\VectorStore\VectorStoreInterface;



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

            $this->session,

            $this->entityManager,

            $this->filesystem,

            $this->container

        );

    }



    public function testListReturns403WhenNotLogged(): void

    {

        $this->session->method('get')->with('userId')->willReturn(null);

        $request = $this->createMock(ServerRequestInterface::class);

        $response = $this->responseFactory->createResponse();



        $result = $this->controller->list($request, $response);



        $this->assertSame(403, $result->getStatusCode());

    }



    public function testListReturnsFilesList(): void

    {

        $userId = 'user-123';

        $this->session->method('get')->with('userId')->willReturn($userId);



        $repository = $this->createMock(FileRepository::class);

        $repository->expects($this->once())

            ->method('listByUser')

            ->with($userId)

            ->willReturn([]);



        $this->entityManager->method('getRepository')->with(File::class)->willReturn($repository);



        $this->twig->expects($this->once())

            ->method('render')

            ->willReturn($this->responseFactory->createResponse());



        $request = $this->createMock(ServerRequestInterface::class);

        $response = $this->responseFactory->createResponse();



        $this->controller->list($request, $response);

    }



    public function testCountReturnsNumber(): void

    {

        $userId = 'user-123';

        $this->session->method('get')->with('userId')->willReturn($userId);



        $repository = $this->createMock(FileRepository::class);

        $repository->expects($this->once())

            ->method('countByUserId')

            ->with($userId)

            ->willReturn(5);



        $this->entityManager->method('getRepository')->with(File::class)->willReturn($repository);



        $request = $this->createMock(ServerRequestInterface::class);

        $response = $this->responseFactory->createResponse();



        $result = $this->controller->count($request, $response);



        $this->assertSame('5', (string) $result->getBody());

    }



    public function testUploadSavesFile(): void

    {

        $userId = 'user-123';

        $this->session->method('get')->with('userId')->willReturn($userId);



        $uploadedFile = $this->createMock(UploadedFileInterface::class);

        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);

        $uploadedFile->method('getClientFilename')->willReturn('test.txt');

        $uploadedFile->method('getClientMediaType')->willReturn('text/plain');

        $uploadedFile->method('getSize')->willReturn(123);



        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);

        $stream->method('getContents')->willReturn('file content');

        $uploadedFile->method('getStream')->willReturn($stream);



        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);



        $user = $this->createMock(User::class);

        $this->entityManager->method('getReference')->with(User::class, $userId)->willReturn($user);



        $this->filesystem->expects($this->once())

            ->method('write')

            ->with($this->isType('string'), 'file content');



        $this->entityManager->expects($this->once())->method('persist');

        $this->entityManager->expects($this->once())->method('flush');



        // list() will be called at the end

        $repository = $this->createMock(FileRepository::class);

        $this->entityManager->method('getRepository')->with(File::class)->willReturn($repository);

        $this->twig->method('render')->willReturn($this->responseFactory->createResponse());



        $response = $this->responseFactory->createResponse();

        $this->controller->upload($request, $response);

    }



    public function testDeleteRemovesFile(): void

    {

        $userId = 'user-123';

        $this->session->method('get')->with('userId')->willReturn($userId);



        $fileId = 'file-789';

        $file = $this->createMock(File::class);

        $user = $this->createMock(User::class);

        $user->method('getId')->willReturn($userId);

        $file->method('getUser')->willReturn($user);

        $file->method('getFileId')->willReturn('internal-id');



        $repository = $this->createMock(FileRepository::class);

        $repository->method('find')->with($fileId)->willReturn($file);

        $this->entityManager->method('getRepository')->with(File::class)->willReturn($repository);



        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getAttribute')->with('id')->willReturn($fileId);



        $this->filesystem->expects($this->once())

            ->method('delete')

            ->with('internal-id');



        $this->entityManager->expects($this->once())->method('remove')->with($file);

        $this->entityManager->expects($this->once())->method('flush');



        // list() will be called at the end

        $this->twig->method('render')->willReturn($this->responseFactory->createResponse());



        $response = $this->responseFactory->createResponse();

        $this->controller->delete($request, $response);

    }



    public function testUploadRagSavesFileAndAddsToVectorStore(): void

    {

        $uploadedFile = $this->createMock(UploadedFileInterface::class);

        $uploadedFile->method('getError')->willReturn(UPLOAD_ERR_OK);

        $uploadedFile->method('getClientFilename')->willReturn('test.txt');

        $uploadedFile->method('getClientMediaType')->willReturn('text/plain');

        $uploadedFile->method('getSize')->willReturn(123);



        $stream = $this->createMock(\Psr\Http\Message\StreamInterface::class);

        $stream->method('getContents')->willReturn('file content. very long content to split.');

        $uploadedFile->method('getStream')->willReturn($stream);



        $request = $this->createMock(ServerRequestInterface::class);

        $request->method('getUploadedFiles')->willReturn(['file' => $uploadedFile]);



        $embedder = $this->createMock(EmbeddingsProviderInterface::class);

        $store = $this->createMock(VectorStoreInterface::class);



        $this->container->method('get')->willReturnMap([

            [EmbeddingsProviderInterface::class, $embedder],

            [VectorStoreInterface::class, $store],

        ]);



        $embedder->method('embedDocuments')->willReturn([]);

        $store->expects($this->once())->method('addDocuments');



        $this->filesystem->expects($this->once())->method('write');

        $this->entityManager->expects($this->once())->method('persist');

        $this->entityManager->expects($this->once())->method('flush');



        $response = $this->responseFactory->createResponse();

        $result = $this->controller->uploadRag($request, $response);



        $this->assertSame(201, $result->getStatusCode());

    }

}

