<?php

declare(strict_types=1);

const INFO_QUERY = <<<SQL
SELECT TABLE_NAME, COLUMN_NAME, IF(DATA_TYPE = 'enum', COLUMN_TYPE, DATA_TYPE), IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = :schema
ORDER BY TABLE_NAME
SQL;


$separator_index = array_search('--', $argv, true);

if (!$separator_index || $separator_index < 2 || $separator_index === count($argv)) {
    fprintf(STDERR, <<<USAGE
Dumps MySQL database structure

Usage: %s dsn [username [password]] -- [database1 database2 ... ]
    
USAGE, basename($argv[0]));
    exit(1);
}

$dsn = $argv[1];
$username = null;
$password = null;

if ($separator_index > 2) {
    $username = $argv[2];
}

if ($separator_index > 3) {
    $password = $argv[3];
}

$databases = array_slice($argv, $separator_index + 1);

$pdo = new PDO($dsn, $username, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$stmt = $pdo->prepare(INFO_QUERY);

$writer = new XMLWriter();
$writer->openUri('php://output');
$writer->setIndent(true);

$writer->startDocument('1.0', 'utf-8', 'yes');
$writer->startElement('databases');

foreach ($databases as $database) {
    $stmt->execute(['schema' => $database]);
    $writer->startElement('database');
    $writer->writeAttribute('name', $database);
    $oldTable = null;

    while (/** @var array{0: string, 1: string, 2: string, 3: string} */
        $row = $stmt->fetch(PDO::FETCH_NUM)
    ) {
        [$table, $column, $type, $nullable] = $row;

        if ($oldTable !== $table) {
            if ($oldTable !== null) {
                $writer->endElement();
            }

            $oldTable = $table;
            $writer->startElement('table');
            $writer->writeAttribute('name', $table);
        }

        $writer->startElement('column');
        $writer->writeAttribute('name', $column);
        $writer->writeAttribute('type', $type);
        $writer->writeAttribute('nullable', $nullable);
        $writer->endElement();
    }

    if ($oldTable !== null) {
        $writer->endElement();
    }

    $writer->endElement();
}

$writer->endElement();
$writer->endDocument();
