<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use NeuronAI\Tools\Tool;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

/**
 * @method static static make(string $searxngUrl)
 */
class WebToolkit extends AbstractToolkit
{
    public function __construct(private readonly string $searxngUrl)
    {
    }

    /**
     * @return array<Tool>
     */
    public function provide(): array
    {
        $tools = [
            new WebUrlReader(),
        ];
        if (is_string($this->searxngUrl)) {
            $tools[] = new WebSearch($this->searxngUrl);
        }

        return $tools;
    }
}
