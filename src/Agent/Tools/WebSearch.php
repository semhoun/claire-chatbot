<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use GuzzleHttp\Client;
use NeuronAI\Exceptions\ToolException;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

class WebSearch extends Tool
{
    public function __construct(private readonly string $searxngUrl)
    {
        parent::__construct(
            'web_search',
            <<< EOT
Performs a web search using the SearXNG API, ideal for general queries, news, articles, and online content.
Use this for broad information gathering, recent events, or when you need diverse web sources.
Results are returned in json format.
EOT
        );
    }

    public function __invoke(
        string $query,
        ?int $pageno = null,
        ?string $time_range = null
    ): string {
        $queryParams = [
            'q' => $query,
            'format' => 'json',
            'safesearch' => 0,
        ];
        if ($pageno !== null) {
            $queryParams['pageno'] = $pageno;
        }

        if ($time_range !== null) {
            $queryParams['time_range'] = $time_range;
        }

        try {
            $client = new Client(['timeout' => 15]);
            $response = $client->request('GET', $this->searxngUrl, [
                'query' => $queryParams,
            ]);
            return $response->getBody()->getContents();
        } catch (\Exception $exception) {
            throw new ToolException('Failed to read URL: ' . $exception->getMessage());
        }
    }

    #[\Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                'query',
                PropertyType::STRING,
                'The search query. This is the main input for the web search',
                true
            ),
            new ToolProperty(
                'pageno',
                PropertyType::INTEGER,
                'Search page number (starts at 1)',
                false
            ),
            new ToolProperty(
                'time_range',
                PropertyType::STRING,
                'Time range of search (day, month, year)',
                false,
                ['day', 'month', 'year']
            ),
        ];
    }
}
