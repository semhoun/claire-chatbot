<?php

declare(strict_types=1);

namespace App\Controller;

use App\Brain\BrainRegistry;
use App\Brain\LongTermMemory;
use App\Brain\LongTermMemoryRebuilder;
use App\Entity\User;
use App\Services\Audio\AudioServiceInterface;
use App\Services\Auth;
use App\Services\ComfyUIWorkflowRegistry;
use App\Services\Session\Trait\SessionFromRequest;
use App\Services\Settings;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final readonly class ConfigController
{
    use SessionFromRequest;

    public function __construct(
        private Twig $twig,
        private EntityManagerInterface $entityManager,
        private BrainRegistry $brainRegistry,
        private ComfyUIWorkflowRegistry $comfyUIWorkflowRegistry,
        private Settings $settings,
        private AudioServiceInterface $audioService,
    ) {
    }

    public function audio(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $data = (array) ($request->getParsedBody() ?? []);
        $updates = [];

        if (array_key_exists('enabled', $data)) {
            $enabled = filter_var(
                $data['enabled'],
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            );
            if ($enabled === null) {
                return $response->withStatus(400);
            }

            $updates[AudioServiceInterface::ENABLED_SESSION_KEY] = $enabled;
        }

        if (array_key_exists('auto_generate', $data)) {
            $autoGenerate = filter_var(
                $data['auto_generate'],
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE,
            );
            if ($autoGenerate === null) {
                return $response->withStatus(400);
            }

            $updates[AudioServiceInterface::AUTO_GENERATE_SESSION_KEY] = $autoGenerate;
        }

        if (array_key_exists('dictation_mode', $data)) {
            $mode = (string) $data['dictation_mode'];
            if (! in_array($mode, ['review', 'auto_send'], true)) {
                return $response->withStatus(400);
            }

            $updates[AudioServiceInterface::DICTATION_MODE_SESSION_KEY] = $mode;
        }

        if (array_key_exists('voice', $data)) {
            $voice = (string) $data['voice'];
            if (! $this->audioService->isAllowedVoice($voice)) {
                return $response->withStatus(400);
            }

            $updates[AudioServiceInterface::VOICE_SESSION_KEY] = $voice;
        }

        if ($updates === []) {
            return $response->withStatus(400);
        }

        $user = $this->entityManager->getRepository(User::class)->find(
            $session->get(Auth::USERID),
        );
        if ($user === null) {
            return $response->withStatus(404);
        }

        $params = $user->getParams() ?? [];
        foreach ($updates as $key => $value) {
            $session->set($key, $value);
            $params[$key] = $value;
        }

        $user->setParams($params);
        $this->entityManager->flush();

        return $response->withStatus(204);
    }

    /**
     * Set current layout width mode in session ("full" | "compact").
     */
    public function layoutMode(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $data = (array) ($request->getParsedBody() ?? []);
        $mode = (string) ($data['mode'] ?? '');
        if (! in_array($mode, ['full', 'compact'], true)) {
            return $response->withStatus(400);
        }

        $session->set('layout_mode', $mode);

        $user = $this->entityManager->getRepository(User::class)->find($session->get(Auth::USERID));
        if ($user === null) {
            return $response->withStatus(404);
        }

        $params = $user->getParams() ?? [];
        $params['layout_mode'] = $mode;
        $user->setParams($params);
        $this->entityManager->flush();

        return $response->withStatus(204);
    }

    /**
     * Set current brain avatar in session (e.g. "claire" | "einstein").
     */
    public function brainAvatar(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $data = (array) ($request->getParsedBody() ?? []);
        $avatar = strtolower((string) ($data['avatar'] ?? ''));

        // Valider dynamiquement via la registry
        if ($avatar === '' || ! $this->brainRegistry->has($avatar)) {
            return $response->withStatus(400);
        }

        $session->set('brain_avatar', $avatar);

        // Persist in user params when available
        $user = $this->entityManager->getRepository(User::class)->find($session->get(Auth::USERID));
        if ($user !== null) {
            $params = $user->getParams() ?? [];
            $params['brain_avatar'] = $avatar;
            $user->setParams($params);
            $this->entityManager->flush();
        }

        // No content, HTMX-friendly
        return $response->withStatus(204);
    }

    public function longTermMemory(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        $data = (array) ($request->getParsedBody() ?? []);
        $enabled = filter_var($data['enabled'] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($enabled === null) {
            return $response->withStatus(400);
        }

        $session->set(LongTermMemory::SESSION_KEY, $enabled);
        $user = $this->entityManager->getRepository(User::class)->find($session->get(Auth::USERID));
        if ($user === null) {
            return $response->withStatus(404);
        }

        $params = $user->getParams() ?? [];
        $params[LongTermMemory::SESSION_KEY] = $enabled;
        $user->setParams($params);
        $this->entityManager->flush();

        return $response->withStatus(204);
    }

    public function rebuildLongTermMemory(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);
        if ($session->get(LongTermMemory::SESSION_KEY, false) !== true) {
            return $response->withStatus(409);
        }

        new LongTermMemoryRebuilder(
            connection: $this->entityManager->getConnection(),
            settings: $this->settings,
            session: $session,
        )->rebuild();

        return $response->withStatus(204);
    }

    public function telegram(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $data = (array) ($request->getParsedBody() ?? []);
        $telegramId = trim((string) ($data['telegram_id'] ?? ''));

        $user = $this->entityManager->getRepository(User::class)->find($session->get(Auth::USERID));
        if ($user === null) {
            return $response->withStatus(404);
        }

        if ($telegramId !== '' && ! ctype_digit($telegramId)) {
            return $this->twig->render($response, 'partials/telegram_config.twig', [
                'telegram_id' => $telegramId,
                'error' => 'L\'identifiant Telegram doit être composé uniquement de chiffres.',
                'success' => null,
            ])->withStatus(422)->withHeader('Content-Type', 'text/html; charset=utf-8');
        }

        if ($telegramId !== '') {
            $existingUser = $this->entityManager->getRepository(User::class)->findByTelegramId($telegramId);
            if ($existingUser !== null && $existingUser->getId() !== $user->getId()) {
                return $this->twig->render($response, 'partials/telegram_config.twig', [
                    'telegram_id' => $user->getTelegramId(),
                    'error' => 'Cet identifiant Telegram est déjà associé à un autre compte.',
                    'success' => null,
                ])->withStatus(409)->withHeader('Content-Type', 'text/html; charset=utf-8');
            }
        }

        $user->setTelegramId($telegramId === '' ? null : $telegramId);
        $this->entityManager->flush();

        return $this->twig->render($response, 'partials/telegram_config.twig', [
            'telegram_id' => $user->getTelegramId(),
            'success' => $telegramId === '' ? 'Association Telegram supprimée avec succès.' : 'Configuration Telegram enregistrée avec succès.',
            'error' => null,
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function telegramForm(Request $request, Response $response): Response
    {
        $session = $this->getSession($request);

        $userId = (string) $session->get(Auth::USERID);
        if ($userId === '') {
            return $response->withStatus(401);
        }

        $user = $this->entityManager->getRepository(User::class)->find($userId);

        if ($user === null) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'partials/telegram_config.twig', [
            'telegram_id' => $user->getTelegramId(),
            'success' => null,
            'error' => null,
        ])->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function comfyuiWorkflow(Request $request, Response $response): Response
    {
        if (! $this->comfyUIWorkflowRegistry->list()) {
            return $response->withStatus(404);
        }

        $session = $this->getSession($request);

        $data = (array) ($request->getParsedBody() ?? []);
        $workflow = strtolower(trim((string) ($data['workflow'] ?? '')));

        if ($workflow === '' || ! $this->comfyUIWorkflowRegistry->has($workflow)) {
            return $response->withStatus(400);
        }

        $session->set(ComfyUIWorkflowRegistry::SESSION_KEY, $workflow);

        $user = $this->entityManager->getRepository(User::class)->find($session->get(Auth::USERID));
        if ($user !== null) {
            $params = $user->getParams() ?? [];
            $params[ComfyUIWorkflowRegistry::SESSION_KEY] = $workflow;
            $user->setParams($params);
            $this->entityManager->flush();
        }

        return $response->withStatus(204);
    }
}
