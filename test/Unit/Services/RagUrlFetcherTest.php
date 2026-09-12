<?php

declare(strict_types=1);

namespace App\Test\Unit\Services;

use App\Services\RagUrlFetcher;
use App\Services\RagUrlTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RagUrlFetcherTest extends TestCase
{
    public static function unsafeUrls(): iterable
    {
        foreach (['file:///etc/passwd', 'ftp://example.com/a', 'http://user:pass@example.com',
            'http://user@example.com', 'http://@example.com', 'http://user%40name@example.com',
            'http://example.com:0/', 'http://example.com:65536/', 'http://example.com:-1/',
            'http://example.com:abc/',
            'http://localhost', 'http://127.0.0.1', 'http://10.0.0.1', 'http://172.16.0.1',
            'http://192.168.1.1', 'http://169.254.169.254', 'http://100.100.100.200',
            'http://0.0.0.0', 'http://224.0.0.1', 'http://2130706433', 'http://0x7f000001',
            'http://127.1', 'http://0177.0.0.1', 'http://[::1]', 'http://[::]', 'http://[fc00::1]',
            'http://[fe80::1]', 'http://[::ffff:127.0.0.1]', 'http://[64:ff9b::7f00:1]',
            'http://[::ffff:7f00:1]', 'http://[::ffff:192.168.1.1]', 'http://[::ffff:93.184.216.34]',
            'http://[2002:7f00:1::]', 'http://[2001:db8::1]', "http://example.com/\r\nHost:localhost",
            'http://example.com\\@127.0.0.1'] as $url) {
            yield $url => [$url];
        }
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeUrlsWithoutNetwork(string $url): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::never())->method('resolve');
        $transport->expects(self::never())->method('get');
        $this->expectException(RuntimeException::class);
        (new RagUrlFetcher($transport))->fetch($url);
    }

    public static function unsafeDnsAddresses(): iterable
    {
        foreach (['::1', '::ffff:127.0.0.1', '::ffff:7f00:1', '::ffff:10.0.0.1',
            '::ffff:93.184.216.34'] as $address) {
            yield $address => [$address];
        }
    }

    #[DataProvider('unsafeDnsAddresses')]
    public function testRejectsMixedDnsAnswers(string $address): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::once())->method('resolve')->willReturn(['93.184.216.34', $address]);
        $transport->expects(self::never())->method('get');
        $this->expectException(RuntimeException::class);
        (new RagUrlFetcher($transport))->fetch('https://example.com');
    }

    public function testPinsAddressAndResolvesRelativeRedirect(): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::exactly(2))->method('resolve')->with('example.com')
            ->willReturn(['2606:4700:4700::1111']);
        $calls = 0;
        $transport->expects(self::exactly(2))->method('get')->willReturnCallback(
            function ($url, $host, $port, $ip, $timeout) use (&$calls): array {
                self::assertSame('example.com', $host);
                self::assertSame(443, $port);
                self::assertSame('2606:4700:4700::1111', $ip);
                self::assertGreaterThan(0, $timeout);
                self::assertLessThanOrEqual(15, $timeout);
                self::assertSame($calls++ === 0 ? 'https://example.com/a' : 'https://example.com/b', $url);
                return $calls === 1
                    ? ['status' => 302, 'location' => '/b', 'body' => '']
                    : ['status' => 200, 'location' => '', 'body' => ' document '];
            }
        );
        self::assertSame('document', (new RagUrlFetcher($transport))->fetch('https://example.com/a'));
    }

    public function testRedirectCannotRebindDns(): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::exactly(2))->method('resolve')
            ->willReturnOnConsecutiveCalls(['93.184.216.34'], ['127.0.0.1']);
        $transport->expects(self::once())->method('get')
            ->willReturn(['status' => 302, 'location' => '/private', 'body' => '']);
        $this->expectException(RuntimeException::class);
        (new RagUrlFetcher($transport))->fetch('https://example.com');
    }

    #[DataProvider('unsafeUrls')]
    public function testRedirectDestinationsAreValidated(string $url): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::once())->method('resolve')->willReturn(['93.184.216.34']);
        $transport->expects(self::once())->method('get')
            ->willReturn(['status' => 302, 'location' => $url, 'body' => '']);
        $this->expectException(\Exception::class);
        (new RagUrlFetcher($transport))->fetch('https://example.com');
    }

    public function testRedirectsAreLimited(): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::exactly(4))->method('resolve')->willReturn(['93.184.216.34']);
        $transport->expects(self::exactly(4))->method('get')
            ->willReturn(['status' => 302, 'location' => '/loop', 'body' => '']);
        $this->expectException(RuntimeException::class);
        (new RagUrlFetcher($transport))->fetch('https://example.com');
    }

    public function testTransferFailureIsNotIndexed(): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::once())->method('resolve')->willReturn(['93.184.216.34']);
        $transport->expects(self::once())->method('get')->willThrowException(new RuntimeException('timeout'));
        $manager = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $manager->expects(self::never())->method('persist');
        $embeddings = $this->createMock(\NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface::class);
        $embeddings->expects(self::never())->method('embedDocuments');
        $service = new \App\Services\RagService(
            $manager, $embeddings, new \App\Services\Settings([]), new \Psr\Log\NullLogger(),
            new RagUrlFetcher($transport)
        );
        $this->expectException(RuntimeException::class);
        $service->createFromUrl(new \App\Entity\User(), 'document', 'https://example.com');
    }

    public function testDnsFailureIsClosed(): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::once())->method('resolve')->willReturn([]);
        $transport->expects(self::never())->method('get');
        $this->expectException(RuntimeException::class);
        (new RagUrlFetcher($transport))->fetch('https://example.com');
    }

    public function testErrorResponseIsRejected(): void
    {
        $transport = $this->createMock(RagUrlTransport::class);
        $transport->expects(self::once())->method('get')
            ->willReturn(['status' => 500, 'location' => '', 'body' => 'error']);
        $this->expectException(RuntimeException::class);
        (new RagUrlFetcher($transport))->fetch('https://93.184.216.34');
    }

    public function testCurlOptionsAndStreamingLimitsWithoutNetwork(): void
    {
        $transport = new class extends RagUrlTransport {
            protected function execute(array $options): int
            {
                TestCase::assertSame(['example.com:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
                TestCase::assertSame('', $options[CURLOPT_PROXY]);
                TestCase::assertSame('*', $options[CURLOPT_NOPROXY]);
                TestCase::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
                TestCase::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
                TestCase::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
                TestCase::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
                TestCase::assertSame(1500, $options[CURLOPT_TIMEOUT_MS]);
                TestCase::assertSame(1500, $options[CURLOPT_CONNECTTIMEOUT_MS]);
                $write = $options[CURLOPT_WRITEFUNCTION];
                TestCase::assertSame(self::MAX_BYTES, $write(null, str_repeat('a', self::MAX_BYTES)));
                TestCase::assertSame(0, $write(null, 'b'));
                $header = $options[CURLOPT_HEADERFUNCTION];
                TestCase::assertSame(0, $header(null, 'Content-Length: ' . (self::MAX_BYTES + 1) . "\r\n"));
                TestCase::assertSame(0, $header(null, str_repeat('a', 32769)));
                return 200;
            }
        };
        self::assertSame(RagUrlTransport::MAX_BYTES, strlen(
            $transport->get('https://example.com', 'example.com', 443, '93.184.216.34', 1.5)['body']
        ));
    }

    public static function publicUrls(): iterable
    {
        yield ['http://example.com/document', 'example.com:80:93.184.216.34'];
        yield ['http://example.com:8080/document', 'example.com:8080:93.184.216.34'];
        yield ['https://example.com:8443/document', 'example.com:8443:93.184.216.34'];
        yield ['http://93.184.216.34:8080/document', null];
        yield ['http://[2606:4700:4700::1111]:8080/document', null];
    }

    #[DataProvider('publicUrls')]
    public function testPublicDownloadSucceedsWithEnvironmentProxiesDisabled(string $url, ?string $pin): void
    {
        $environment = [];
        foreach (['http_proxy', 'https_proxy', 'all_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'ALL_PROXY'] as $name) {
            $environment[$name] = getenv($name);
            putenv($name . '=http://127.0.0.1:9');
        }
        try {
            $transport = new class ($url, $pin) extends RagUrlTransport {
                public function __construct(private string $url, private ?string $pin)
                {
                }

                public function resolve(string $host): array
                {
                    TestCase::assertSame('example.com', $host);
                    return ['93.184.216.34'];
                }

                protected function execute(array $options): int
                {
                    TestCase::assertSame('http://127.0.0.1:9', getenv('all_proxy'));
                    TestCase::assertSame('', $options[CURLOPT_PROXY]);
                    TestCase::assertSame('*', $options[CURLOPT_NOPROXY]);
                    TestCase::assertSame($this->url, $options[CURLOPT_URL]);
                    TestCase::assertSame($this->pin === null ? [] : [$this->pin], $options[CURLOPT_RESOLVE]);
                    TestCase::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
                    $body = 'Public document downloaded successfully.';
                    TestCase::assertSame(strlen($body), $options[CURLOPT_WRITEFUNCTION](null, $body));
                    return 200;
                }
            };
            self::assertSame('Public document downloaded successfully.', (new RagUrlFetcher($transport))->fetch($url));
        } finally {
            foreach ($environment as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
