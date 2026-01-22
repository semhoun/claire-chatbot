<?php



declare(strict_types=1);



namespace App\Test\Unit\Services;



use App\Services\Settings;

use PHPUnit\Framework\TestCase;

use RuntimeException;



final class SettingsTest extends TestCase

{

    public function testGetReturnsValueForValidKey(): void

    {

        $data = [

            'app' => [

                'name' => 'Claire',

                'version' => '1.0.0'

            ],

            'debug' => true

        ];

        $settings = new Settings($data);



        $this->assertSame('Claire', $settings->get('app.name'));

        $this->assertSame(true, $settings->get('debug'));

        $this->assertSame($data['app'], $settings->get('app'));

    }



    public function testGetThrowsExceptionForInvalidKey(): void

    {

        $settings = new Settings(['foo' => 'bar']);



        $this->expectException(RuntimeException::class);

        $this->expectExceptionMessage('Trying to fetch invalid setting "invalid.key"');



        $settings->get('invalid.key');

    }



    public function testGetAppRoot(): void

    {

        $root = Settings::getAppRoot();

        $this->assertDirectoryExists($root);

        $this->assertFileExists($root . DIRECTORY_SEPARATOR . 'composer.json');

    }

}

