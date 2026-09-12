<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\File;
use App\Entity\User;
use App\Services\Auth;
use App\Services\Markdown;
use App\Services\RagServiceInterface;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\Filesystem;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;

final readonly class FileController
{
    use SessionFromRequest;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Filesystem $filesystem,
        private Settings $settings,
        private RagServiceInterface $ragService,
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
        $response->getBody()->write(json_encode([
            'files' => array_values(array_map(static fn (File $file): array => [
                'fileId' => $file->getFileId(),
                'filename' => $file->getFilename(),
                'mimeType' => $file->getMimeType(),
                'sizeBytes' => $file->getSizeBytes(),
                'createdAt' => $file->getCreatedAt()->format(DATE_ATOM),
            ], $files)),
            'acceptedExt' => $this->settings->get('files.upload.acceptedExt'),
        ], JSON_THROW_ON_ERROR));
        return $response->withHeader('Content-Type', 'application/json');
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

        if (! $this->validateUploadedFile($file)) {
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

        if (! $this->validateUploadedFile($file)) {
            return $response->withStatus(400);
        }

        $entity = $this->createFileEntity($file, $user);

        $data = $this->readFileContent($file);
        $this->filesystem->write($entity->getFilePath() ?? $entity->getFileId(), $data);
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $processedData = $this->convertToMarkdown($file->getClientMediaType(), $data);
        $this->ragService->createFromFile($entity, $user, $processedData);

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

        // Return the updated JSON list.
        return $this->list($request, $response);
    }

    /**
     * Serve a generated file from the filesystem.
     */
    public function serve(Request $request, Response $response): Response
    {
        $response = $response->withHeader('Cache-Control', 'private, no-store');
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

        $path = $file->getFilePath();

        if ($path === null || ! $this->filesystem->fileExists($path)) {
            return $response->withStatus(404);
        }

        $mimeType = $this->filesystem->mimeType($path);
        if ($request->getMethod() === 'HEAD') {
            $contentLength = $this->filesystem->fileSize($path);
        } else {
            $content = $this->filesystem->read($path);
            $contentLength = strlen($content);
            $response->getBody()->write($content);
        }

        $response = $response
            ->withHeader('Content-Type', $mimeType)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Length', (string) $contentLength);

        // Only passive formats may be displayed under the application origin.
        $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
        if (! in_array(strtolower($mimeType), ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'], true)
            || in_array($extension, ['svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'js'], true)) {
            $filename = preg_replace('/[\x00-\x1f\x7f]/', '', $file->getFilename());
            return $response->withHeader('Content-Type', 'application/octet-stream')
                ->withHeader('Content-Security-Policy', "sandbox; default-src 'none'")
                ->withHeader('Content-Disposition', 'attachment; filename="' . addcslashes($filename, '"\\') . '"');
        }

        if ($file->fileType() !== File::FILE_TYPE_IMAGE) {
            return $response->withHeader('Content-Disposition', 'inline; filename="' . addcslashes($file->getFilename(), '"\\') . '"');
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

    private function validateUploadedFile(UploadedFileInterface $uploadedFile): bool
    {
        $filename = $uploadedFile->getClientFilename() ?? '';
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($extension, ['svg', 'svgz'], true)
            || str_contains(strtolower($uploadedFile->getClientMediaType() ?? ''), 'svg')) {
            return false;
        }

        $forbiddenExtensions = $this->settings->get('files.upload.forbidden_extensions');
        if (in_array($extension, $forbiddenExtensions, true)) {
            return false;
        }

        $mimeType = $uploadedFile->getClientMediaType();

        // Inspect the stream, including in-memory uploads; never trust the client MIME.
        $content = $this->readFileContent($uploadedFile);
        $realMimeType = new \finfo(FILEINFO_MIME_TYPE)->buffer($content);
        if ($realMimeType !== false) {
            $mimeType = $realMimeType;
        }

        if (str_contains(strtolower($mimeType ?? ''), 'svg')
            || preg_match('/<\s*(?:[a-z0-9_-]+:)?svg\b/i', str_replace("\0", '', $content))) {
            return false;
        }

        $allowedMimeTypes = $this->settings->get('files.upload.allowed_mime_types');
        if ($mimeType !== null && ! in_array($mimeType, $allowedMimeTypes, true)) {
            return false;
        }

        return true;
    }
}
