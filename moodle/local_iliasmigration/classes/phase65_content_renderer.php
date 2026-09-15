<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Render normalized ILIAS Content Page structures as deterministic Moodle Page HTML.
 *
 * The renderer is intentionally pure: Moodle mapping lookups are injected through
 * the internal-link resolver callback so the HTML transformation can be regression tested.
 */
final class phase65_content_renderer {
    /** @var string Prefix removed from normalized package asset paths. */
    private string $assetprefix = '';

    /** @var callable Internal-link resolver. */
    private $linkresolver;

    /** @var array Collected internal-link resolution summaries. */
    private array $linkresolutions = [];

    /** @var array Collected normalized asset references. */
    private array $assets = [];

    /**
     * Render one normalized Content Page document.
     *
     * @param array $structure content_pages/<ref_id>/structure.json document.
     * @param callable $linkresolver Receives one internal_link node and returns a resolution array.
     * @return array Render result with html, link resolutions and asset references.
     */
    public function render(array $structure, callable $linkresolver): array {
        $this->linkresolver = $linkresolver;
        $this->linkresolutions = [];
        $this->assets = [];

        $sourceref = (string) (($structure['source'] ?? [])['ref_id'] ?? '');
        $this->assetprefix = $sourceref !== '' ? 'content_pages/' . $sourceref . '/' : '';

        $html = $this->render_blocks(is_array($structure['blocks'] ?? null) ? $structure['blocks'] : []);

        return [
            'html' => $html,
            'internal_link_resolutions' => $this->linkresolutions,
            'assets' => $this->assets,
        ];
    }

    /** Render a list of normalized block nodes. */
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

