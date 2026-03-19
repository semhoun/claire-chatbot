<?php

declare(strict_types=1);

namespace App\Test\Unit\Messages;

use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use PHPUnit\Framework\TestCase;

final class MessageTimestampTest extends TestCase
{
    public function testUserMessageWithTimestamp(): void
    {
        $userMessage = new UserMessage('Hello');
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $userMessage->addMetadata('timestamp', $timestamp);

        $serialized = $userMessage->jsonSerialize();

        $this->assertArrayHasKey('timestamp', $serialized);
        $this->assertSame($timestamp, $serialized['timestamp']);
        $this->assertSame('user', $serialized['role']);
    }

    public function testAssistantMessageWithTimestamp(): void
    {
        $assistantMessage = new AssistantMessage('Hi there!');
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $assistantMessage->addMetadata('timestamp', $timestamp);

        $serialized = $assistantMessage->jsonSerialize();

        $this->assertArrayHasKey('timestamp', $serialized);
        $this->assertSame($timestamp, $serialized['timestamp']);
        $this->assertSame('assistant', $serialized['role']);
    }

    public function testTimestampPreservedAfterJsonEncodeDecode(): void
    {
        $userMessage = new UserMessage('Test message');
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $userMessage->addMetadata('timestamp', $timestamp);

        // Serialize to JSON and back
        $json = json_encode($userMessage->jsonSerialize(), JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('timestamp', $decoded);
        $this->assertSame($timestamp, $decoded['timestamp']);
    }
}
