<?php

declare(strict_types=1);

namespace App\Test\Unit\Brain\Tools;

use App\Brain\Tools\GenerateImageTool;
use App\Brain\Tools\PdfGeneratorTool;
use App\Brain\Tools\TextToSpeechTool;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneratedFilePostProcessorTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function capabilities(): iterable
    {
        yield 'image' => [GenerateImageTool::class, 'generate_image'];
        yield 'pdf' => [PdfGeneratorTool::class, 'generate_pdf'];
        yield 'speech' => [TextToSpeechTool::class, 'generate_speech'];
    }

    #[DataProvider('capabilities')]
    public function testResultsBelongToCallsNotExecutableCapabilities(string $class, string $name): void
    {
        // No external services are needed to post-process a persisted call result.
        $capability = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $message = new AssistantMessage('Your file');
        $call = ToolCall::make($name, 'first')->setResult('{"status":"success","id":"@@GENERATED@@first@@"}');
        $otherCall = ToolCall::make($name, 'second')->setResult('{"status":"success","id":"@@GENERATED@@second@@"}');

        $capability->postProcessMessage($message, $call);
        $capability->postProcessMessage($message, $otherCall);
        $capability->postProcessMessage($message, $call);

        self::assertSame("Your file\n@@GENERATED@@first@@\n@@GENERATED@@second@@", $message->getContent());
        self::assertSame($name, $capability->getName());
    }

    #[DataProvider('capabilities')]
    public function testMissingMalformedOrErrorResultsNeverFabricateReferences(string $class, string $name): void
    {
        $capability = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $message = new AssistantMessage('No file');
        foreach ([null, 'broken JSON', '{"status":"success","id":123}', ToolOutput::error('Cancelled')] as $result) {
            $call = ToolCall::make($name, 'call');
            if ($result !== null) {
                $call->setResult($result);
            }
            $capability->postProcessMessage($message, $call);
        }

        self::assertSame('No file', $message->getContent());
    }
}
