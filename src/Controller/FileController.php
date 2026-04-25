<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Services\Auth;
use App\Services\Markdown;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;
use Slim\Views\Twig;

final readonly class FileController
{
    use SessionFromRequest;

    public function __construct(
        private Twig $twig,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private ContainerInterface $container,
        private Settings $settings,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $files = $this->entityManager->getRepository(File::class)->listByUser($userId);
        return $this->twig->render($response, 'partials/files_list.twig', [
            'files' => $files,
        ]);
    }

    /**
     * Retourne le nombre de fichiers pour l'utilisateur courant.
     */
    public function count(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $count = $this->entityManager->getRepository(File::class)->countByUserId($userId);
        $response->getBody()->write((string) $count);
        return $response;
    }

    public function upload(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }
        $user = $this->entityManager->getReference(User::class, $userId);
        if ($user === null) {
            return $response->withStatus(403);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $response->withStatus(400);
        }

        $entity = $this->createFileEntity($file, $user);

        $data = $this->readFileContent($file);
        $this->filesystem->write($entity->getFilePath() ?? $entity->getFileId(), $data);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        // Return refreshed list
        return $this->list($request, $response);
    }

    public function uploadRag(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }
        $user = $this->entityManager->getReference(User::class, $userId);

        $file = $this->getUploadedFile($request);
        if (! $file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $response->withStatus(400);
        }

        $entity = $this->createFileEntity($file, $user);

        $data = $this->readFileContent($file);
        $this->filesystem->write($entity->getFilePath() ?? $entity->getFileId(), $data);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $processedData = $this->convertToMarkdown($file->getClientMediaType(), $data);
        $this->indexInRag($processedData);

        return $response->withStatus(201);
    }

    public function delete(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if ($user === null) {
            return $response->withStatus(403);
        }

        $id = (string) $request->getAttribute('id');

        $file = $this->entityManager->getRepository(File::class)->findOneBy(['fileId' => $id, 'user' => $user]);
        if ($file === null) {
            return $response->withStatus(404);
        }

        $path = $file->getFilePath() ?? $file->getFileId();
        if ($this->filesystem->fileExists($path)) {
            $this->filesystem->delete($path);
        }

        $this->entityManager->remove($file);
        $this->entityManager->flush();

        // Return refreshed list (so the badge/count updates via OOB)
        return $this->list($request, $response);
    }

    /**
     * Serve a generated file from the filesystem.
     */
    public function serve(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if ($user === null) {
            return $response->withStatus(403);
        }

        $id = (string) $request->getAttribute('id');

        /** @var File|null $file */
        $file = $this->entityManager->getRepository(File::class)->findOneBy(['fileId' => $id, 'user' => $user]);

        if ($file === null) {
            return $response->withStatus(404);
        }

        $path = $file?->getFilePath();

        if ($path === null || ! $this->filesystem->fileExists($path)) {
            return $response->withStatus(404);
        }

        $mimeType = $this->filesystem->mimeType($path);
        $content = $this->filesystem->read($path);

        $response->getBody()->write($content);

        $response = $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('Content-Length', (string) strlen($content));

        if ($file->fileType() !== File::FILE_TYPE_IMAGE) {
            $response = $response->withHeader('Content-Disposition', 'inline; filename="' . addcslashes($file->getFilename(), '"\\') . '"');
        }

        return $response;
    }

    private function getUploadedFile(Request $request): ?UploadedFileInterface
    {
        $uploadedFiles = $request->getUploadedFiles();

        return $uploadedFiles['file'] ?? null;
    }

    private function readFileContent(UploadedFileInterface $uploadedFile): string
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();

        return $stream->getContents();
    }

    private function createFileEntity(UploadedFileInterface $uploadedFile, User $user): File
    {
        $fileId = Uuid::uuid4()->toString();
        $extension = pathinfo($uploadedFile->getClientFilename() ?? '', PATHINFO_EXTENSION);
        $diskFilename = $fileId . ($extension !== '' ? '.' . $extension : '');
        $localPath = $this->settings->get('files.upload.path') . '/' . $user->getId() . '/' . $diskFilename;

        $file = new File();
        $file->setUser($user);
        $file->setFilename($uploadedFile->getClientFilename() ?? 'fichier');
        $file->setMimeType($uploadedFile->getClientMediaType() ?? 'application/octet-stream');
        $file->setFileId($fileId);
        $file->setFilePath($localPath);
        $file->setSizeBytes($uploadedFile->getSize());

        return $file;
    }

    private function convertToMarkdown(?string $mimeType, string $data): string
    {
        return match ($mimeType) {
            'application/xhtml+xml', 'text/html' => Markdown::fromHtml($data),
            'application/pdf' => Markdown::fromPdf($data),
            default => $data,
        };
    }

    private function indexInRag(string $data): void
    {
        $embedder = $this->container->get(EmbeddingsProviderInterface::class);
        $store = $this->container->get(VectorStoreInterface::class);

        $documents = StringDataLoader::for($data)
            ->withSplitter(
                new DelimiterTextSplitter(
                    maxLength: 1000,
                    separator: '.',
                    wordOverlap: 0
                )
            )
            ->getDocuments();

        $store->addDocuments($embedder->embedDocuments($documents));
    }
}
