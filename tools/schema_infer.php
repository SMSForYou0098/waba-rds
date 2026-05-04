<?php
/**
 * Best-effort schema inference from code + migrations.
 *
 * Goals:
 * - List referenced columns per table from DB::table(...) query builder usage.
 * - List declared columns per table from database/migrations.
 * - Output a diff to help backfill migrations (esp. when legacy columns were created manually in DB).
 *
 * Notes:
 * - This is regex-based and intentionally conservative; it may miss dynamic column names.
 * - Treat output as a starting point, not an absolute truth.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/..';

function iterPhpFiles(string $dir): Generator
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $f */
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        if (!str_ends_with($f->getFilename(), '.php')) {
            continue;
        }
        yield $f->getPathname();
    }
}

function addCol(array &$map, string $table, string $col): void
{
    $table = strtolower($table);
    $col = trim($col);
    if ($col === '' || $col === '*' || str_contains($col, ' ')) {
        return;
    }
    $map[$table][$col] = true;
}

function normalizeCol(string $col): string
{
    // strip simple aliasing: "foo as bar" -> foo
    $col = preg_replace('/\s+as\s+.*/i', '', $col) ?? $col;
    // strip raw quoting/backticks/double quotes
    $col = trim($col, " \t\n\r\0\x0B`\"'");
    // handle qualified references like "users.email" or "u.id"
    if (str_contains($col, '.')) {
        $parts = explode('.', $col);
        $col = end($parts) ?: $col;
    }
    return $col;
}

