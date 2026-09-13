<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Normalises ILIAS folder trees to the two structural levels Moodle can represent.
 *
 * Policy:
 * - level 1 ILIAS folders stay Moodle course sections;
 * - level 2 ILIAS folders stay Moodle mod_subsection activities;
 * - level 3+ folders are moved beside their level-2 ancestor and also become
 *   mod_subsection activities;
 * - moved folders receive a deterministic hierarchical title such as
 *   "Niveau 2 / Niveau 3 / Niveau 4";
 * - direct pedagogical children stay attached to their nearest source folder.
 *
 * The transformation is Moodle-side only. Source identifiers are never changed,
 * so persistent mappings remain stable and replayable.
 */
final class folder_flattener {
    /** @var array Transformation diagnostics for the current document. */
    private array $changes = [];

    /**
     * Flatten unsupported deep folder nesting without changing source ids.
     *
     * @param array $document Validated migration document.
     * @return array Moodle-normalised migration document.
     */
    public function flatten(array $document): array {
        $this->changes = [];

        $course = $document['course'] ?? null;
        if (!is_array($course) || !is_array($course['items'] ?? null)) {
            return $document;
        }

        $items = [];
        foreach ($course['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['type'] ?? '') === 'folder') {
                $items[] = $this->flatten_level_one_folder($item);
            } else {
                $items[] = $item;
            }
        }

        $document['course']['items'] = $items;

        $source = is_array($document['source'] ?? null) ? $document['source'] : [];
        $transformations = is_array($source['transformations'] ?? null)
            ? $source['transformations']
            : [];
        $transformations['folder_flattening'] = [
            'applied' => !empty($this->changes),
            'strategy' => 'deep_folders_as_sibling_subsections',
            'flattened_count' => count($this->changes),
            'items' => $this->changes,
            'order_note' => 'Nested folders become sibling subsections immediately after their level-2 ancestor; '
                . 'direct activities remain inside their nearest source folder.',
        ];
        $source['transformations'] = $transformations;
        $document['source'] = $source;

        return $document;
    }

    /**
     * Rebuild one level-1 folder while promoting all deeper folders to level 2.
     */
    private function flatten_level_one_folder(array $folder): array {
        $leveloneref = (string) ($folder['source_id'] ?? '');
        $children = is_array($folder['items'] ?? null) ? $folder['items'] : [];
        $rebuilt = [];

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            if (($child['type'] ?? '') !== 'folder') {
                $rebuilt[] = $child;
                continue;
            }

            $path = [$this->folder_label($child)];
            foreach ($this->flatten_subsection_tree($child, $path, $leveloneref, 2, $leveloneref) as $node) {
                $rebuilt[] = $node;
            }
        }

        $folder['items'] = $rebuilt;
        return $folder;
    }

    /**
     * Return one level-2-compatible subsection followed by all promoted descendants.
     *
     * @return array<int,array>
     */
    private function flatten_subsection_tree(
        array $folder,
        array $path,
        string $leveloneref,
        int $depth,
        string $originalparentref
    ): array {
        $sourceid = (string) ($folder['source_id'] ?? '');
        $originaltitle = (string) ($folder['title'] ?? '');
        $children = is_array($folder['items'] ?? null) ? $folder['items'] : [];
        $directitems = [];
        $promoted = [];

        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }

            if (($child['type'] ?? '') !== 'folder') {
                $directitems[] = $child;
                continue;
            }

            $childpath = [...$path, $this->folder_label($child)];
            $childref = (string) ($child['source_id'] ?? '');
            foreach ($this->flatten_subsection_tree(
                $child,
                $childpath,
                $leveloneref,
                $depth + 1,
                $sourceid
            ) as $node) {
                $promoted[] = $node;
            }
        }

        $node = $folder;
        $node['items'] = $directitems;

        if ($depth > 2) {
            $moodletitle = implode(' / ', $path);
            $metadata = is_array($node['metadata'] ?? null) ? $node['metadata'] : [];
            $metadata['moodle_flattened'] = true;
            $metadata['moodle_original_depth'] = $depth;
            $metadata['moodle_original_title'] = $originaltitle;
            $metadata['moodle_original_parent_source_ref_id'] = $originalparentref;
            $metadata['moodle_level_one_source_ref_id'] = $leveloneref;
            $metadata['moodle_flatten_path'] = $path;
            $node['metadata'] = $metadata;
            $node['title'] = $moodletitle;

            $this->changes[] = [
                'source_ref_id' => $sourceid,
                'original_parent_source_ref_id' => $originalparentref,
                'moodle_parent_source_ref_id' => $leveloneref,
                'original_depth' => $depth,
                'original_title' => $originaltitle,
                'moodle_title' => $moodletitle,
                'path' => $path,
            ];
        }

        return array_merge([$node], $promoted);
    }

    /**
     * Produce a deterministic readable path segment even for an empty source title.
     */
    private function folder_label(array $folder): string {
        $title = trim((string) ($folder['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }

        $sourceid = trim((string) ($folder['source_id'] ?? ''));
        return $sourceid !== '' ? 'Dossier ' . $sourceid : 'Dossier';
    }
}
