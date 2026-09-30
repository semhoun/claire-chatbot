<?php

declare(strict_types=1);

namespace Tests\Unit\Brain\ChatHistory;

use Composer\InstalledVersions;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\FileContent;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\FileVectorStore;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\TestCase;

/** Frozen synthetic 3.16.9 payloads, never regenerate with a newer serializer. */
final class NeuronV3FixturesTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../../../Fixtures/neuron-v3/';

    public function testFixtureContractsRemainAvailableWithoutV3(): void
    {
        $fixture = $this->history();
        self::assertCount(2, $fixture['messages']);
        self::assertCount(8, $fixture['display_messages']);
        self::assertSame('out_of_context', $fixture['messages'][0]['message_type']);
        self::assertStringContainsString($fixture['summary'], $fixture['messages'][0]['content'][0]['content']);
        self::assertSame($fixture['messages'][1]['__id'], $fixture['display_messages'][7]['__id']);
        self::assertArrayNotHasKey('claire_message_id', $fixture['messages'][1]);
        self::assertSame('audio_fixture_final', $fixture['display_messages'][7]['claire_audio_request_id']);
        self::assertNull($fixture['display_messages'][1]['claire_audio_request_id']);
        foreach ([3, 5] as $index) {
            $call = $fixture['display_messages'][$index];
            $result = $fixture['display_messages'][$index + 1];
            self::assertSame('tool_call', $call['type']);
            self::assertSame('tool_call_result', $result['type']);
            self::assertSame($call['tools'][0]['callId'], $result['tools'][0]['callId']);
            self::assertNotNull($result['tools'][0]['result']);
        }
        self::assertSame(
            "Synthetic fixture.\n",
            base64_decode($fixture['display_messages'][2]['content'][2]['content'], true),
        );
    }

    public function testFrozenHistoryMatchesInstalledV3Serialization(): void
    {
        $this->requireV3();
        $report = new Tool('generate_pdf', 'Synthetic report tool');
        $report->setParameters(['type' => 'object', 'properties' => ['text' => ['type' => 'string']]])
            ->setInputs(['text' => 'Synthetic fixture.'])->setCallId('call_fixture_1');
        $audio = new Tool('generate_speech', 'Synthetic audio tool');
        $audio->setCallId('call_fixture_2');
        $file = new FileContent(base64_encode("Synthetic fixture.\n"), SourceType::BASE64, 'text/plain', 'fixture.txt');
        $file->setMetadata(['purpose' => 'synthetic']);
        $messages = [
            new UserMessage('Hello, synthetic Claire.'),
            new AssistantMessage('Hello, synthetic user.'),
            new UserMessage('Make a report and audio from these synthetic attachments.')
                ->addContent(new ImageContent(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=',
                    SourceType::BASE64,
                    'image/png',
                ))->addContent($file),
            new ToolCallMessage('Preparing the report.', [clone $report]),
            new ToolResultMessage([$report->setResult('@@GENERATED@@/fixture.pdf')]),
            new ToolCallMessage('Preparing the audio next.', [clone $audio]),
            new ToolResultMessage([$audio->setResult('@@GENERATED@@/fixture.mp3')]),
            new AssistantMessage('Report: @@GENERATED@@/fixture.pdf Audio: @@GENERATED@@/fixture.mp3')
                ->setUsage(new Usage(42, 12)),
        ];
        $ids = ['user', 'hello', 'assets', 'call_1', 'result_1', 'call_2', 'result_2', 'final'];
        foreach ($messages as $index => $message) {
            $message->addMetadata('__id', 'msg_' . $ids[$index]);
            $message->addMetadata('timestamp', sprintf('2026-01-02T03:04:%02d+00:00', $index + 5));
        }
        $messages[0]->addMetadata('claire_submission_id', 'submission_fixture');
        $messages[1]->addMetadata('claire_message_id', 'assistant.fixture.hello')
            ->addMetadata('claire_audio_request_id', null);
        $activeFinal = clone $messages[7];
        $messages[7]->addMetadata('claire_message_id', 'assistant.fixture.final')
            ->addMetadata('claire_audio_request_id', 'audio_fixture_final');
        $summary = 'The synthetic user requested a report and audio.';
        $summaryMessage = new UserMessage("[OC]Previous conversation summary:\n\n{$summary}\n[\\OC]");
        $summaryMessage->addMetadata('__id', 'msg_summary')->addMetadata('message_type', 'out_of_context');
        $actual = [
            'title' => 'Synthetic fixture conversation',
            'summary' => $summary,
            'messages' => [$summaryMessage, $activeFinal],
            'display_messages' => $messages,
        ];
        // JSON normalizes backed enums, empty input objects and scalar metadata.
        self::assertSame($this->history(), json_decode(
            json_encode($actual, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR,
        ));
    }

    public function testV3ReaderLimitationsAreExplicitRatherThanBakedIntoMigrationExpectations(): void
    {
        $this->requireV3();
        $reader = new class extends AbstractChatHistory {
            public function decode(array $messages): array
            {
                return $this->deserializeMessages($messages);
            }
        };
        $fixture = $this->history()['display_messages'];
        $messages = $reader->decode($fixture);
        foreach ([0, 1, 2, 3, 5, 7] as $index) {
            self::assertEquals($fixture[$index], json_decode(
                json_encode($messages[$index], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR,
            ));
        }
        foreach ([4, 6] as $index) {
            // v3 never invokes deserializeMeta for tool results; migration must preserve the raw values.
            self::assertNull($messages[$index]->getMetadata('timestamp'));
            self::assertNotSame($fixture[$index]['__id'], $messages[$index]->getMetadata('__id'));
            self::assertSame([], $messages[$index]->getTools()[0]->getParameters());
            self::assertSame($fixture[$index]['tools'][0]['result'], $messages[$index]->getTools()[0]->getResult());
        }
    }

    public function testOldStoreMatchesV3WriterAndCanBeSearchedWithoutEmbeddingsService(): void
    {
        $this->requireV3();
        $directory = sys_get_temp_dir() . '/claire-v3-fixture-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        try {
            $store = new FileVectorStore($directory, 2, 'synthetic');
            foreach ([['fixture-document-1', 'report', [1, 0, 0], 'fixture.txt', 101],
                [102, 'audio', [0, 1, 0], 'audio.txt', 102]] as [$id, $label, $embedding, $name, $documentId]) {
                $document = new Document('Synthetic ' . $label . ' source.');
                $document->id = $id;
                $document->embedding = $embedding;
                $document->sourceType = 'file';
                $document->sourceName = $name;
                $document->metadata = ['document_id' => $documentId, 'chunk' => 0];
                $store->addDocument($document);
            }
            self::assertSame(
                file_get_contents(self::FIXTURES . 'documents.store'),
                file_get_contents($directory . '/synthetic.store'),
            );
            $results = new FileVectorStore(self::FIXTURES, 2, 'documents')->similaritySearch([1, 0, 0]);
            self::assertSame(
                ['fixture-document-1', 102],
                array_map(static fn (Document $doc): string|int => $doc->id, $results),
            );
            self::assertSame(['document_id' => 101, 'chunk' => 0], $results[0]->metadata);
            self::assertSame(1.0, $results[0]->score);
        } finally {
            if (is_file($directory . '/synthetic.store')) {
                unlink($directory . '/synthetic.store');
            }
            rmdir($directory);
        }
    }

    private function history(): array
    {
        return json_decode(file_get_contents(self::FIXTURES . 'history.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function requireV3(): void
    {
        if (InstalledVersions::getPrettyVersion('neuron-core/neuron-ai') !== '3.16.9') {
            self::markTestSkipped('Frozen v3 producer checks require Neuron 3.16.9; consume fixtures in migration tests.');
        }
    }
}
