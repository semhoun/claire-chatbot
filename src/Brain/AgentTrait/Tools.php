<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\Tools\GenerateImageTool;
use App\Brain\Tools\WebToolkit;
use App\Services\ComfyUIService;
use App\Services\Settings;
use NeuronAI\Tools\Toolkits\Calculator\CalculatorToolkit;
use NeuronAI\Tools\Toolkits\Calendar\CalendarToolkit;

trait Tools
{
    #[\Override]
    protected function tools(): array
    {
        $tools = [
            CalculatorToolkit::make(),
            CalendarToolkit::make(),
        ];

        if ($this->settings->get('comfyui.enabled') === true) {
            $tools[] = GenerateImageTool::make($this->container->get(ComfyUIService::class), $this->container->get(Settings::class), $this->session);
        }

        if ($this->settings->get('llm.tools.searchXngUrl') !== null) {
            $tools[] = WebToolkit::make($this->settings->get('llm.tools.searchXngUrl'));
        }

        // TODO gérer les erreurs
        return $tools;
    }
}
