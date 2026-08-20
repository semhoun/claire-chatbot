<?php

declare(strict_types=1);

namespace App\Brain\AgentTrait;

use App\Brain\Tools\GenerateImageTool;
use App\Brain\Tools\PdfGeneratorTool;
use App\Brain\Tools\RagSearchTool;
use App\Brain\Tools\TextToSpeechTool;
use App\Brain\Tools\WebToolkit;
use App\Services\Audio\AudioServiceInterface;
use App\Services\AudioGeneratorService;
use App\Services\ComfyUIService;
use App\Services\PdfGeneratorService;
use App\Services\RagService;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
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

        if ($this->settings->get('tools.comfyui.enabled') === true) {
            $tools[] = GenerateImageTool::make(
                $this->container->get(ComfyUIService::class),
                $this->container->get(Settings::class),
                $this->session,
                $this->threadId,
                $this->logger,
            );
        }

        if ($this->settings->get('tools.searXNG.enabled') === true) {
            $tools[] = WebToolkit::make($this->settings->get('tools.searXNG.url'));
        }

        if ($this->settings->get('tools.pdf.enabled') === true) {
            $tools[] = PdfGeneratorTool::make(
                $this->container->get(PdfGeneratorService::class),
                $this->container->get(Settings::class),
                $this->session,
                $this->threadId,
            );
        }

        $audioService = $this->container->get(AudioServiceInterface::class);
        if ($audioService->isAvailable()) {
            $tools[] = TextToSpeechTool::make(
                $this->container->get(AudioGeneratorService::class),
                $audioService,
                $this->session,
                $this->threadId,
            );
        }

        return $this->appendRagSearchTool($tools);
    }

    /**
     * @param array<int, object> $tools
     *
     * @return array<int, object>
     */
    private function appendRagSearchTool(array $tools): array
    {
        try {
            $userId = (string) $this->session->get(\App\Services\Auth::USERID);
            if ($userId === '') {
                return $tools;
            }

            $user = $this->container->get(EntityManagerInterface::class)
                ->getRepository(\App\Entity\User::class)
                ->find($userId);
            if ($user === null) {
                return $tools;
            }

            $ragService = $this->container->get(RagService::class);
            $documents = $ragService->listForUser($user);
            $hasActive = false;
            foreach ($documents as $document) {
                if ($document->isActive()) {
                    $hasActive = true;
                    break;
                }
            }

            if (! $hasActive) {
                return $tools;
            }

            $tools[] = new RagSearchTool(
                $ragService,
                $this->container->get(EntityManagerInterface::class),
                $this->session,
                $this->logger,
            );
        } catch (\Throwable $throwable) {
            $this->logger->warning('Failed to append RAG search tool', [
                'exception' => $throwable,
            ]);
        }

        return $tools;
    }
}
