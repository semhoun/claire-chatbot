<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Entity\RagDocument;
use App\Entity\User;
use App\Brain\Tools\RagSearchTool;
use App\Services\Auth;
use App\Services\RagServiceInterface;
use App\Services\Session\ArraySession;
use App\Repository\RagDocumentRepository;
use App\Services\RagService;
use App\Services\RagUrlFetcher;
use App\Services\RagUrlTransport;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RagServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/claire-rag-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testLegacyStoreIsReadableWithoutConversionOrEmbedding(): void
    {
        $user = $this->user('101');
        $document = $this->fixture($user, 'legacy');
        $service = $this->service([$document]);
        $bytes = file_get_contents($this->path($document));
        $service->rebuildActiveVectorStore($user);
        $service->rebuildActiveVectorStore($user);
        self::assertSame($bytes, file_get_contents($this->path($document)));
        self::assertSame($bytes, file_get_contents($this->directory . '/101/active.store'));
        self::assertSame(['Synthetic report source.', 'Synthetic audio source.'], $service->listSegments($document));
        $results = $service->getActiveVectorStoreForUser($user)->search(new SearchRequest([1, 0, 0]));
        self::assertSame('fixture-document-1', $results[0]->getId());
        self::assertSame('fixture.txt', $results[0]->getSourceName());
        self::assertSame('file', $results[0]->getSourceType());
        self::assertSame(['document_id' => 101, 'chunk' => 0], $results[0]->getMetadata());
        self::assertSame(1.0, $results[0]->getScore());
    }

    public function testSelectionDeletionAndUsersRemainSeparate(): void
    {
        $first = $this->fixture($this->user('101'), 'first');
        $second = $this->fixture($this->user('202'), 'second');
        $service = $this->service([$first, $second]);
        // A future semantic store must never be picked up by directory globbing.
        file_put_contents($this->directory . '/101/semantic.store', "not document data\n");
        $service->rebuildActiveVectorStore($first->getUser());
        $service->rebuildActiveVectorStore($second->getUser());
        $secondBytes = file_get_contents($this->directory . '/202/active.store');
        $service->setActive($first, false);
        self::assertSame('', file_get_contents($this->directory . '/101/active.store'));
        $service->setActive($first, true);
        self::assertSame($secondBytes, file_get_contents($this->directory . '/101/active.store'));
        $service->delete($first);
        self::assertFileDoesNotExist($this->path($first));
        self::assertSame('', file_get_contents($this->directory . '/101/active.store'));
        self::assertSame($secondBytes, file_get_contents($this->directory . '/202/active.store'));
    }

    public function testMixedLegacyAndV4RowsKeepTheirSources(): void
    {
        $user = $this->user('101');
        $legacy = $this->fixture($user, 'legacy');
        $modern = $this->fixture($user, 'modern');
        unlink($this->path($modern));
        (new FileVectorStore($this->directory . '/101', name: 'modern'))->addDocument(
            (new Document('Modern document'))->setId('modern')->setEmbedding([0, 0, 1])
                ->setSourceType('url')->setSourceName('https://example.test/source')
        );
        $service = $this->service([$legacy, $modern]);
        $service->rebuildActiveVectorStore($user);
        $results = $service->getActiveVectorStoreForUser($user)->search(new SearchRequest([0, 0, 1]));
        self::assertCount(3, $results);
        self::assertSame('modern', $results[0]->getId());
        self::assertSame('https://example.test/source', $results[0]->getSourceName());
    }

    public function testConcurrentRebuildsPublishOnlyCompleteSnapshots(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for concurrent writer coverage.');
        }
        $document = $this->fixture($this->user('101'), 'legacy');
        $service = $this->service([$document]);
        $service->rebuildActiveVectorStore($document->getUser());
        $expected = file_get_contents($this->path($document));
        $children = [];
        for ($worker = 0; $worker < 2; ++$worker) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    for ($iteration = 0; $iteration < 30; ++$iteration) {
                        $service->rebuildActiveVectorStore($document->getUser());
                    }
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        for ($iteration = 0; $iteration < 100; ++$iteration) {
            self::assertSame($expected, file_get_contents($this->directory . '/101/active.store'));
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame([], glob($this->directory . '/101/.active-*'));
    }

    public function testNewIndexUsesV4DocumentSourceWithoutChangingLegacyStores(): void
    {
        $user = $this->user('101');
        $legacy = $this->fixture($user, 'legacy');
        $bytes = file_get_contents($this->path($legacy));
        $created = null;
        $repository = $this->createStub(RagDocumentRepository::class);
        $repository->method('findActiveByUser')->willReturnCallback(
            static function () use (&$created, $legacy): array {
                return $created === null ? [$legacy] : [$legacy, $created];
            },
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(
            static function (RagDocument $document) use (&$created): void {
                $document->onPrePersist();
                $created = $document;
            },
        );
        $embeddings = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddings->expects(self::once())->method('embedDocuments')->willReturnCallback(
            static function (array $documents): array {
                foreach ($documents as $document) {
                    self::assertSame('New source', $document->getSourceName());
                    self::assertSame('text', $document->getSourceType());
                    $document->setEmbedding([0, 0, 1]);
                }
                return $documents;
            },
        );
        $service = new RagService(
            $entityManager,
            $embeddings,
            new Settings(['llm' => ['rag' => ['path' => $this->directory, 'topK' => 4, 'chunkSize' => 1000]]]),
            new NullLogger(),
            new RagUrlFetcher($this->createStub(RagUrlTransport::class)),
        );
        $document = $service->createFromText($user, 'New source', 'A deterministic new document.');
        self::assertSame(1, $document->getChunkCount());
        $results = (new FileVectorStore($this->directory . '/101', name: $document->getDocumentId()))
            ->search(new SearchRequest([0, 0, 1]));
        self::assertSame('New source', $results[0]->getSourceName());
        self::assertCount(3, $service->getActiveVectorStoreForUser($user)->search(new SearchRequest([0, 0, 1])));
        self::assertSame($bytes, file_get_contents($this->path($legacy)));
    }

    public function testSearchToolUsesV4SchemaAndSearchRequest(): void
    {
        $user = $this->user('101');
        $document = $this->fixture($user, 'legacy');
        $repository = $this->createStub(\App\Repository\UserRepository::class);
        $repository->method('find')->willReturn($user);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $session = new ArraySession();
        $session->set(Auth::USERID, $user->getId());
        $service = $this->createMock(RagServiceInterface::class);
        $service->expects(self::once())->method('listForUser')->with($user)->willReturn([$document]);
        $service->expects(self::once())->method('embedQuery')->with('report')->willReturn([1, 0, 0]);
        $service->method('getActiveVectorStoreForUser')->willReturn(
            new FileVectorStore($this->directory . '/101', name: 'legacy'),
        );
        $tool = new RagSearchTool($service, $entityManager, $session, new NullLogger());
        self::assertSame('rag_search', $tool->getName());
        self::assertSame(['query'], $tool->getInputSchema()['required']);
        $response = json_decode($tool('report'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('success', $response['status']);
        self::assertSame('fixture.txt', $response['results'][0]['source']);
        self::assertSame('Synthetic report source.', $response['results'][0]['content']);
    }

    public function testRagInstructionsPreserveSystemBlocksWithoutAccumulatingDates(): void
    {
        $agent = (new \ReflectionClass(\App\Brain\RAG::class))->newInstanceWithoutConstructor();
        $instructions = new \NeuronAI\Chat\Messages\SystemMessage('Document instructions');
        $instructions->cache();
        $agent->setInstructions($instructions);
        $resolve = new \ReflectionMethod($agent, 'resolveTools');
        foreach ([1, 2] as $iteration) {
            [$resolved, $tools] = $resolve->invoke($agent);
            self::assertCount(2, $resolved->getContentBlocks());
            self::assertTrue($resolved->getContentBlocks()[0]->isCached());
            self::assertStringContainsString('Date et heure actuelles', $resolved->getContent());
            self::assertSame([], $tools);
        }
        self::assertSame('Document instructions', $agent->getInstructions()->getContent());
    }

    public function testRealV4RagWorkflowRetrievesLegacyFixtureWithDeterministicProvider(): void
    {
        $document = $this->fixture($this->user('101'), 'legacy');
        $service = $this->service([$document]);
        $service->rebuildActiveVectorStore($document->getUser());
        $agent = new class extends \App\Brain\RAG {
            public function __construct()
            {
                $this->settings = new Settings([]);
                $this->session = new \App\Services\Session\ArraySession();
                \NeuronAI\RAG\RAG::__construct();
            }

            protected function middleware(): array
            {
                return [];
            }
        };
        $provider = new \NeuronAI\Testing\FakeAIProvider(
            new \NeuronAI\Chat\Messages\AssistantMessage('Deterministic answer'),
        );
        $embeddings = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddings->expects(self::once())->method('embedText')->willReturn([1, 0, 0]);
        $embeddings->expects(self::never())->method('embedDocuments');
        $agent->setThreadId('rag-fixture')->setContextWindow(1000)
            ->setMessageStore(new \NeuronAI\Chat\History\InMemoryMessageStore())
            ->setAiProvider($provider)->setEmbeddingsProvider($embeddings)
            ->setVectorStore($service->getActiveVectorStoreForUser($document->getUser()));
        $state = $agent->chat(new \NeuronAI\Chat\Messages\UserMessage('What does the report say?'));
        self::assertSame('Deterministic answer', $state->getMessage()->getContent());
        $provider->assertCallCount(1);
        $instructions = $provider->getRecorded()[0]->systemPrompt->getContent();
        self::assertStringContainsString('Synthetic report source.', $instructions);
        self::assertStringContainsString('Date et heure actuelles', $instructions);
    }

    private function user(string $id): User
    {
        $user = new User();
        $user->setId($id);
        return $user;
    }

    private function fixture(User $user, string $id): RagDocument
    {
        $document = new RagDocument();
        $document->setUser($user);
        $document->setDocumentId($id);
        if (! is_dir($this->directory . '/' . $user->getId())) {
            mkdir($this->directory . '/' . $user->getId());
        }
        copy(dirname(__DIR__, 2) . '/Fixtures/neuron-v3/documents.store', $this->path($document));
        return $document;
    }

    private function path(RagDocument $document): string
    {
        return $this->directory . '/' . $document->getUser()->getId() . '/' . $document->getDocumentId() . '.store';
    }

    /** @param list<RagDocument> $documents */
    private function service(array $documents): RagService
    {
        $repository = $this->createStub(RagDocumentRepository::class);
        $repository->method('findActiveByUser')->willReturnCallback(
            static fn (string $id): array => array_values(array_filter(
                $documents,
                static fn (RagDocument $document): bool => $document->getUser()->getId() === $id
                    && $document->isActive(),
            )),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('remove')->willReturnCallback(
            static fn (RagDocument $document) => $document->setIsActive(false),
        );
        $embeddings = $this->createMock(EmbeddingsProviderInterface::class);
        $embeddings->expects(self::never())->method('embedDocuments');
        $embeddings->expects(self::never())->method('embedText');
        return new RagService(
            $entityManager,
            $embeddings,
            new Settings(['llm' => ['rag' => ['path' => $this->directory, 'topK' => 4]]]),
            new NullLogger(),
            new RagUrlFetcher($this->createStub(RagUrlTransport::class)),
        );
    }
}
