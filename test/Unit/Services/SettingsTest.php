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



    public function testExplicitDefaultDoesNotReplaceConfiguredValues(): void
    {
        $settings = new Settings(['enabled' => false, 'nullable' => null]);
        self::assertFalse($settings->get('missing.enabled', false));
        self::assertNull($settings->get('missing', null));
        self::assertFalse($settings->get('enabled', true));
        self::assertNull($settings->get('nullable', 'fallback'));
    }

    public function testSqlObservabilityRequiresExplicitActivation(): void
    {
        $enabled = getenv('SQL_OBSERVABILITY_ENABLED');
        $maxBytes = getenv('SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES');
        try {
            putenv('SQL_OBSERVABILITY_ENABLED');
            putenv('SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES');
            $config = require Settings::getAppRoot() . '/config/settings/observability.php';
            self::assertSame(['sql' => ['enabled' => false, 'maxQueryTextBytes' => 16384]], $config);

            putenv('SQL_OBSERVABILITY_ENABLED=true');
            putenv('SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES=256');
            $config = require Settings::getAppRoot() . '/config/settings/observability.php';
            self::assertSame(['sql' => ['enabled' => true, 'maxQueryTextBytes' => 256]], $config);

            putenv('SQL_OBSERVABILITY_ENABLED=false');
            putenv('SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES=-1');
            $config = require Settings::getAppRoot() . '/config/settings/observability.php';
            self::assertSame(['sql' => ['enabled' => false, 'maxQueryTextBytes' => 1]], $config);
        } finally {
            putenv($enabled === false ? 'SQL_OBSERVABILITY_ENABLED' : 'SQL_OBSERVABILITY_ENABLED=' . $enabled);
            putenv($maxBytes === false
                ? 'SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES'
                : 'SQL_OBSERVABILITY_MAX_QUERY_TEXT_BYTES=' . $maxBytes);
        }
    }

    public function testGetAppRoot(): void

    {

        $root = Settings::getAppRoot();

        $this->assertDirectoryExists($root);

        $this->assertFileExists($root . DIRECTORY_SEPARATOR . 'composer.json');

    }

}
