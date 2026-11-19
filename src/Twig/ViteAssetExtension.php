<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ViteAssetExtension extends AbstractExtension
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vite_entry_tags', $this->renderEntryTags(...), ['is_safe' => ['html']]),
        ];
    }

    public function renderEntryTags(string $entry): string
    {
        $manifest = $this->loadManifest();
        if (!isset($manifest[$entry]) || !is_array($manifest[$entry])) {
            return sprintf('<!-- Vite entry "%s" not built yet. Run npm run build -->', htmlspecialchars($entry, ENT_QUOTES));
        }

        $chunk = $manifest[$entry];
        $tags = [];

        foreach ($chunk['css'] ?? [] as $cssFile) {
            $tags[] = sprintf('<link rel="stylesheet" href="/build/%s">', htmlspecialchars((string) $cssFile, ENT_QUOTES));
        }

        if (isset($chunk['file'])) {
            $tags[] = sprintf('<script type="module" src="/build/%s"></script>', htmlspecialchars((string) $chunk['file'], ENT_QUOTES));
        }

        return implode("\n", $tags);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestPath = $this->projectDir.'/public/build/.vite/manifest.json';
        if (!is_file($manifestPath)) {
            return $this->manifest = [];
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true);

        return $this->manifest = is_array($decoded) ? $decoded : [];
    }
}
