<?php



declare(strict_types=1);



namespace App\Test\Unit\Renderer;



use App\Renderer\JsonRenderer;

use PHPUnit\Framework\TestCase;

use Slim\Psr7\Response;

use Slim\Psr7\Factory\ResponseFactory;



final class JsonRendererTest extends TestCase

{

    private JsonRenderer $renderer;



    protected function setUp(): void

    {

        $this->renderer = new JsonRenderer();

    }



    public function testJson(): void

    {

        $response = (new ResponseFactory())->createResponse();

        $data = ['foo' => 'bar'];



        $result = $this->renderer->json($response, $data);



        $this->assertSame('application/json', $result->getHeaderLine('Content-Type'));

        $this->assertSame(json_encode($data), (string) $result->getBody());

    }



    public function testHtml(): void

    {

        $response = (new ResponseFactory())->createResponse();

        $data = ['foo' => 'bar'];



        $result = $this->renderer->html($response, $data);



        $body = (string) $result->getBody();

        $this->assertStringContainsString('<html><body><pre>', $body);

        $this->assertStringContainsString('bar', $body);

        $this->assertStringContainsString('</pre></body></html>', $body);

    }

}

