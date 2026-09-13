<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves or creates a deterministic Moodle course-category path.
 *
 * Existing categories are selected only when exactly one category with the
 * requested name exists under the expected parent. Ambiguous sibling names
 * block the migration instead of guessing.
 */
final class category_path_resolver {
    /**
     * Parse a category path. The preferred separator is >; / is accepted for convenience.
     *
     * @param string $path Human-readable category path.
     * @return string[] Normalized category names.
     */
    public static function parse_path(string $path): array {
        $path = trim($path);
        if ($path === '') {
            throw new \InvalidArgumentException('Category path must not be empty.');
        }

        $separator = str_contains($path, '>') ? '>' : '/';
        $segments = array_map('trim', explode($separator, $path));

        foreach ($segments as $segment) {
            if ($segment === '') {
                throw new \InvalidArgumentException('Category path contains an empty segment.');
            }
        }

        return array_values($segments);
    }

    /**
     * Build a read-only category resolution plan.
     *
     * @param string $path Requested category path.
     * @return array Resolution plan.
     */
    public function plan(string $path): array {
        global $DB;

        $segments = self::parse_path($path);
        $operations = [];
        $blockers = [];
        $parentid = 0;
        $parentpath = '';
        $missingancestor = false;
        $finalvisible = 0;

        foreach ($segments as $index => $name) {
            $normalizedpath = $parentpath === '' ? $name : $parentpath . ' / ' . $name;

            if ($missingancestor) {
                $operations[] = [
                    'kind' => 'category',
                    'action' => 'CREATE',
                    'name' => $name,
                    'target_id' => null,
                    'parent_target_id' => null,
                    'parent_path' => $parentpath,
                    'path' => $normalizedpath,
                    'visible' => 0,
                ];
                $parentpath = $normalizedpath;
                continue;
            }

            $matches = $DB->get_records(
                'course_categories',
                ['parent' => $parentid, 'name' => $name],
                'id ASC',
                'id,name,parent,visible'
            );

            if (count($matches) > 1) {
                $blockers[] = [
                    'code' => 'CATEGORY_PATH_AMBIGUOUS',
                    'segment' => $name,
                    'path' => $normalizedpath,
                    'parent_target_id' => $parentid,
                    'matching_ids' => array_map('intval', array_keys($matches)),
                    'message' => 'More than one Moodle category with this name exists under the expected parent.',
                ];
                break;
            }

            if ($matches) {
                $category = reset($matches);
                $parentid = (int) $category->id;
                $finalvisible = (int) $category->visible;
                $operations[] = [
                    'kind' => 'category',
                    'action' => 'SELECT',
                    'name' => $name,
                    'target_id' => $parentid,
                    'parent_target_id' => (int) $category->parent,
                    'path' => $normalizedpath,
                    'visible' => $finalvisible,
                ];
            } else {
                $missingancestor = true;
                $finalvisible = 0;
                $operations[] = [
                    'kind' => 'category',
                    'action' => 'CREATE',
                    'name' => $name,
                    'target_id' => null,
                    'parent_target_id' => $parentid,
                    'parent_path' => $parentpath,
                    'path' => $normalizedpath,
                    'visible' => 0,
                ];
            }

            $parentpath = $normalizedpath;
        }

        return [
            'strategy' => 'unique_name_under_parent',
            'path' => implode(' / ', $segments),
            'ready' => !$blockers,
            'requires_creation' => $missingancestor,
            'target_id' => (!$missingancestor && !$blockers) ? $parentid : null,
            'target_name' => end($segments),
            'target_visible' => $finalvisible,
            'operations' => $operations,
            'blockers' => $blockers,
            'creation_visibility' => 0,
        ];
    }

    /**
     * Resolve the requested path and create missing categories through Moodle core APIs.
     *
     * New categories are deliberately hidden. Existing categories are never renamed,
     * moved or hidden by this resolver.
     *
     * @param string $path Requested category path.
     * @return array Execution report including the final target id.
     */
    public function apply(string $path): array {
        global $DB, $USER;

        $segments = self::parse_path($path);
        $operations = [];
        $parentid = 0;
        $parentpath = '';
        $originaluser = $USER;

        \core\session\manager::set_user(get_admin());

        try {
            foreach ($segments as $name) {
                $normalizedpath = $parentpath === '' ? $name : $parentpath . ' / ' . $name;
                $matches = $DB->get_records(
                    'course_categories',
                    ['parent' => $parentid, 'name' => $name],
                    'id ASC',
                    'id,name,parent,visible'
                );

                if (count($matches) > 1) {
                    throw new \coding_exception(
                        'Category path is ambiguous at "' . $normalizedpath . '". '
                        . 'Use --category=ID or remove the duplicate sibling category names.'
                    );
                }

                if ($matches) {
                    $category = reset($matches);
                    $action = 'SELECTED';
                } else {
                    $category = \core_course_category::create((object) [
                        'name' => $name,
                        'parent' => $parentid,
                        'visible' => 0,
                    ]);
                    $action = 'CREATED';
                }

                $parentid = (int) $category->id;
                $operations[] = [
                    'kind' => 'category',
                    'action' => $action,
                    'name' => $name,
                    'target_id' => $parentid,
                    'parent_target_id' => (int) $category->parent,
                    'path' => $normalizedpath,
                    'visible' => (int) $category->visible,
                ];
                $parentpath = $normalizedpath;
            }
        } finally {
            if ($originaluser instanceof \stdClass) {
                \core\session\manager::set_user($originaluser);
            }
        }

        $final = end($operations);

        return [
            'strategy' => 'unique_name_under_parent',
            'path' => implode(' / ', $segments),
            'ready' => true,
            'requires_creation' => false,
            'target_id' => (int) ($final['target_id'] ?? 0),
            'target_name' => (string) ($final['name'] ?? ''),
            'target_visible' => (int) ($final['visible'] ?? 0),
            'operations' => $operations,
            'blockers' => [],
            'creation_visibility' => 0,
        ];
    }
}