    /** Render one normalized block. */
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
            $inline = is_array($block['inline'] ?? null) ? $block['inline'] : [];
            $content = $inline ? $this->render_inline($inline) : $this->escape((string) ($block['text'] ?? ''));
            return '<' . $tag . '>' . $content . '</' . $tag . ">\n";
        }

        if ($type === 'media') {
            return $this->render_media($block);
        }

        if ($type === 'file_list') {
            $title = trim((string) ($block['title'] ?? ''));
            $html = '<div class="ilias-file-list">';
            if ($title !== '') {
                $html .= '<h3>' . $this->escape($title) . '</h3>';
            }
            $html .= '<ul>';
            foreach ((array) ($block['files'] ?? []) as $fileitem) {
                if (!is_array($fileitem)) {
                    continue;
                }
                $file = is_array($fileitem['file'] ?? null) ? $fileitem['file'] : [];
                $migrationpath = (string) ($file['migration_path'] ?? '');
                $filename = (string) ($fileitem['filename'] ?? ($file['filename'] ?? ''));
                if ($migrationpath === '') {
                    $html .= '<li>' . $this->escape($filename) . '</li>';
                    continue;
                }
                $url = $this->pluginfile_url($migrationpath);
                $this->assets[] = [
                    'kind' => 'file',
                    'source_id' => (string) ($fileitem['source_id'] ?? ''),
                    'migration_path' => $migrationpath,
                    'pluginfile_path' => $url,
                ];
                $html .= '<li><a href="' . $this->escape($url) . '">' . $this->escape($filename) . '</a></li>';
            }
            return $html . "</ul></div>\n";
        }

        if ($type === 'table') {
            $html = '<table class="table table-bordered"><tbody>';
            foreach ((array) ($block['rows'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td>' . $this->render_blocks(is_array($cell) ? $cell : []) . '</td>';
                }
                $html .= '</tr>';
            }
            return $html . "</tbody></table>\n";
        }

        if ($type === 'section') {
            $characteristic = strtolower(trim((string) ($block['characteristic'] ?? 'standard')));
            $class = preg_replace('/[^a-z0-9_-]+/', '-', $characteristic) ?: 'standard';
            return '<section class="ilias-section ilias-section-' . $this->escape($class) . '">'
                . $this->render_blocks(is_array($block['blocks'] ?? null) ? $block['blocks'] : [])
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
                $html .= $this->render_blocks(is_array($tab['blocks'] ?? null) ? $tab['blocks'] : []);
                $html .= '</section>';
            }
            return $html . "</div>\n";
        }

        if ($type === 'grid') {
            $html = '<div class="row ilias-grid">';
            foreach ((array) ($block['cells'] ?? []) as $cell) {
                if (!is_array($cell)) {
                    continue;
                }
                $widths = is_array($cell['widths'] ?? null) ? $cell['widths'] : [];
                $width = (int) ($widths['width_m'] ?? 12);
                if ($width < 1 || $width > 12) {
                    $width = 12;
                }
                $html .= '<div class="col-md-' . $width . ' ilias-grid-cell">'
                    . $this->render_blocks(is_array($cell['blocks'] ?? null) ? $cell['blocks'] : [])
                    . '</div>';
            }
            return $html . "</div>\n";
        }

        return '';
    }

    /** Render inline paragraph nodes. */
    private function render_inline(array $parts): string {
        $html = '';
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = (string) ($part['type'] ?? 'text');
            $children = is_array($part['children'] ?? null) ? $part['children'] : [];
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
                $href = $this->safe_external_url((string) ($part['href'] ?? ''));
                $html .= $href !== ''
                    ? '<a href="' . $this->escape($href) . '">' . $content . '</a>'
                    : $content;
            } else if ($type === 'internal_link') {
                $resolution = ($this->linkresolver)($part);
                if (!is_array($resolution)) {
                    $resolution = [];
                }
                $resolution['target'] = (string) ($part['target'] ?? '');
                $resolution['target_type'] = (string) ($part['target_type'] ?? '');
                $resolution['source_ref_id'] = (string) ($part['source_ref_id'] ?? '');
                $this->linkresolutions[] = $resolution;
                $href = trim((string) ($resolution['url'] ?? ''));
                $html .= $href !== ''
                    ? '<a href="' . $this->escape($href) . '">' . $content . '</a>'
                    : $content;
            } else {
                $html .= $content;
            }
        }
        return $html;
    }

    /** Render one media block using the Standard media item when possible. */
    private function render_media(array $block): string {
        $media = is_array($block['media'] ?? null) ? $block['media'] : [];
        $items = is_array($media['items'] ?? null) ? $media['items'] : [];
        if (!$items) {
            return '';
        }

        $selected = null;
        foreach ($items as $item) {
            if (is_array($item) && (string) ($item['purpose'] ?? '') === 'Standard') {
                $selected = $item;
                break;
            }
        }
        if ($selected === null) {
            $selected = is_array($items[0] ?? null) ? $items[0] : [];
        }

        $migrationpath = (string) ($selected['migration_path'] ?? '');
        if ($migrationpath === '') {
            return '';
        }
        $url = $this->pluginfile_url($migrationpath);
        $mime = strtolower((string) ($selected['mime_type'] ?? ''));
        $title = (string) ($media['title'] ?? ($selected['location'] ?? ''));
        $caption = trim((string) ($selected['caption'] ?? ''));
        $this->assets[] = [
            'kind' => 'media',
            'source_id' => (string) ($block['source_id'] ?? ''),
            'migration_path' => $migrationpath,
            'pluginfile_path' => $url,
            'mime_type' => $mime,
        ];

        if (str_starts_with($mime, 'image/')) {
            $html = '<figure><img class="img-fluid" src="' . $this->escape($url)
                . '" alt="' . $this->escape($title) . '">';
            if ($caption !== '') {
                $html .= '<figcaption>' . $this->escape($caption) . '</figcaption>';
            }
            return $html . "</figure>\n";
        }
        if (str_starts_with($mime, 'audio/')) {
            return '<audio controls src="' . $this->escape($url) . '">'
                . $this->escape($title) . "</audio>\n";
        }
        if (str_starts_with($mime, 'video/')) {
            return '<video controls class="img-fluid" src="' . $this->escape($url) . '">'
                . $this->escape($title) . "</video>\n";
        }
        return '<p><a href="' . $this->escape($url) . '">' . $this->escape($title) . "</a></p>\n";
    }

    /** Convert a normalized package path to a mod_page @@PLUGINFILE@@ URL. */
    private function pluginfile_url(string $migrationpath): string {
        $relative = ltrim($migrationpath, '/');
        if ($this->assetprefix !== '' && str_starts_with($relative, $this->assetprefix)) {
            $relative = substr($relative, strlen($this->assetprefix));
        }
        $segments = array_values(array_filter(explode('/', $relative), static fn(string $part): bool => $part !== ''));
        $encoded = implode('/', array_map('rawurlencode', $segments));
        return '@@PLUGINFILE@@/' . $encoded;
    }

    /** Allow only navigation-safe external URL schemes. */
    private function safe_external_url(string $url): string {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
            return '';
        }
        return $url;
    }

    /** HTML-escape arbitrary source content. */
    private function escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
