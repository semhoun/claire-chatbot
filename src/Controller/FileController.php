<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Odan\Session\SessionInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class FileController
{
    public function __construct(
        private Twig $twig,
        private SessionInterface $session,
        private EntityManagerInterface $entityManager,
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

        $data = $contentStream->getContents();

        $user = $this->entityManager->getReference(User::class, $userId);

        $entity = new File();
        $entity->setUser($user);
        $entity->setFilename($file->getClientFilename() ?? 'fichier');
        $entity->setMimeType($file->getClientMediaType() ?? 'application/octet-stream');
        $entity->setSizeBytes((string) $file->getSize());
        $entity->setContent($data);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        // Return refreshed list
        return $this->list($request, $response);
    }

    public function delete(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $id = (string) $request->getAttribute('id');
        $entityRepository = $this->entityManager->getRepository(File::class);
        if (! $entityRepository->deleteForUser($userId, $id)) {
            return $response->withStatus(400);
        }

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
        $file = $entityRepository->findOneBy(['token' => $token]);
        if (! $file instanceof File) {
            return $response->withStatus(404);
        }

        $stream = $file->getContent();
        // Blob may be a resource stream depending on driver; normalize to string
        $data = is_resource($stream) ? stream_get_contents($stream) : (string) $stream;
        $response->getBody()->write($data);

        $disposition = sprintf('attachment; filename="%s"', addslashes($file->getFilename()));
        return $response
            ->withHeader('Content-Type', $file->getMimeType())
            ->withHeader('Content-Length', $file->getSizeBytes())
            ->withHeader('Content-Disposition', $disposition);
    }
}
