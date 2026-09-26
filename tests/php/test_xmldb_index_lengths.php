<?php

$path = __DIR__ . '/../../moodle/local_iliasmigration/db/install.xml';
$xml = simplexml_load_file($path);

if ($xml === false) {
    fwrite(STDERR, "Unable to parse install.xml\n");
    exit(1);
}

$limit = 333;

foreach ($xml->TABLES->TABLE as $table) {
    $lengths = [];
    foreach ($table->FIELDS->FIELD as $field) {
        $name = (string) $field['NAME'];
        $type = strtolower((string) $field['TYPE']);
        $lengths[$name] = $type === 'char' ? (int) $field['LENGTH'] : 0;
    }

    $entries = [];
    foreach ($table->KEYS->KEY as $key) {
        if (strtolower((string) $key['TYPE']) === 'unique') {
            $entries[] = [
                'kind' => 'unique key',
                'name' => (string) $key['NAME'],
                'fields' => (string) $key['FIELDS'],
            ];
        }
    }
    foreach ($table->INDEXES->INDEX as $index) {
        $entries[] = [
            'kind' => 'index',
            'name' => (string) $index['NAME'],
            'fields' => (string) $index['FIELDS'],
        ];
    }

    foreach ($entries as $entry) {
        $total = 0;
        foreach (array_map('trim', explode(',', $entry['fields'])) as $fieldname) {
            $total += $lengths[$fieldname] ?? 0;
        }

        if ($total > $limit) {
            fwrite(
                STDERR,
                sprintf(
                    "%s %s on table %s declares %d indexed CHAR characters; limit is %d.\n",
                    $entry['kind'],
                    $entry['name'],
                    (string) $table['NAME'],
                    $total,
                    $limit
                )
            );
            exit(1);
        }
    }
}

echo "XMLDB indexed CHAR length regression: OK\n";
