<?php

namespace local_iliasmigration;

defined('MOODLE_INTERNAL') || die();

/**
 * Extend the read-only Glossary package validation with deterministic
 * per-entry CREATE/UPDATE planning once the real executor is available.
 */
final class phase65_glossary_apply_validator {
    /** @var string Absolute migration.json path. */
    private string $migrationjson;

    public function __construct(string $migrationjson) {
        $file = realpath($migrationjson);
        if ($file === false || !is_file($file)) {
            throw new \coding_exception('Unable to resolve migration.json.');
        }
        $this->migrationjson = $file;
    }

    /**
     * Validate the package, then resolve each Glossary entry mapping.
     */
    public function validate(array $plan): array {
        global $DB;

        $plan = (new phase65_glossary_package_validator($this->migrationjson))->validate($plan);

        $sourceinstance = (string) ($plan['source']['instance'] ?? '');
        $sourcecourse = (string) ($plan['course']['source_id'] ?? '');
        $entryblocked = 0;
        $entrycreates = 0;
        $entryupdates = 0;

        foreach ($plan['operations'] as &$operation) {
            if ((string) ($operation['kind'] ?? '') !== 'glossary') {
                continue;
            }
            if ((string) ($operation['action'] ?? '') === 'BLOCKED') {
                continue;
            }

            $glossaryref = (string) ($operation['source_ref_id'] ?? '');
            $glossaryaction = (string) ($operation['action'] ?? '');
            $glossaryinstance = 0;

            if ($glossaryaction === 'UPDATE') {
                $cmid = (int) ($operation['target_id'] ?? 0);
                $cm = $DB->get_record_sql(
                    'SELECT cm.id, cm.instance, cm.course, m.name AS modulename
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = ?',
                    [$cmid]
                );
                if (!$cm || (string) $cm->modulename !== 'glossary') {
                    $this->block(
                        $operation,
                        'GLOSSARY_MAPPING_INVALID',
                        'The mapped Moodle Glossary course module is missing or invalid.'
                    );
                    $entryblocked++;
                    continue;
                }
                $glossaryinstance = (int) $cm->instance;
            }

            $validation = is_array($operation['glossary_validation'] ?? null)
                ? $operation['glossary_validation']
                : [];
            $terms = is_array($validation['terms'] ?? null) ? $validation['terms'] : [];

            foreach ($terms as &$term) {
                if (!is_array($term)) {
                    continue;
                }
                $termid = (string) ($term['source_id'] ?? '');
                $entryref = $this->entry_ref($glossaryref, $termid);
                $mapping = $this->find_entry_mapping(
                    $sourceinstance,
                    $sourcecourse,
                    $entryref
                );

                if (!$mapping) {
                    $term['action'] = 'CREATE';
                    $term['target_entry_id'] = null;
                    $term['mapping_ref'] = $entryref;
                    $entrycreates++;
                    continue;
                }

                if ($glossaryaction !== 'UPDATE') {
                    $this->block(
                        $operation,
                        'GLOSSARY_ENTRY_ORPHAN_MAPPING',
                        'A Glossary entry mapping exists while the parent Glossary itself is planned as CREATE.'
                    );
                    $entryblocked++;
                    break;
                }

                $entryid = (int) ($mapping->targetid ?? 0);
                $entry = $DB->get_record('glossary_entries', ['id' => $entryid], 'id,glossaryid');
                if (!$entry || (int) $entry->glossaryid !== $glossaryinstance) {
                    $this->block(
                        $operation,
                        'GLOSSARY_ENTRY_MAPPING_INVALID',
                        'A mapped Moodle Glossary entry is missing or belongs to another Glossary.'
                    );
                    $entryblocked++;
                    break;
                }

                $term['action'] = 'UPDATE';
                $term['target_entry_id'] = $entryid;
                $term['mapping_ref'] = $entryref;
                $entryupdates++;
            }
            unset($term);

            if (($operation['action'] ?? '') !== 'BLOCKED') {
                $operation['glossary_validation']['terms'] = $terms;
            }
        }
        unset($operation);

        $ready = !empty($plan['phase65_glossary_package']['ready']) && $entryblocked === 0;
        $plan['phase65_glossary_package']['entry_create_count'] = $entrycreates;
        $plan['phase65_glossary_package']['entry_update_count'] = $entryupdates;
        $plan['phase65_glossary_package']['entry_blocked_count'] = $entryblocked;
        $plan['phase65_glossary_package']['apply_implemented'] = true;
        $plan['phase65_glossary_package']['apply_ready'] = $ready;
        if ($entryblocked > 0) {
            $plan['phase65_glossary_package']['ready'] = false;
        }

        $plan['warnings'] = array_values(array_filter(
            is_array($plan['warnings'] ?? null) ? $plan['warnings'] : [],
            static fn($warning): bool => !is_array($warning)
                || (string) ($warning['code'] ?? '') !== 'GLOSSARY_APPLY_NOT_IMPLEMENTED'
        ));

        return $plan;
    }

    private function entry_ref(string $glossaryref, string $termid): string {
        return $glossaryref . ':term:' . $termid;
    }

    private function find_entry_mapping(
        string $sourceinstance,
        string $sourcecourse,
        string $entryref
    ): \stdClass|false {
        global $DB;

        $conditions = [
            'sourcelms' => 'ILIAS',
            'sourceinstance' => $sourceinstance,
            'sourcecourse' => $sourcecourse,
            'sourceref' => $entryref,
            'targettype' => 'glossary_entry',
        ];
        $mapping = $DB->get_record('local_iliasmigration_map', $conditions);
        if ($mapping) {
            return $mapping;
        }
        if ($sourceinstance === '') {
            return false;
        }
        $conditions['sourceinstance'] = '';
        return $DB->get_record('local_iliasmigration_map', $conditions);
    }

    private function block(array &$operation, string $code, string $message): void {
        $operation['action'] = 'BLOCKED';
        $operation['reason'] = $code;
        $operation['glossary_validation']['status'] = 'BLOCKED';
        $operation['glossary_validation']['code'] = $code;
        $operation['glossary_validation']['message'] = $message;
    }
}
