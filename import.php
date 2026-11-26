<?php

// DATABASE CONNECTION
$host = 'localhost';
$db   = 'it30_lab_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

session_start();

// HELPER: Normalize a header cell
function normalizeHeader(string $header): string
{
    return strtolower(trim(str_replace([' ', '-'], '_', $header)));
}

// HELPER: Build a case-insensitive dedupe key for a student
function studentKey(string $first, string $last, string $course): string
{
    return mb_strtolower(trim($first) . '|' . trim($last) . '|' . trim($course));
}

// HELPER: Parse uploaded CSV into rows (header + data)
function parseCsv(string $path): array
{
    $rows = [];

    $handle = fopen($path, 'r');
    if ($handle === false) {
        return $rows;
    }

    // Strip UTF-8 BOM if present
    if (fread($handle, 3) !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    while (($row = fgetcsv($handle)) !== false) {
        // Skip fully blank lines
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }
        $rows[] = $row;
    }

    fclose($handle);
    return $rows;
}

// HELPER: Validate a parsed CSV against required columns.
function validateCsv(array $rows, array $required): array
{
    $result = [
        'ok'     => false,
        'header' => [],
        'col'    => [],
        'rows'   => [],
        'errors' => [],
    ];

    if (empty($rows)) {
        $result['errors'][] = 'The CSV file is empty.';
        return $result;
    }

    $header = array_map('normalizeHeader', $rows[0]);
    $result['header'] = $header;

    $missing = array_diff($required, $header);
    if (!empty($missing)) {
        $result['errors'][] = 'Missing column(s): ' . implode(', ', $missing);
        return $result;
    }

    $result['col']  = array_flip($header);
    $result['rows'] = array_slice($rows, 1);
    $result['ok']   = true;

    return $result;
}

// HELPER: Extract & validate a single student row
function extractStudent(array $row, array $col): array
{
    $firstName = trim($row[$col['student_first_name']] ?? '');
    $lastName  = trim($row[$col['student_last_name']] ?? '');
    $course    = trim($row[$col['student_course']] ?? '');

    $missing = [];
    if ($firstName === '') $missing[] = 'first name';
    if ($lastName  === '') $missing[] = 'last name';
    if ($course    === '') $missing[] = 'course';

    return [
        'valid'      => empty($missing),
        'errors'     => $missing,
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'course'     => $course,
    ];
}

// HELPER: Fetch existing student keys from the database
function fetchExistingStudentKeys(PDO $pdo): array
{
    $keys = [];

    $stmt = $pdo->query("
        SELECT student_first_name, student_last_name, student_course
        FROM students
    ");

    while ($row = $stmt->fetch()) {
        $keys[studentKey(
            $row['student_first_name'],
            $row['student_last_name'],
            $row['student_course']
        )] = true;
    }

    return $keys;
}

$required = ['student_first_name', 'student_last_name', 'student_course'];

// STAGE TRACKING
$stage   = 'upload';   // upload | preview | done
$preview = null;
$results = null;
$fatal   = null;

// ============================================================
// STEP 1 — UPLOAD: validate CSV, dedupe, build preview
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'upload') {

    $file = $_FILES['csv_file'] ?? null;

    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $fatal = 'File upload failed.';
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $fatal = 'Only .csv files are allowed.';
    } else {

        $rows = parseCsv($file['tmp_name']);
        $v    = validateCsv($rows, $required);

        if (!$v['ok']) {
            $fatal = implode(' ', $v['errors']);
        } else {

            // Load existing keys from DB once
            $existingKeys = fetchExistingStudentKeys($pdo);

            // Track keys seen within this file
            $seenInFile = [];

            $previewRows  = [];
            $validCount   = 0;
            $invalidCount = 0;
            $dupInFileCount = 0;
            $dupInDbCount   = 0;

            $line = 1;

            foreach ($v['rows'] as $row) {

                $line++;
                $s = extractStudent($row, $v['col']);

                // -------- Invalid row --------
                if (!$s['valid']) {
                    $invalidCount++;
                    $previewRows[] = [
                        'line'       => $line,
                        'status'     => 'invalid',
                        'note'       => 'Missing ' . implode(', ', $s['errors']),
                        'first_name' => $s['first_name'],
                        'last_name'  => $s['last_name'],
                        'course'     => $s['course'],
                    ];
                    continue;
                }

                $key = studentKey($s['first_name'], $s['last_name'], $s['course']);

                // -------- Duplicate within CSV --------
                if (isset($seenInFile[$key])) {
                    $dupInFileCount++;
                    $previewRows[] = [
                        'line'       => $line,
                        'status'     => 'duplicate_file',
                        'note'       => 'Duplicate within CSV (first seen on line ' . $seenInFile[$key] . ')',
                        'first_name' => $s['first_name'],
                        'last_name'  => $s['last_name'],
                        'course'     => $s['course'],
                    ];
                    continue;
                }

                // -------- Duplicate already in DB --------
                if (isset($existingKeys[$key])) {
                    $dupInDbCount++;
                    $previewRows[] = [
                        'line'       => $line,
                        'status'     => 'duplicate_db',
                        'note'       => 'Already exists in database',
                        'first_name' => $s['first_name'],
                        'last_name'  => $s['last_name'],
                        'course'     => $s['course'],
                    ];
                    continue;
                }

                // -------- Valid & new --------
                $seenInFile[$key] = $line;
                $validCount++;

                $previewRows[] = [
                    'line'       => $line,
                    'status'     => 'ok',
                    'note'       => '',
                    'first_name' => $s['first_name'],
                    'last_name'  => $s['last_name'],
                    'course'     => $s['course'],
                ];
            }

            // Stash in session for confirmation step
            $_SESSION['import_preview'] = $previewRows;

            $preview = [
                'filename'        => $file['name'],
                'rows'            => $previewRows,
                'valid_count'     => $validCount,
                'invalid_count'   => $invalidCount,
                'dup_file_count'  => $dupInFileCount,
                'dup_db_count'    => $dupInDbCount,
            ];

            $stage = 'preview';
        }
    }
}

