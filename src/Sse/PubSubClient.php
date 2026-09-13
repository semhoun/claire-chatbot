<?php

declare(strict_types=1);

namespace App\Sse;

use Clue\Redis\Protocol\Factory;
use Clue\Redis\Protocol\Model\ErrorReply;
use Clue\Redis\Protocol\Parser\ParserInterface;
use Clue\Redis\Protocol\Serializer\SerializerInterface;
use Evenement\EventEmitter;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use function React\Promise\resolve;
use React\Socket\Connector;
use React\Stream\DuplexStreamInterface;
use RuntimeException;
use Throwable;

/**
 * Own Pub/Sub ACKs: Clue StreamingClient 2.8 leaves an unhandled child promise
 * when a pending SUBSCRIBE/UNSUBSCRIBE fails, even if callers handle its promise.
 */
final class PubSubClient extends EventEmitter
{
    /** @var list<array{command: string, arguments: list<string>, deferred: Deferred}> */
    private array $pending = [];

    private bool $closed = false;

    private readonly ParserInterface $parser;

    private readonly SerializerInterface $serializer;

    public function __construct(private readonly DuplexStreamInterface $stream)
    {
        $protocol = new Factory();
        $this->parser = $protocol->createResponseParser();
        $this->serializer = $protocol->createSerializer();
        $stream->on('data', $this->receive(...));
        $stream->on('error', $this->close(...));
        $stream->on('close', $this->close(...));
    }

    /** @return PromiseInterface<self> */
    public static function connect(string $uri, LoopInterface $loop): PromiseInterface
    {
        $parts = parse_url($uri);
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $timeout = (float) ($query['timeout'] ?? 2);
        $connector = new Connector(['timeout' => $timeout], $loop);
        $connecting = $connector->connect('tcp://' . $parts['host'] . ':' . ($parts['port'] ?? 6379));
        $client = null;
        $handshake = $connecting->then(static function (DuplexStreamInterface $stream) use (&$client, $parts) {
            $client = new self($stream);
            $authenticated = isset($parts['pass'])
                ? $client->request('auth', [rawurldecode($parts['pass'])]) : resolve(null);
            return $authenticated->then(
                static fn () => $client->request('select', [ltrim($parts['path'] ?? '/0', '/')]),
            )->then(static fn () => $client);
        });
        $promise = new Promise(static function ($resolve, $reject) use ($handshake, &$client): void {
            $handshake->then($resolve, static function (Throwable $error) use ($reject, &$client): void {
                $client?->close();
                $reject($error);
            });
        }, static function () use ($connecting, &$client): void {
            $connecting->cancel();
            $client?->close();
            throw new RuntimeException('Pub/Sub connection cancelled');
        });

        return Async::timeout($promise, $loop, $timeout);
    }

    /** @return PromiseInterface<array{string, string, int}> */
    public function subscribe(string $channel): PromiseInterface
    {
        return $this->request('subscribe', [$channel]);
    }

    /** @return PromiseInterface<array{string, string, int}> */
    public function unsubscribe(string $channel): PromiseInterface
    {
        return $this->request('unsubscribe', [$channel]);
    }

    public function close(?Throwable $error = null): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $pending = $this->pending;
        $this->pending = [];
        $this->stream->close();
        $this->emit('close');
        $this->removeAllListeners();
        $error ??= new RuntimeException('Pub/Sub connection closed');
        foreach ($pending as $request) {
            $request['deferred']->reject($error);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return PromiseInterface<mixed>
     */
    private function request(string $command, array $arguments): PromiseInterface
    {
        if ($this->closed) {
            return reject(new RuntimeException('Pub/Sub connection unavailable'));
        }

        $deferred = new Deferred(function (): void {
            $this->close();
        });
        $this->pending[] = ['command' => $command, 'arguments' => $arguments, 'deferred' => $deferred];
        $this->stream->write($this->serializer->getRequestMessage($command, $arguments));

        return $deferred->promise();
    }

    private function receive(string $data): void
    {
        try {
            foreach ($this->parser->pushIncoming($data) as $reply) {
                if ($this->closed) {
                    return;
                }

                $value = $reply->getValueNative();
                if (is_array($value) && ($value[0] ?? null) === 'message') {
                    if (count($value) !== 3 || ! is_string($value[1]) || ! is_string($value[2])) {
                        throw new RuntimeException('Invalid Pub/Sub message');
                    }
                    $this->emit('message', [$value[1], $value[2]]);
                    continue;
                }

                $request = $this->pending[0] ?? null;
                if ($request === null) {
                    throw new RuntimeException('Unexpected Pub/Sub response');
                }
                if ($reply instanceof ErrorReply) {
                    array_shift($this->pending);
                    $request['deferred']->reject($reply);
                    continue;
                }
                if (in_array($request['command'], ['subscribe', 'unsubscribe'], true)
                    && (! is_array($value) || count($value) !== 3 || $value[0] !== $request['command']
                        || $value[1] !== $request['arguments'][0] || ! is_int($value[2]) || $value[2] < 0)) {
                    throw new RuntimeException('Invalid Pub/Sub acknowledgement');
                }

                array_shift($this->pending);
                $request['deferred']->resolve($value);
            }
        } catch (Throwable $error) {
            $this->close($error);
        }
    }
}
