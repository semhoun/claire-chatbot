<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Monolog\Logger;
use NeuronAI\RAG\DataLoader\StringDataLoader;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Splitter\DelimiterTextSplitter;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Odan\Session\SessionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Slim\Views\Twig;

final readonly class FileController
{
    public function __construct(
        private Twig $twig,
        private SessionInterface $session,
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private Logger $logger,
        private ContainerInterface $container,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $files = $this->entityManager->getRepository(File::class)->listByUser($userId);
        return $this->twig->render($response, 'partials/files_list.twig', [
            'files' => $files,
        ]);
    }

    /**
     * Retourne le nombre de fichiers pour l'utilisateur courant
     */
    public function count(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $count = $this->entityManager->getRepository(File::class)->countByUserId($userId);
        $response->getBody()->write((string) $count);
        return $response;
    }

    public function upload(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $response->withStatus(400);
        }

        $contentStream = $file->getStream();
        $contentStream->rewind();

        $user = $this->entityManager->getReference(User::class, $userId);

        $entity = new File();
        $entity->setUser($user);
        $entity->setFilename($file->getClientFilename() ?? 'fichier');
        $entity->setMimeType($file->getClientMediaType() ?? 'application/octet-stream');
        $entity->setFileId(Uuid::uuid7()->toString());
        $entity->setSizeBytes((string) $file->getSize());

        $this->filesystem->write($entity->getFileId(), $contentStream->getContents());

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        // Return refreshed list
        return $this->list($request, $response);
    }

    public function uploadRag(Request $request, Response $response): Response
    {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $response->withStatus(400);
        }

        $contentStream = $file->getStream();
        $contentStream->rewind();

        $data = $contentStream->getContents();

        $entity = new File();
        $entity->setFilename($file->getClientFilename() ?? 'fichier');
        $entity->setMimeType($file->getClientMediaType() ?? 'application/octet-stream');
        $entity->setFileId(Uuid::uuid7()->toString());
        $entity->setSizeBytes((string) $file->getSize());

        $this->filesystem->write($entity->getFileId(), $data);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        // Ajout dans le RAG
        $embedder = $this->container->get(EmbeddingsProviderInterface::class);
        $store = $this->container->get(VectorStoreInterface::class);
        $documents = StringDataLoader::for($data)->withSplitter(
            new DelimiterTextSplitter(
                maxLength: 1000,
                separator: '.',
                wordOverlap: 0
            )
        )
        ->getDocuments();
        $store->addDocuments(
            $embedder->embedDocuments($documents)
        );

        return $response->withStatus(201);
    }

    public function delete(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $id = (string) $request->getAttribute('id');

        $file = $this->entityManager->getRepository(File::class)->find($id);
        if ($file === null) {
            return $response->withStatus(404);
        }

        if ($file->getUser()->getId() !== $userId) {
            return $response->withStatus(403);
        }

        $this->filesystem->delete($file->getFileId());

        $this->entityManager->remove($file);
        $this->entityManager->flush();

        // Return refreshed list (so the badge/count updates via OOB)
        return $this->list($request, $response);
    }

    /**
     * Télécharge un fichier via son token public (UUID v7)
     */
    public function downloadByToken(Request $request, Response $response): Response
    {
        $token = (string) $request->getAttribute('token');
        if ($token === '') {
            return $response->withStatus(400);
        }

        $entityRepository = $this->entityManager->getRepository(File::class);
        $fileDB = $entityRepository->findOneBy(['token' => $token]);
        if (! $fileDB instanceof File) {
            return $response->withStatus(404);
        }

        try {
            $response->getBody()->write($this->filesystem->read($fileDB->getFileId()));
        } catch (FilesystemException | UnableToReadFile $exception) {
            $this->logger->error('Failed to read file by token', ['token' => $token, 'exception' => $exception]);
            return $response->withStatus(404);
        }

        $disposition = sprintf('attachment; filename="%s"', addslashes($fileDB->getFilename()));
        return $response
            ->withHeader('Content-Type', $fileDB->getMimeType())
            ->withHeader('Content-Length', $fileDB->getSizeBytes())
            ->withHeader('Content-Disposition', $disposition);
    }
}