function parseDbTableUsage(string $code, array &$referenced): void
{
    // Capture chained statements that start with DB::table('x') or \DB::table('x')
    if (!preg_match_all("/(?:\\\\?DB)::table\\(\\s*'([^']+)'\\s*\\)([\\s\\S]*?);/m", $code, $m, PREG_SET_ORDER)) {
        return;
    }

    foreach ($m as $stmt) {
        $table = $stmt[1];
        $chunk = $stmt[2];

        // select([...]) columns
        if (preg_match_all("/->select\\(\\s*\\[([\\s\\S]*?)\\]\\s*\\)/m", $chunk, $sm, PREG_SET_ORDER)) {
            foreach ($sm as $sel) {
                if (preg_match_all("/'([^']+)'/", $sel[1], $cols)) {
                    foreach ($cols[1] as $c) {
                        addCol($referenced, $table, normalizeCol($c));
                    }
                }
            }
        }
        if (preg_match_all("/->select\\(\\s*'([^']+)'/m", $chunk, $sm2)) {
            foreach ($sm2[1] as $c) {
                addCol($referenced, $table, normalizeCol($c));
            }
        }

        // where/orderBy/groupBy
        foreach (['where', 'orWhere', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull', 'orderBy', 'groupBy', 'having'] as $fn) {
            if (preg_match_all("/->{$fn}\\(\\s*'([^']+)'/m", $chunk, $wm)) {
                foreach ($wm[1] as $c) {
                    addCol($referenced, $table, normalizeCol($c));
                }
            }
        }

        // insert/update array keys
        foreach (['insert', 'update', 'updateOrInsert'] as $fn) {
            if (preg_match_all("/->{$fn}\\(\\s*\\[([\\s\\S]*?)\\]\\s*\\)/m", $chunk, $im, PREG_SET_ORDER)) {
                foreach ($im as $arr) {
                    if (preg_match_all("/'([^']+)'\\s*=>/m", $arr[1], $keys)) {
                        foreach ($keys[1] as $k) {
                            addCol($referenced, $table, normalizeCol($k));
                        }
                    }
                }
            }
        }

        // join('table2 as t2', 'a.col', '=', 'b.col')
        if (preg_match_all("/->join\\(\\s*'([^']+)'\\s*,\\s*'([^']+)'\\s*,\\s*'[^']+'\\s*,\\s*'([^']+)'\\s*\\)/m", $chunk, $jm, PREG_SET_ORDER)) {
            foreach ($jm as $j) {
                $joinTable = preg_replace('/\\s+as\\s+.*/i', '', $j[1]) ?? $j[1];
                $left = $j[2];
                $right = $j[3];

                // a.b -> column b, but table mapping is ambiguous; still capture the column name on the base table and join table if possible
                if (str_contains($left, '.')) {
                    [, $col] = explode('.', $left, 2);
                    addCol($referenced, $table, normalizeCol($col));
                } else {
                    addCol($referenced, $table, normalizeCol($left));
                }

                if (str_contains($right, '.')) {
                    [, $col] = explode('.', $right, 2);
                    addCol($referenced, $joinTable, normalizeCol($col));
                } else {
                    addCol($referenced, $joinTable, normalizeCol($right));
                }
            }
        }
    }
}

function parseMigrationColumns(string $code, array &$declared): void
{
    // Schema::create('table', function(Blueprint $table) { ... })
    if (preg_match_all('/Schema::create\\(\\s*\'([^\']+)\'\\s*,\\s*function\\s*\\(\\s*Blueprint\\s*\\$table\\s*\\)\\s*\\{([\\s\\S]*?)\\}\\s*\\);/m', $code, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
            $table = $c[1];
            $body = $c[2];
            // implicit columns (id/timestamps/etc.)
            if (preg_match('/\\$table->id\\s*\\(/m', $body) || preg_match('/\\$table->bigIncrements\\s*\\(/m', $body) || preg_match('/\\$table->increments\\s*\\(/m', $body)) {
                addCol($declared, $table, 'id');
            }
            if (preg_match('/\\$table->timestamps(Tz)?\\s*\\(/m', $body) || preg_match('/\\$table->timestamps\\s*\\(\\s*\\)/m', $body) || preg_match('/\\$table->timestamps\\s*;?/m', $body)) {
                addCol($declared, $table, 'created_at');
                addCol($declared, $table, 'updated_at');
            }
            if (preg_match('/\\$table->softDeletes(Tz)?\\s*\\(/m', $body)) {
                addCol($declared, $table, 'deleted_at');
            }
            if (preg_match('/\\$table->rememberToken\\s*\\(/m', $body) || preg_match('/\\$table->rememberToken\\s*\\(\\s*\\)/m', $body)) {
                addCol($declared, $table, 'remember_token');
            }

            if (preg_match_all('/\\$table->(?:[a-zA-Z_]+)\\(\\s*\'([^\']+)\'/m', $body, $cols)) {
                foreach ($cols[1] as $col) {
                    addCol($declared, $table, normalizeCol($col));
                }
            }
        }
    }

    // Schema::table('table', function(Blueprint $table) { ... })
    if (preg_match_all('/Schema::table\\(\\s*\'([^\']+)\'\\s*,\\s*function\\s*\\(\\s*Blueprint\\s*\\$table\\s*\\)\\s*\\{([\\s\\S]*?)\\}\\s*\\);/m', $code, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $t) {
            $table = $t[1];
            $body = $t[2];
            if (preg_match('/\\$table->timestamps(Tz)?\\s*\\(/m', $body) || preg_match('/\\$table->timestamps\\s*\\(\\s*\\)/m', $body) || preg_match('/\\$table->timestamps\\s*;?/m', $body)) {
                addCol($declared, $table, 'created_at');
                addCol($declared, $table, 'updated_at');
            }
            if (preg_match('/\\$table->softDeletes(Tz)?\\s*\\(/m', $body)) {
                addCol($declared, $table, 'deleted_at');
            }

            if (preg_match_all('/\\$table->(?:[a-zA-Z_]+)\\(\\s*\'([^\']+)\'/m', $body, $cols)) {
                foreach ($cols[1] as $col) {
                    addCol($declared, $table, normalizeCol($col));
                }
            }
        }
    }
}

function sortMap(array $map): array
{
    ksort($map);
    foreach ($map as $t => $cols) {
        $keys = array_keys($cols);
        sort($keys);
        $map[$t] = $keys;
    }
    return $map;
}

$referenced = [];
$declared = [];

// 1) referenced columns from app code
foreach (iterPhpFiles(ROOT . '/app') as $path) {
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    parseDbTableUsage($code, $referenced);
}

// 2) declared columns from migrations
foreach (iterPhpFiles(ROOT . '/database/migrations') as $path) {
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    parseMigrationColumns($code, $declared);
}

$referencedSorted = sortMap($referenced);
$declaredSorted = sortMap($declared);

// 3) diff
$missing = [];
foreach ($referencedSorted as $table => $cols) {
    $decl = $declaredSorted[$table] ?? [];
    $declSet = array_fill_keys($decl, true);
    foreach ($cols as $c) {
        if (!isset($declSet[$c])) {
            $missing[$table][] = $c;
        }
    }
    if (isset($missing[$table])) {
        sort($missing[$table]);
    }
}
ksort($missing);

$outDir = ROOT . '/storage/schema_infer';
@mkdir($outDir, 0777, true);

file_put_contents($outDir . '/referenced_columns.json', json_encode($referencedSorted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/declared_columns.json', json_encode($declaredSorted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($outDir . '/missing_columns.json', json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "Wrote:\n";
echo "- storage/schema_infer/referenced_columns.json\n";
echo "- storage/schema_infer/declared_columns.json\n";
echo "- storage/schema_infer/missing_columns.json\n";

