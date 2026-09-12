<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Controller\RagController;
use App\Entity\RagDocument;
use App\Entity\User;
use App\Middleware\JwtSessionMiddleware;
use App\Repository\RagDocumentRepository;
use App\Services\Auth;
use App\Services\RagServiceInterface;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[AllowMockObjectsWithoutExpectations]
final class RagControllerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private RagServiceInterface $service;
    private RagDocumentRepository $repository;
    private RagController $controller;
    private User $user;
    private RagDocument $document;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->service = $this->createMock(RagServiceInterface::class);
        $this->repository = $this->createMock(RagDocumentRepository::class);
        $this->user = new User();
        $this->document = new RagDocument();
        $this->document->setDocumentId('doc-1');
        $this->document->setName('<document>');
        $this->document->setChunkCount(2);
        $this->document->onPrePersist();
        $this->entityManager->method('getReference')->with(User::class, 'user-1')->willReturn($this->user);
        $this->entityManager->method('getRepository')->with(RagDocument::class)->willReturn($this->repository);
        $this->controller = new RagController($this->entityManager, $this->service,
            new Settings(['files' => ['upload' => ['acceptedExt' => '.txt']]]));
    }

    public function testListReturnsTypedMetadata(): void
    {
        $this->service->expects(self::once())->method('listForUser')->with($this->user)->willReturn([$this->document]);
        $response = $this->controller->list($this->request(), new Response());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['documents' => [[
            'documentId' => 'doc-1', 'name' => '<document>', 'sourceType' => 'text',
            'isActive' => true, 'chunkCount' => 2,
            'createdAt' => $this->document->getCreatedAt()->format(DATE_ATOM),
        ]], 'acceptedExt' => '.txt'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSegmentsRemainPlainTextAndAreScopedToUser(): void
    {
        $this->repository->expects(self::once())->method('findOneByDocumentIdAndUser')
            ->with('doc-1', 'user-1')->willReturn($this->document);
        $this->service->expects(self::once())->method('listSegments')->with($this->document)
            ->willReturn(['<script>alert(1)</script>', "line 1\nline 2"]);
        $response = $this->controller->segments($this->request(), new Response());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame([
            'document' => ['documentId' => 'doc-1', 'name' => '<document>'],
            'segments' => ['<script>alert(1)</script>', "line 1\nline 2"],
        ], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testForeignDocumentCannotBeReadOrMutated(): void
    {
        $this->repository->expects(self::exactly(3))->method('findOneByDocumentIdAndUser')
            ->with('doc-1', 'user-1')->willReturn(null);
        $this->service->expects(self::never())->method('listSegments');
        $this->service->expects(self::never())->method('setActive');
        $this->service->expects(self::never())->method('delete');
        foreach (['segments', 'toggle', 'delete'] as $action) {
            self::assertSame(404, $this->controller->$action($this->request(), new Response())->getStatusCode());
        }
    }

    public function testToggleAndDeleteReturnUpdatedJsonLists(): void
    {
        $this->repository->method('findOneByDocumentIdAndUser')->willReturn($this->document);
        $this->service->expects(self::once())->method('setActive')->with($this->document, false);
        $this->service->expects(self::once())->method('delete')->with($this->document);
        $this->service->method('listForUser')->willReturn([]);
        foreach (['toggle', 'delete'] as $action) {
            $response = $this->controller->$action($this->request(), new Response());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            self::assertSame(['documents' => [], 'acceptedExt' => '.txt'],
                json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testUnauthenticatedRequestsAreRejected(): void
    {
        $this->service->expects(self::never())->method('listForUser');
        $request = $this->request()->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, new InMemorySession([]));
        foreach (['list', 'segments', 'toggle', 'delete'] as $action) {
            self::assertSame(403, $this->controller->$action($request, new Response())->getStatusCode());
        }
    }

    public function testTextAndUrlCreationReturnJsonLists(): void
    {
        $this->service->expects(self::once())->method('createFromText')
            ->with($this->user, 'Note', 'Content')->willReturn($this->document);
        $this->service->expects(self::once())->method('createFromUrl')
            ->with($this->user, 'Note', 'https://example.org')->willReturn($this->document);
        $this->service->method('listForUser')->willReturn([$this->document]);
        foreach (['addText', 'addUrl'] as $action) {
            $request = $this->request()->withParsedBody([
                'name' => ' Note ', 'content' => ' Content ', 'url' => 'https://example.org',
            ]);
            $response = $this->controller->$action($request, new Response());
            self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('doc-1', $data['documents'][0]['documentId']);
        }
    }

    private function request(): ServerRequestInterface
    {
        return new ServerRequestFactory()->createServerRequest('GET', '/rag/list')
            ->withAttribute('id', 'doc-1')
            ->withAttribute(JwtSessionMiddleware::SESSION_ATTRIBUTE, new InMemorySession([Auth::USERID => 'user-1']));
    }
}
