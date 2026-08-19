<?php

declare(strict_types=1);

namespace App\Services;

use App\Brain\BrainRegistry;
use App\Brain\LongTermMemory;
use App\Services\Session\SessionInterface;

final readonly class FrontendConfigFactory
{
    public function __construct(
        private BrainRegistry $brainRegistry,
        private ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private Settings $settings,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(
        SessionInterface $session,
        string $mode,
        string $threadId,
        string $sessionId,
    ): array {
        $brainSlug = (string) $session->get('brain_avatar');
        $brainInfo = $this->brainRegistry->getMeta($brainSlug);
        $userInfo = $session->get(Auth::USERINFO);
        $comfyuiEnabled = $this->settings->get('tools.comfyui.enabled') === true;
        $currentWorkflow = $this->currentWorkflow($session, $comfyuiEnabled);

        return [
            'mode' => $mode,
            'acceptedExt' => $this->settings->get('files.upload.acceptedExt'),
            'threadId' => $threadId,
            'sessionId' => $sessionId,
            'brainInfo' => [
                'name' => $brainInfo['name'],
                'description' => $brainInfo['description'],
                'avatar' => $brainInfo['avatar'],
                'css' => $brainInfo['css'],
                'cssInline' => $brainInfo['css_inline'],
            ],
            'currentBrain' => $brainSlug,
            'brains' => array_map(
                static fn (array $brain): array => [
                    'slug' => $brain['slug'],
                    'name' => $brain['name'],
                    'description' => $brain['description'],
                    'avatar' => $brain['avatar'],
                    'css' => $brain['css'],
                    'cssInline' => $brain['css_inline'],
                ],
                $this->brainRegistry->list()
            ),
            'comfyuiEnabled' => $comfyuiEnabled,
            'workflows' => $comfyuiEnabled
                ? $this->comfyUIWorkflowRegistry->list()
                : [],
            'currentWorkflow' => $currentWorkflow,
            'longTermMemoryEnabled' => $session->get(
                LongTermMemory::SESSION_KEY,
                false
            ),
            'layoutMode' => $session->get('layout_mode', 'full'),
            'user' => is_array($userInfo) ? [
                'id' => (string) $session->get(Auth::USERID),
                'displayName' => (string) ($userInfo['displayName'] ?? ''),
            ] : null,
            'refreshBeforeExpire' => $this->settings->get(
                'session.refresh_before_expire'
            ),
            'refreshMinInterval' => $this->settings->get(
                'session.refresh_min_interval'
            ),
        ];
    }

    private function currentWorkflow(
        SessionInterface $session,
        bool $comfyuiEnabled,
    ): string {
        if (! $comfyuiEnabled) {
            return '';
        }

        $workflow = (string) $session->get(
            ComfyUIWorkflowRegistry::SESSION_KEY,
            ''
        );
        if ($workflow !== '') {
            return $workflow;
        }

        $workflow = $this->comfyUIWorkflowRegistry->getDefaultSlug() ?? '';
        $session->set(ComfyUIWorkflowRegistry::SESSION_KEY, $workflow);

        return $workflow;
    }
}
