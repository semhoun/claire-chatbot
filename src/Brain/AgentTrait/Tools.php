<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\Tools\GenerateImageTool;
use App\Brain\Tools\PdfGeneratorTool;
use App\Brain\Tools\WebToolkit;
use App\Services\ComfyUIService;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\PdfGeneratorService;
use App\Services\Settings;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;

trait Tools
{
    #[\Override]
    /** @return array<int, object> */
    protected function tools(): array
    {
        $tools = [
            CalculatorToolkit::make(),
            CalendarToolkit::make(),
        ];

        if ($this->settings->get('comfyui.enabled') === true) {
            $tools[] = GenerateImageTool::make(
                $this->container->get(ComfyUIService::class),
                $this->container->get(Settings::class),
                $this->session,
                $this->container->get(ComfyUIWorkflowRegistry::class),
            );
        }

        if ($this->settings->get('tools.searXNG.enabled') === true) {
            $tools[] = WebToolkit::make($this->settings->get('tools.searXNG.url'));
        }

        if ($this->settings->get('pdf.enabled') === true) {
            $tools[] = PdfGeneratorTool::make(
                $this->container->get(PdfGeneratorService::class),
                $this->container->get(Settings::class),
                $this->session,
            );
        }

        return $tools;
    }
}