// ============================================================
// STEP 2 — CONFIRM: insert only 'ok' rows from session
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'confirm') {

    $rows = $_SESSION['import_preview'] ?? null;

    if (!$rows) {
        $fatal = 'No preview data found. Please upload the CSV again.';
    } else {

        $results = [
            'success'         => 0,
            'failed'          => 0,
            'invalid'         => 0,
            'dup_file'        => 0,
            'dup_db'          => 0,
            'errors'          => [],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO students (
                student_first_name,
                student_last_name,
                student_course
            ) VALUES (?, ?, ?)
        ");

        // Re-fetch DB keys in case something changed between preview & confirm
        $existingKeys = fetchExistingStudentKeys($pdo);

        foreach ($rows as $row) {

            switch ($row['status']) {

                case 'invalid':
                    $results['invalid']++;
                    $results['errors'][] =
                        "Line {$row['line']}: Skipped — {$row['note']}.";
                    continue 2;

                case 'duplicate_file':
                    $results['dup_file']++;
                    $results['errors'][] =
                        "Line {$row['line']}: Skipped — {$row['note']}.";
                    continue 2;

                case 'duplicate_db':
                    $results['dup_db']++;
                    $results['errors'][] =
                        "Line {$row['line']}: Skipped — {$row['note']}.";
                    continue 2;
            }

            // Final safety check: is this key already in DB now?
            $key = studentKey($row['first_name'], $row['last_name'], $row['course']);

            if (isset($existingKeys[$key])) {
                $results['dup_db']++;
                $results['errors'][] =
                    "Line {$row['line']}: Skipped — already exists in database.";
                continue;
            }

            try {
                $stmt->execute([
                    $row['first_name'],
                    $row['last_name'],
                    $row['course'],
                ]);
                $existingKeys[$key] = true;
                $results['success']++;
            } catch (PDOException $e) {
                $results['failed']++;
                $results['errors'][] = "Line {$row['line']}: " . $e->getMessage();
            }
        }

        unset($_SESSION['import_preview']);
        $stage = 'done';
    }
}

// ============================================================
// STEP 3 — CANCEL
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'cancel') {
    unset($_SESSION['import_preview']);
    header('Location: import.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Students from CSV</title>

    <style>
        body { font-family: sans-serif; }
        table { border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #999; padding: 6px 10px; text-align: left; }
        th { background: #f0f0f0; }
        .row-ok   { background: #eaffea; }
        .row-skip { background: #ffe6e6; }
        .row-dup  { background: #fff8dc; }
        .badge { padding: 2px 6px; border-radius: 4px; font-size: 0.85em; }
        .badge-ok    { background: #c9f0c9; }
        .badge-bad   { background: #f7c9c9; }
        .badge-dup   { background: #f3e0a0; }
        .summary { margin: 12px 0; padding: 10px; border: 1px solid #ccc; background: #fafafa; }
        .summary ul { margin: 6px 0 0 16px; padding: 0; }
    </style>
</head>

<body>

<h1>Import Students from CSV</h1>

<nav>
    <a href="index.php?section=students">Students</a> |
    <strong>Import Students</strong>
</nav>

<hr>

<?php if ($fatal !== null): ?>

    <h3>Error</h3>
    <p style="color:#b00;"><?= htmlspecialchars($fatal) ?></p>
    <p><a href="import.php">Try again</a></p>

<?php endif; ?>


<?php if ($stage === 'upload' && $fatal === null): ?>

    <h3>Upload Students CSV</h3>

    <p>
        The CSV must include a header row with the columns:
        <code>student_first_name, student_last_name, student_course</code>
    </p>

    <p>
        Rows matching an existing student (same first name, last name, and course)
        will be <strong>skipped automatically</strong>.
    </p>

    <p><strong>Example:</strong></p>

    <pre>student_first_name,student_last_name,student_course
CLIFF AMADEUS,EVANGELIO,BSIT
JAN XAVIER,EVANGELIO,BSA-AGRONOMY
RON EDMUND,EVANGELIO,BSEE</pre>

    <form method="POST" enctype="multipart/form-data">

        <input type="hidden" name="step" value="upload">

        <p>
            <label>CSV File:</label><br>
            <input type="file" name="csv_file" accept=".csv" required>
        </p>

        <button type="submit">Preview</button>

    </form>

<?php endif; ?>


<?php if ($stage === 'preview' && $preview !== null): ?>

    <h3>Preview: <?= htmlspecialchars($preview['filename']) ?></h3>

    <div class="summary">
        <strong>Total rows:</strong> <?= count($preview['rows']) ?>

        <ul>
            <li>To be imported: <strong><?= (int) $preview['valid_count'] ?></strong></li>
            <li>Invalid (missing fields): <strong><?= (int) $preview['invalid_count'] ?></strong></li>
            <li>Duplicate within CSV: <strong><?= (int) $preview['dup_file_count'] ?></strong></li>
            <li>Already in database: <strong><?= (int) $preview['dup_db_count'] ?></strong></li>
        </ul>
    </div>

    <?php if ($preview['valid_count'] === 0): ?>

        <p style="color:#b00;">No new rows to import.</p>

    <?php else: ?>

        <p>
            Review the rows below. Only rows marked <strong>OK</strong> will be inserted;
            invalid and duplicate rows are skipped.
        </p>

        <table>

            <thead>
                <tr>
                    <th>Line</th>
                    <th>Status</th>
                    <th>First Name</th>
                    <th>Last Name</th>
                    <th>Course</th>
                    <th>Notes</th>
                </tr>
            </thead>

            <tbody>

                <?php foreach ($preview['rows'] as $row): ?>

                    <?php
                        $rowClass = match ($row['status']) {
                            'ok'             => 'row-ok',
                            'invalid'        => 'row-skip',
                            'duplicate_file' => 'row-dup',
                            'duplicate_db'   => 'row-dup',
                            default          => '',
                        };

                        $badgeClass = match ($row['status']) {
                            'ok'             => 'badge-ok',
                            'invalid'        => 'badge-bad',
                            'duplicate_file' => 'badge-dup',
                            'duplicate_db'   => 'badge-dup',
                            default          => '',
                        };

                        $badgeLabel = match ($row['status']) {
                            'ok'             => 'OK',
                            'invalid'        => 'SKIP',
                            'duplicate_file' => 'DUPLICATE',
                            'duplicate_db'   => 'EXISTS',
                            default          => '?',
                        };
                    ?>

                    <tr class="<?= $rowClass ?>">

                        <td><?= (int) $row['line'] ?></td>

                        <td>
                            <span class="badge <?= $badgeClass ?>">
                                <?= $badgeLabel ?>
                            </span>
                        </td>

                        <td><?= htmlspecialchars($row['first_name']) ?></td>
                        <td><?= htmlspecialchars($row['last_name']) ?></td>
                        <td><?= htmlspecialchars($row['course']) ?></td>

                        <td><?= htmlspecialchars($row['note']) ?></td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    <?php endif; ?>

    <hr>

    <form method="POST" style="display:inline;">

        <input type="hidden" name="step" value="confirm">

        <button type="submit"
            <?= $preview['valid_count'] === 0 ? 'disabled' : '' ?>
            onclick="return confirm('Import <?= (int) $preview['valid_count'] ?> new student(s)? Duplicates and invalid rows will be skipped.');"
        >
            Confirm Import
        </button>

    </form>

    <form method="POST" style="display:inline;">

        <input type="hidden" name="step" value="cancel">

        <button type="submit">Cancel</button>

    </form>

<?php endif; ?>


<?php if ($stage === 'done' && $results !== null): ?>

    <h3>Import Results</h3>

    <ul>
        <li><strong>Inserted:</strong> <?= (int) $results['success'] ?></li>
        <li><strong>Duplicates in CSV (skipped):</strong> <?= (int) $results['dup_file'] ?></li>
        <li><strong>Already in DB (skipped):</strong> <?= (int) $results['dup_db'] ?></li>
        <li><strong>Invalid rows (skipped):</strong> <?= (int) $results['invalid'] ?></li>
        <li><strong>Insert errors:</strong> <?= (int) $results['failed'] ?></li>
    </ul>

    <?php if (!empty($results['errors'])): ?>
        <h4>Details</h4>
        <ul>
            <?php foreach ($results['errors'] as $error): ?>
                <li><?= htmlspecialchars($error) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <p>
        <a href="import.php">Import another file</a> |
        <a href="index.php?section=students">Back to Students</a>
    </p>

<?php endif; ?>

</body>
</html>