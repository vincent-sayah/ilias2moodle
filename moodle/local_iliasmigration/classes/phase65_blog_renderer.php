<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Render one normalized ILIAS Blog posting as deterministic HTML.
 *
 * Asset URL creation is injected so the renderer stays independent from
 * Moodle file storage and can be regression tested.
 */
final class phase65_blog_renderer {
    private $assetresolver;
    private array $assets = [];

    public function render(array $posting, callable $assetresolver): array {
        $this->assetresolver = $assetresolver;
        $this->assets = [];

        $content = is_array($posting['content'] ?? null)
            ? $posting['content']
            : [];
        $blocks = is_array($content['blocks'] ?? null)
            ? $content['blocks']
            : [];

        return [
            'html' => $this->render_blocks($blocks),
            'assets' => $this->assets,
        ];
    }

    private function render_blocks(array $blocks): string {
        $html = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $html .= $this->render_block($block);
        }
        return $html;
    }

    private function render_block(array $block): string {
        $type = (string) ($block['type'] ?? '');

        if ($type === 'paragraph') {
            $characteristic = (string) ($block['characteristic'] ?? '');
            $tag = match ($characteristic) {
                'Headline1' => 'h1',
                'Headline2' => 'h2',
                'Headline3' => 'h3',
                'Headline4' => 'h4',
                'Headline5' => 'h5',
                'Headline6' => 'h6',
                default => 'p',
            };
            $inline = is_array($block['inline'] ?? null)
                ? $block['inline']
                : [];
            $content = $inline
                ? $this->render_inline($inline)
                : $this->escape((string) ($block['text'] ?? ''));
            return '<' . $tag . '>' . $content . '</' . $tag . ">\n";
        }

        if ($type === 'media') {
            return $this->render_media($block);
        }

        if ($type === 'file_list') {
            $html = '<ul class="ilias-blog-file-list">';
            foreach ((array) ($block['files'] ?? []) as $fileitem) {
                if (!is_array($fileitem)) {
                    continue;
                }
                $file = is_array($fileitem['file'] ?? null)
                    ? $fileitem['file']
                    : [];
                $path = (string) ($file['migration_path'] ?? '');
                $name = (string) (
                    $fileitem['filename']
                    ?? $file['filename']
                    ?? basename($path)
                );
                if ($path === '') {
                    $html .= '<li>' . $this->escape($name) . '</li>';
                    continue;
                }
                $url = ($this->assetresolver)($path, $file);
                $this->assets[$path] = $url;
                $html .= '<li><a href="' . $this->escape($url) . '">'
                    . $this->escape($name) . '</a></li>';
            }
            return $html . "</ul>\n";
        }

        if ($type === 'grid') {
            $html = '<div class="row ilias-grid">';
            foreach ((array) ($block['cells'] ?? []) as $cell) {
                if (!is_array($cell)) {
                    continue;
                }
                $widths = is_array($cell['widths'] ?? null)
                    ? $cell['widths']
                    : [];
                $width = (int) ($widths['width_m'] ?? 12);
                if ($width < 1 || $width > 12) {
                    $width = 12;
                }
                $html .= '<div class="col-md-' . $width
                    . ' ilias-grid-cell">'
                    . $this->render_blocks(
                        is_array($cell['blocks'] ?? null)
                            ? $cell['blocks']
                            : []
                    )
                    . '</div>';
            }
            return $html . "</div>\n";
        }

        if ($type === 'table') {
            $html = '<table class="table table-bordered"><tbody>';
            foreach ((array) ($block['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>'
                        . $this->render_blocks(is_array($cell) ? $cell : [])
                        . '</td>';
                }
                $html .= '</tr>';
            }
            return $html . "</tbody></table>\n";
        }

        if ($type === 'section') {
            $characteristic = strtolower(
                trim((string) ($block['characteristic'] ?? 'standard'))
            );
            $class = preg_replace(
                '/[^a-z0-9_-]+/',
                '-',
                $characteristic
            ) ?: 'standard';
            return '<section class="ilias-section ilias-section-'
                . $this->escape($class) . '">'
                . $this->render_blocks(
                    is_array($block['blocks'] ?? null)
                        ? $block['blocks']
                        : []
                )
                . "</section>\n";
        }

        if ($type === 'tabs') {
            $html = '<div class="ilias-tabs-static">';
            foreach ((array) ($block['tabs'] ?? []) as $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $caption = trim((string) ($tab['caption'] ?? ''));
                $html .= '<section class="ilias-tab-static">';
                if ($caption !== '') {
                    $html .= '<h3>' . $this->escape($caption) . '</h3>';
                }
                $html .= $this->render_blocks(
                    is_array($tab['blocks'] ?? null)
                        ? $tab['blocks']
                        : []
                );
                $html .= '</section>';
            }
            return $html . "</div>\n";
        }

        throw new \coding_exception(
            'Unsupported Blog block reached the Moodle renderer: ' . $type
        );
    }

    private function render_inline(array $parts): string {
        $html = '';

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }

            $type = (string) ($part['type'] ?? 'text');
            $children = is_array($part['children'] ?? null)
                ? $part['children']
                : [];
            $content = $children
                ? $this->render_inline($children)
                : $this->escape((string) ($part['text'] ?? ''));

            if ($type === 'text') {
                $html .= $this->escape((string) ($part['text'] ?? ''));
            } else if ($type === 'strong') {
                $html .= '<strong>' . $content . '</strong>';
            } else if ($type === 'emphasis') {
                $html .= '<em>' . $content . '</em>';
            } else if ($type === 'underline') {
                $html .= '<u>' . $content . '</u>';
            } else if ($type === 'line_break') {
                $html .= '<br>';
            } else if ($type === 'external_link') {
                $url = $this->safe_external_url(
                    (string) ($part['href'] ?? '')
                );
                $html .= $url !== ''
                    ? '<a href="' . $this->escape($url) . '">'
                        . $content . '</a>'
                    : $content;
            } else if ($type === 'internal_link') {
                throw new \coding_exception(
                    'ILIAS internal links are not validated for Blog apply.'
                );
            } else {
                $html .= $content;
            }
        }

        return $html;
    }

    private function render_media(array $block): string {
        $media = is_array($block['media'] ?? null)
            ? $block['media']
            : [];
        $items = is_array($media['items'] ?? null)
            ? $media['items']
            : [];

        if (!$items) {
            throw new \coding_exception(
                'Blog media block has no normalized media item.'
            );
        }

        $selected = null;
        foreach ($items as $item) {
            if (is_array($item)
                    && (string) ($item['purpose'] ?? '') === 'Standard') {
                $selected = $item;
                break;
            }
        }
        if ($selected === null) {
            $selected = is_array($items[0] ?? null)
                ? $items[0]
                : [];
        }

        $path = trim((string) ($selected['migration_path'] ?? ''));
        if ($path === '') {
            throw new \coding_exception(
                'Blog media block has no migration_path.'
            );
        }

        $mime = strtolower((string) ($selected['mime_type'] ?? ''));
        if (!str_starts_with($mime, 'image/')) {
            throw new \coding_exception(
                'Only image media are validated for Blog apply.'
            );
        }

        $url = ($this->assetresolver)($path, $selected);
        $this->assets[$path] = $url;
        $title = (string) (
            $media['title']
            ?? $selected['location']
            ?? basename($path)
        );
        $caption = trim((string) ($selected['caption'] ?? ''));

        $html = '<figure><img class="img-fluid" src="'
            . $this->escape($url)
            . '" alt="' . $this->escape($title) . '">';
        if ($caption !== '') {
            $html .= '<figcaption>' . $this->escape($caption)
                . '</figcaption>';
        }

        return $html . "</figure>\n";
    }

    private function safe_external_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true)
            ? $url
            : '';
    }

    private function escape(string $value): string {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
