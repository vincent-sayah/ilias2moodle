<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Render normalized ILIAS Glossary term definitions as deterministic HTML.
 *
 * This renderer is pure and performs no Moodle writes. It delegates Page Editor
 * blocks to the Content Page renderer already validated in Phase 6.5.1.
 */
final class phase65_glossary_renderer {
    /**
     * Render every glossary term definition.
     *
     * @param array $structure Normalized glossaries/<ref_id>/structure.json.
     * @return array Rendered terms, aggregate assets/internal links and fingerprint.
     */
    public function render(array $structure): array {
        $terms = is_array($structure['terms'] ?? null) ? $structure['terms'] : [];
        $rendered = [];
        $assets = [];
        $links = [];
        $fingerprintparts = [];

        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }

            $definition = is_array($term['definition'] ?? null)
                ? $term['definition']
                : [];
            $blocks = is_array($definition['blocks'] ?? null)
                ? $definition['blocks']
                : [];

            // Keep the complete normalized glossary asset path in @@PLUGINFILE@@
            // during the dry-run. The future executor must persist the same path
            // exactly, so the approved fingerprint remains deterministic.
            $termstructure = [
                'source' => ['ref_id' => ''],
                'blocks' => $blocks,
            ];

            $renderer = new phase65_content_renderer();
            $result = $renderer->render(
                $termstructure,
                static function(array $link): array {
                    return [
                        'status' => 'PRESERVED',
                        'reason' => 'GLOSSARY_INTERNAL_LINK_POLICY_PENDING',
                        'candidate_count' => 0,
                        'url' => '',
                        'fallback_url' => '',
                    ];
                }
            );

            $html = (string) ($result['html'] ?? '');
            $termid = (string) ($term['source_id'] ?? '');
            $termname = (string) ($term['term'] ?? '');
            $termhash = hash('sha256', $html);
            $termassets = is_array($result['assets'] ?? null) ? $result['assets'] : [];
            $termlinks = is_array($result['internal_link_resolutions'] ?? null)
                ? $result['internal_link_resolutions']
                : [];

            foreach ($termassets as $asset) {
                if (is_array($asset)) {
                    $asset['term_id'] = $termid;
                    $assets[] = $asset;
                }
            }
            foreach ($termlinks as $link) {
                if (is_array($link)) {
                    $link['term_id'] = $termid;
                    $links[] = $link;
                }
            }

            $rendered[] = [
                'source_id' => $termid,
                'term' => $termname,
                'language' => (string) ($term['language'] ?? ''),
                'definition_status' => (string) ($definition['status'] ?? ''),
                'html' => $html,
                'html_bytes' => strlen($html),
                'html_sha256' => $termhash,
                'asset_count' => count($termassets),
                'internal_link_count' => count($termlinks),
            ];
            $fingerprintparts[] = $termid . ':' . $termhash;
        }

        return [
            'terms' => $rendered,
            'assets' => $assets,
            'internal_link_resolutions' => $links,
            'fingerprint_sha256' => hash('sha256', implode("\n", $fingerprintparts)),
        ];
    }
}
