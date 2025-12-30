<?php

namespace App\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Util\HtmlElement;
use Spatie\CommonMarkHighlighter\FencedCodeRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

readonly class ExtendedFencedCodeRendered implements NodeRendererInterface
{
    public function __construct(private FencedCodeRenderer $defaultRenderer)
    {
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        FencedCode::assertInstanceOf($node);

        // Does this contain a mermaid diagram?
        $infoWords = $node->getInfoWords();
        if (\count($infoWords) !== 0 && $infoWords[0] === 'mermaid') {
            return new HtmlElement('div', ['class' => 'mermaid'], Xml::escape($node->getLiteral()));
        }

        // Nope - use the default renderer instead
        return $this->defaultRenderer->render($node, $childRenderer);
    }
}