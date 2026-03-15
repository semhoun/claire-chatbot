<?php



declare(strict_types=1);



namespace App\Test\Unit\Services;



use App\Services\Health;

use App\Services\Settings;

use PHPUnit\Framework\TestCase;



final class HealthTest extends TestCase

{

    public function testStatusReturnsCorrectData(): void

    {

        $settings = new Settings(['version' => '1.2.3']);

        $health = new Health($settings);



        $status = $health->status();



        $this->assertArrayHasKey('version', $status);

        $this->assertArrayHasKey('date', $status);

        $this->assertSame('1.2.3', $status['version']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $status['date']);

    }

}

