<?php

declare(strict_types=1);

namespace App\Test\Unit\Controller;

use App\Brain\BrainAvatar;
use App\Brain\BrainRegistry;
use App\Controller\EmbedController;
use App\Controller\HomeController;
use App\Job\Web\StartThreadJob;
use App\Renderer\VueShell;
use App\Repository\ChatHistoryRepository;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\FrontendConfigFactory;
use App\Services\Queue\QueueDispatcherInterface;
use App\Services\Session\InMemorySession;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

final class BootstrapTestBrain implements BrainAvatar
{
    public const string NAME = '<script>Claire</script>';
}

final class WebBootstrapControllerTest extends TestCase
{
    #[TestWith(['normal'])]
    #[TestWith(['embed'])]
    public function testBootstrapReturnsJsonAndQueuesOnlyOnce(string $mode): void
    {
        $settings = new Settings([
            'llm' => ['brains' => ['test' => BootstrapTestBrain::class], 'yamlBrains' => ['path' => '/tmp/kilo/no-brains']],
            'tools' => ['comfyui' => ['enabled' => false]],
            'files' => ['upload' => ['acceptedExt' => '.txt']],
            'session' => ['refresh_before_expire' => 120, 'refresh_min_interval' => 30],
            'queue' => ['defaultQueue' => 'default'],
        ]);
        $factory = new FrontendConfigFactory(
            new BrainRegistry($settings, $this->createStub(ContainerInterface::class)),
            new ComfyUIWorkflowRegistry($settings), $settings, $this->createStub(AudioServiceInterface::class),
        );
        $repository = $this->createMock(ChatHistoryRepository::class);
        $repository->expects(self::once())->method('deleteEmptyConversations')->with('owner');
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $queue = $this->createMock(QueueDispatcherInterface::class);
        $queued = [];
        $queue->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (string $job, array $payload) use (&$queued): string {
                self::assertSame(StartThreadJob::class, $job);
                self::assertSame('owner', $payload['session'][Auth::USERID]);
                $queued = $payload;
                return 'job';
            },
        );
        $controller = $mode === 'normal'
            ? new HomeController(new VueShell(), $manager, $factory, $settings, $queue)
            : new EmbedController($manager, $factory, $settings, $queue);
        $request = new ServerRequestFactory()->createServerRequest('GET', $mode === 'normal' ? '/' : '/embed')
            ->withAttribute('base_url', 'https://claire.test')
            ->withAttribute('session', new InMemorySession([
                Auth::USERID => 'owner', Auth::AUTHENTICATED => true, 'brain_avatar' => 'test',
            ]));
        if ($mode === 'normal') {
            $shell = $controller->index($request->withHeader('Accept', 'text/html'), new Response());
            self::assertSame('no-store', $shell->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('id="claire-vue-app"', (string) $shell->getBody());
            self::assertStringNotContainsString('<article', (string) $shell->getBody());
            self::assertSame([], $queued);
        }
        $response = $controller->index($request->withHeader('Accept', 'application/json'), new Response());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($mode, $data['mode']);
        self::assertSame($queued['threadId'], $data['threadId']);
        self::assertSame($queued['sessionId'], $data['sessionId']);
        self::assertSame(BootstrapTestBrain::NAME, $data['brainInfo']['name']);
        self::assertArrayNotHasKey('html', $data);
        self::assertArrayNotHasKey('sessionToken', $data);
    }
}
