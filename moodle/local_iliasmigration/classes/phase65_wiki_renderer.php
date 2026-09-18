<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Render normalized ILIAS Wiki current pages as deterministic HTML.
 *
 * This renderer is deliberately pure. Wiki-page links are represented by a
 * deterministic source-id anchor during dry-run; the future executor will
 * resolve those source ids only after all Moodle wiki pages have stable ids.
 */
final class phase65_wiki_renderer {
    /**
     * Render all current Wiki pages.
     *
     * @param array $structure Normalized wikis/<ref_id>/structure.json.
     * @return array Rendered pages, assets, links and aggregate fingerprint.
     */
    public function render(array $structure): array {
        $pages = is_array($structure['pages'] ?? null) ? $structure['pages'] : [];
        $rendered = [];
        $assets = [];
        $links = [];
        $fingerprintparts = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageid = (string) ($page['source_id'] ?? '');
            $title = (string) ($page['title'] ?? '');
            $content = is_array($page['content'] ?? null) ? $page['content'] : [];
            $blocks = is_array($content['blocks'] ?? null) ? $content['blocks'] : [];

            // Empty ref_id keeps the complete normalized Wiki asset path in
            // @@PLUGINFILE@@. The future executor must persist the same path.
            $pagestructure = [
                'source' => ['ref_id' => ''],
                'blocks' => $blocks,
            ];

            $contentrenderer = new phase65_content_renderer();
            $result = $contentrenderer->render(
                $pagestructure,
                static function(array $link): array {
                    $target = (string) ($link['target'] ?? '');
                    $targettype = (string) ($link['target_type'] ?? '');
                    $sourceid = (string) ($link['source_ref_id'] ?? '');

                    if ($targettype === 'wpg'
                            || preg_match('/(?:^|_)wpg_(\d+)$/', $target, $matches) === 1) {
                        if ($sourceid === '' && isset($matches[1])) {
                            $sourceid = (string) $matches[1];
                        }
                        return [
                            'status' => 'WIKI_PAGE_REFERENCE',
                            'reason' => 'TWO_PASS_PAGE_MAPPING_REQUIRED',
                            'candidate_count' => 1,
                            'url' => '#ilias-wiki-page-' . rawurlencode($sourceid),
                            'fallback_url' => '',
                            'link_scope' => 'wiki_page',
                            'wiki_page_source_id' => $sourceid,
                        ];
                    }

                    return [
                        'status' => 'REPOSITORY_REFERENCE',
                        'reason' => 'MOODLE_MAPPING_REQUIRED',
                        'candidate_count' => 0,
                        'url' => '',
                        'fallback_url' => '',
                        'link_scope' => 'repository_object',
                    ];
                }
            );

            $html = (string) ($result['html'] ?? '');
            $hash = hash('sha256', $html);
            $pageassets = is_array($result['assets'] ?? null) ? $result['assets'] : [];
            $pagelinks = is_array($result['internal_link_resolutions'] ?? null)
                ? $result['internal_link_resolutions']
                : [];

            foreach ($pageassets as $asset) {
                if (is_array($asset)) {
                    $asset['page_id'] = $pageid;
                    $assets[] = $asset;
                }
            }
            foreach ($pagelinks as $link) {
                if (is_array($link)) {
                    $link['page_id'] = $pageid;
                    $links[] = $link;
                }
            }

            $rendered[] = [
                'source_id' => $pageid,
                'title' => $title,
                'content_status' => (string) ($content['status'] ?? ''),
                'html' => $html,
                'html_bytes' => strlen($html),
                'html_sha256' => $hash,
                'asset_count' => count($pageassets),
                'internal_link_count' => count($pagelinks),
                'important_page' => is_array($page['important_page'] ?? null)
                    ? $page['important_page']
                    : null,
            ];
            $fingerprintparts[] = $pageid . ':' . $hash;
        }

        return [
            'pages' => $rendered,
            'assets' => $assets,
            'internal_link_resolutions' => $links,
            'fingerprint_sha256' => hash('sha256', implode("\n", $fingerprintparts)),
        ];
    }
}
