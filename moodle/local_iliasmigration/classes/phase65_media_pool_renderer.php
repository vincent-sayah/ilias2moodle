<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Pure renderer for normalized ILIAS Media Pool records.
 */
final class phase65_media_pool_renderer {
    private $assetresolver;
    private array $media = [];
    private array $assets = [];

    public function render(
        array $structure,
        callable $assetresolver
    ): array {
        $this->assetresolver = $assetresolver;
        $this->media = is_array($structure['media'] ?? null)
            ? $structure['media']
            : [];
        $this->assets = [];

        $rendered = [];
        foreach ((array) ($structure['records'] ?? []) as $record) {
            if (!is_array($record)) {
                continue;
            }

            $treeid = (string) ($record['source_tree_id'] ?? '');
            $type = (string) ($record['item_type'] ?? '');
            $html = '';

            if ($type === 'media') {
                $mediaid = (string) ($record['media_source_id'] ?? '');
                $media = is_array($this->media[$mediaid] ?? null)
                    ? $this->media[$mediaid]
                    : [];
                $html = $this->render_media($mediaid, $media);
            } else if ($type === 'page') {
                $content = is_array($record['content'] ?? null)
                    ? $record['content']
                    : [];
                $blocks = is_array($content['blocks'] ?? null)
                    ? $content['blocks']
                    : [];
                $html = $this->render_blocks($blocks);
            } else {
                throw new \coding_exception(
                    'Unsupported Media Pool record type: ' . $type
                );
            }

            $rendered[] = [
                'source_tree_id' => $treeid,
                'item_type' => $type,
                'title' => (string) ($record['title'] ?? ''),
                'folder_path' => array_values(array_map(
                    'strval',
                    (array) ($record['folder_path'] ?? [])
                )),
                'html' => $html,
                'html_bytes' => strlen($html),
                'html_sha256' => hash('sha256', $html),
            ];
        }

        return [
            'records' => $rendered,
            'assets' => array_values($this->assets),
            'fingerprint_sha256' => hash(
                'sha256',
                implode(
                    "\n",
                    array_map(
                        static fn(array $record): string =>
                            $record['source_tree_id']
                            . ':'
                            . $record['html_sha256'],
                        $rendered
                    )
                )
            ),
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
            $body = $inline
                ? $this->render_inline($inline)
                : $this->escape((string) ($block['text'] ?? ''));

            return '<' . $tag . '>' . $body . '</' . $tag . ">\n";
        }

        if ($type === 'media') {
            $mediaid = (string) ($block['source_id'] ?? '');
            $media = is_array($this->media[$mediaid] ?? null)
                ? $this->media[$mediaid]
                : [];
            return $this->render_media($mediaid, $media);
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

        if ($type === 'section') {
            return '<section class="ilias-media-pool-section">'
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

        if ($type === 'internal_link') {
            throw new \coding_exception(
                'ILIAS internal links are not validated for Media Pool apply.'
            );
        }

        throw new \coding_exception(
            'Unsupported Media Pool Page Editor block: ' . $type
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
                    'ILIAS internal links are not validated for Media Pool apply.'
                );
            } else {
                $html .= $content;
            }
        }
        return $html;
    }

    private function render_media(
        string $mediaid,
        array $media
    ): string {
        $items = is_array($media['items'] ?? null)
            ? $media['items']
            : [];
        if (!$items) {
            throw new \coding_exception(
                'Media Pool media object has no media item.'
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
        $mime = strtolower(trim((string) ($selected['mime_type'] ?? '')));
        if ($path === '') {
            throw new \coding_exception(
                'Media Pool media item has no migration_path.'
            );
        }

        $url = ($this->assetresolver)($path, $selected);
        $this->assets[$path] = [
            'source_id' => $mediaid,
            'migration_path' => $path,
            'resolved_url' => $url,
            'mime_type' => $mime,
        ];

        $title = (string) (
            $media['title']
            ?? $selected['location']
            ?? basename($path)
        );
        $caption = trim((string) ($selected['caption'] ?? ''));

        if (str_starts_with($mime, 'image/')) {
            $html = '<figure><img class="img-fluid" src="'
                . $this->escape($url)
                . '" alt="' . $this->escape($title) . '">';
            if ($caption !== '') {
                $html .= '<figcaption>'
                    . $this->escape($caption)
                    . '</figcaption>';
            }
            return $html . "</figure>\n";
        }

        if ($mime === 'video/mp4') {
            return '<video controls class="img-fluid" src="'
                . $this->escape($url)
                . '">'
                . $this->escape($title)
                . "</video>\n";
        }

        throw new \coding_exception(
            'Media Pool MIME type is not validated: ' . $mime
        );
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
