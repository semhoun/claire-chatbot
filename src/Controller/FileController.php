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
        private EntityManagerInterface $em,
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        $userId = (string) $this->session->get('userId');
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $files = $this->em->getRepository(File::class)->listByUser($userId);
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

        $count = $this->em->getRepository(File::class)->countByUserId($userId);
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

        $user = $this->em->getReference(User::class, $userId);

        $entity = new File();
        $entity->setUser($user);
        $entity->setFilename($file->getClientFilename() ?? 'fichier');
        $entity->setMimeType($file->getClientMediaType() ?? 'application/octet-stream');
        $entity->setSizeBytes((string) $file->getSize());
        $entity->setContent($data);

        $this->em->persist($entity);
        $this->em->flush();

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
        $repo = $this->em->getRepository(File::class);
        if (! $repo->deleteForUser($userId, $id)) {
            return $response->withStatus(400);
        }
        // Return refreshed list (so the badge/count updates via OOB)
        return $this->list($request, $response);
    }
}
