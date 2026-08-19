<?php

declare(strict_types=1);

namespace App\Renderer;

use App\Services\Markdown;
use App\Services\Rendering\GeneratedFileProcessor;
use DateTimeImmutable;

final readonly class ChatHtmlRenderer
{
    public function __construct(
        private Markdown $markdown,
        private GeneratedFileProcessor $generatedFileProcessor,
    ) {
    }

    public function markdown(string $message, bool $placeholder = false): string
    {
        $html = $this->markdown->convert($message);

        return $placeholder
            ? $this->generatedFileProcessor->processPlaceholder($html)
            : $this->generatedFileProcessor->process($html);
    }

    /** @param array<int, array<string, mixed>>|null $messages */
    public function messages(?array $messages): string
    {
        if ($messages === null || $messages === []) {
            return '<span class="claire-typing-indicator">'
                . '<span class="claire-typing-indicator__dot"></span>'
                . '<span class="claire-typing-indicator__dot"></span>'
                . '<span class="claire-typing-indicator__dot"></span></span>';
        }

        return implode('', array_map($this->message(...), $messages));
    }

    /** @param array<string, mixed> $message */
    public function message(array $message): string
    {
        $sent = ($message['sent'] ?? false) === true;
        $id = trim((string) ($message['id'] ?? ''));
        $tools = is_array($message['toolsCall'] ?? null)
            ? $message['toolsCall'] : [];
        $articleId = $id !== '' ? ' id="claire-' . $this->escape($id) . '"' : '';
        $textId = $id !== '' ? ' id="claire-message-' . $this->escape($id) . '"' : '';
        $toolsHtml = $tools !== [] || $id !== ''
            ? $this->toolContainer($tools, $id) : '';
        $time = $this->formatTimestamp((string) ($message['time'] ?? ''));
        $meta = $time . ($sent ? ' • Vous' : '');

        return '<article class="claire-message '
            . ($sent ? 'claire-message--sent' : 'claire-message--received')
            . '"' . $articleId . '><div class="claire-message__bubble">'
            . $toolsHtml . '<span class="claire-message__text"' . $textId . '>'
            . $this->markdown((string) ($message['message'] ?? ''))
            . '</span></div><span class="claire-message__meta">'
            . $this->escape($meta) . '</span></article>';
    }

    /** @param array<string, array<string, mixed>>|array<int, array<string, mixed>> $tools */
    public function tools(array $tools): string
    {
        $html = '';
        foreach ($tools as $tool) {
            $id = $this->escape((string) ($tool['id'] ?? ''));
            $running = ($tool['running'] ?? false) === true
                ? '<span class="claire-tools-running-flag" hidden></span>' : '';
            $inputs = '';
            foreach ((array) ($tool['inputs'] ?? []) as $input) {
                if (! is_array($input)) {
                    continue;
                }

                $value = $input['value'] ?? '';
                if (! is_scalar($value) && $value !== null) {
                    $value = json_encode($value, JSON_THROW_ON_ERROR);
                }

                $inputs .= '<li>' . $this->escape((string) ($input['name'] ?? ''))
                    . ' : ' . $this->escape((string) $value) . '</li>';
            }

            $result = $tool['result'] ?? null;
            $resultHtml = $result !== null && $result !== ''
                ? '<pre class="claire-toolcall__result">'
                    . $this->escape(is_scalar($result)
                        ? (string) $result
                        : json_encode($result, JSON_THROW_ON_ERROR)) . '</pre>'
                : '';
            $html .= '<div class="claire-toolcall__text" id="claire-tool-'
                . $id . '">' . $running . 'Utilisation de l’outil : '
                . $this->escape((string) ($tool['name'] ?? ''))
                . '<br>Paramètres :<br><ul>' . $inputs
                . '</ul>Réponse :<br>' . $resultHtml . '</div>';
        }

        return $html;
    }

    /** @param array<int, array<string, mixed>> $tools */
    private function toolContainer(array $tools, string $id): string
    {
        $containerId = $id !== ''
            ? ' id="claire-toolscall-' . $this->escape($id) . '"' : '';

        return '<div class="claire-message__subbubble '
            . 'claire-message__subbubble--toolcall"><details class="claire-toolcall">'
            . '<summary class="claire-toolcall__summary" aria-label="Appels d’outils">'
            . $this->icon('claire-toolcall__icon claire-toolcall__icon--tool',
                'M14 6 18 2l4 4-4 4M13 7 4 16a2 2 0 0 0 0 3l1 1a2 2 0 0 0 3 0l9-9')
            . '<svg class="claire-toolcall__icon claire-toolcall__icon--spinner" '
            . 'viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" '
            . 'fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-dasharray="42 16"/></svg>'
            . $this->icon('claire-toolcall__icon claire-toolcall__icon--done',
                'M5 12l4 4L19 6')
            . $this->icon('claire-toolcall__chevron', 'M8 10l4 4 4-4')
            . '<span class="claire-visually-hidden">Appels d’outils</span></summary>'
            . '<div class="claire-toolscall-data"' . $containerId . '>'
            . $this->tools($tools) . '</div></details></div>';
    }

    private function formatTimestamp(string $timestamp): string
    {
        if ($timestamp === '') {
            return '';
        }

        try {
            return new DateTimeImmutable($timestamp)->format('H:i');
        } catch (\Exception) {
            return '';
        }
    }

    private function icon(string $class, string $path): string
    {
        return '<svg class="' . $class . '" viewBox="0 0 24 24" aria-hidden="true">'
            . '<path d="' . $path . '" fill="none" stroke="currentColor" '
            . 'stroke-width="1.8" stroke-linecap="round" '
            . 'stroke-linejoin="round"/></svg>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
