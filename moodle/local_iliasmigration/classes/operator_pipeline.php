<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds the resumable operator pipeline from migration.json.
 */
final class operator_pipeline {
    private const FAMILY_ALIASES = [
        'simple_resources' => ['file', 'url', 'html_module'],
        'scorm' => ['scorm', 'sahs'],
        'learning_modules' => ['learning_module', 'lm'],
        'tests_questions' => ['test', 'tst', 'question_pool', 'qpl'],
        'content_pages' => ['content_page', 'copa'],
        'glossaries' => ['glossary', 'glo'],
        'wikis' => ['wiki'],
        'exercises' => ['exercise', 'exc'],
        'forums' => ['forum', 'frm'],
        'mediacasts' => ['mediacast', 'mcst'],
        'blogs' => ['blog'],
        'media_pools' => ['media_pool', 'mep'],
        'item_groups' => ['item_group', 'itgr'],
    ];

    public static function inventory(array $document): array {
        $inventory = [];
        foreach (array_keys(self::FAMILY_ALIASES) as $family) {
            $inventory[$family] = [];
        }

        self::walk(
            (array) ($document['course']['items'] ?? []),
            function(array $item) use (&$inventory): void {
                $type = strtolower(trim((string) ($item['type'] ?? '')));
                $ref = (string) ($item['source_id'] ?? '');
                foreach (self::FAMILY_ALIASES as $family => $aliases) {
                    if (in_array($type, $aliases, true)) {
                        if ($ref !== '') {
                            $inventory[$family][] = $ref;
                        }
                        break;
                    }
                }
            }
        );

        foreach ($inventory as $family => $refs) {
            $refs = array_values(array_unique($refs));
            sort($refs, SORT_NATURAL);
            $inventory[$family] = $refs;
        }

        return $inventory;
    }

    public static function steps(array $document): array {
        $inventory = self::inventory($document);
        $steps = [];
        $sequence = 10;

        $add = static function(
            string $key,
            string $label,
            bool $critical,
            bool $skippable,
            array $refs = []
        ) use (&$steps, &$sequence): void {
            $steps[] = [
                'sequence' => $sequence,
                'stepkey' => $key,
                'label' => $label,
                'critical' => $critical,
                'skippable' => $skippable,
                'context' => [
                    'source_ref_ids' => array_values($refs),
                    'source_object_count' => count($refs),
                ],
            ];
            $sequence += 10;
        };

        $add('preflight', 'Préflight du package', true, false);
        $add('structure', 'Structure du cours', true, false);

        $definitions = [
            'simple_resources' => 'Ressources simples',
            'scorm' => 'SCORM',
            'learning_modules' => 'Learning Modules / Books',
            'tests_questions' => 'Tests et banques de questions',
            'content_pages' => 'Content Pages',
            'glossaries' => 'Glossaires',
            'wikis' => 'Wikis',
            'exercises' => 'Exercices',
            'forums' => 'Forums',
            'mediacasts' => 'Mediacasts',
            'blogs' => 'Blogs',
            'media_pools' => 'Media Pools',
            'item_groups' => 'Item Groups',
        ];

        foreach ($definitions as $key => $label) {
            if (!empty($inventory[$key])) {
                $add($key, $label, false, true, $inventory[$key]);
            }
        }

        if (empty($inventory['item_groups'])) {
            $add('order_reconciliation', 'Réconciliation de l’ordre', false, true);
        }

        $add('final_report', 'Compte rendu final', false, false);

        return $steps;
    }

    private static function walk(array $items, callable $callback): void {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $callback($item);
            self::walk((array) ($item['items'] ?? []), $callback);
        }
    }
}
