<?php

declare(strict_types=1);

namespace App\Services\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class OCFilterExtension extends AbstractExtension
{
    /**
     * @return array<TwigFilter>
     */
    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('filter_oc_tags', $this->filterOCTags(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * Filtre les balises [OC] et [/OC] du contenu.
     * Retourne une chaîne vide si le contenu ne contient que ces balises ou est vide.
     */
    public function filterOCTags(string $content): string
    {
        // Retirer les blocs [OC]...[/OC] sur plusieurs lignes
        $filtered = preg_replace('/\[OC\].*?\[\/OC\]/s', '', $content);

        // Retirer les balises isolées [OC] et [/OC] au cas où
        $filtered = preg_replace('/\[OC\]|\[\/OC\]/', '', (string) $filtered);

        // Trim et vérifier si vide
        return trim((string) $filtered);
    }
}
