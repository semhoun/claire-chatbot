<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\RagDocument;
use App\Entity\User;
use App\Services\Auth;
use App\Services\Markdown;
use App\Services\RagServiceInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Views\Twig;

final readonly class RagController
{
    use SessionFromRequest;

    public function __construct(
        private Twig $twig,
        private EntityManagerInterface $entityManager,
        private RagServiceInterface $ragService,
        private Settings $settings,
        private Markdown $markdown,
    ) {
    }

    public function list(Request $request, Response $response): Response
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

        $documents = $this->ragService->listForUser($user);

        return $this->twig->render($response, 'partials/rag_list.twig', [
            'documents' => $documents,
            'base_url' => (string) $request->getAttribute('base_url'),
            'accepted_ext' => $this->settings->get('files.upload.acceptedExt'),
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function count(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $count = $this->entityManager->getRepository(RagDocument::class)->countByUserId($userId);
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

        $file = $this->getUploadedFile($request);
        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $response->withStatus(400);
        }

        $content = $this->readUploadedFile($file);
        $processed = $this->convertToMarkdown($file->getClientMediaType(), $content);
        $this->ragService->createFromText($user, $file->getClientFilename() ?? 'document', $processed);

        return $this->list($request, $response);
    }

    public function addText(Request $request, Response $response): Response
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

        $data = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($data['name'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        if ($name === '' || $content === '') {
            return $response->withStatus(400);
        }

        $this->ragService->createFromText($user, $name, $content);

        return $this->list($request, $response);
    }

    public function addUrl(Request $request, Response $response): Response
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

        $data = (array) ($request->getParsedBody() ?? []);
        $name = trim((string) ($data['name'] ?? ''));
        $url = trim((string) ($data['url'] ?? ''));
        if ($name === '' || $url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return $response->withStatus(400);
        }

        $this->ragService->createFromUrl($user, $name, $url);

        return $this->list($request, $response);
    }

    public function segments(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $document = $this->findDocumentForUser($request, $userId);
        if (! $document instanceof RagDocument) {
            return $response->withStatus(404);
        }

        $segments = $this->ragService->listSegments($document);

        return $this->twig->render($response, 'partials/rag_segments.twig', [
            'document' => $document,
            'segments' => $segments,
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function toggle(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $document = $this->findDocumentForUser($request, $userId);
        if (! $document instanceof \App\Entity\RagDocument) {
            return $response->withStatus(404);
        }

        $this->ragService->setActive($document, ! $document->isActive());

        return $this->list($request, $response);
    }

    public function delete(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(403);
        }

        $document = $this->findDocumentForUser($request, $userId);
        if (! $document instanceof \App\Entity\RagDocument) {
            return $response->withStatus(404);
        }

        $this->ragService->delete($document);

        return $this->list($request, $response);
    }

    private function findDocumentForUser(Request $request, string $userId): ?RagDocument
    {
        $id = (string) $request->getAttribute('id');

        return $this->entityManager->getRepository(RagDocument::class)
            ->findOneByDocumentIdAndUser($id, $userId);
    }

    private function convertToMarkdown(?string $mimeType, string $data): string
    {
        return match ($mimeType) {
            'application/xhtml+xml', 'text/html' => Markdown::fromHtml($data),
            'application/pdf' => Markdown::fromPdf($data),
            default => $data,
        };
    }

    private function getUploadedFile(Request $request): ?UploadedFileInterface
    {
        $uploadedFiles = $request->getUploadedFiles();

        return $uploadedFiles['file'] ?? null;
    }

    private function readUploadedFile(UploadedFileInterface $uploadedFile): string
    {
        $stream = $uploadedFile->getStream();
        $stream->rewind();

        return $stream->getContents();
    }
}
