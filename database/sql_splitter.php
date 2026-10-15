<?php
/**
 * Découpe les scripts MySQL du projet en instructions, en prenant en charge
 * les blocs de déclencheurs encadrés par DELIMITER.
 */
function split_sql_statements(string $sql): array {
    $delimiter = ';';
    $buffer = '';
    $statements = [];

    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            $delimiter = $match[1];
            continue;
        }

        $buffer .= $line . "\n";
        $trimmed = rtrim($buffer);
        if ($trimmed === '' || !str_ends_with($trimmed, $delimiter)) {
            continue;
        }

        $statement = substr($trimmed, 0, -strlen($delimiter));
        $statement = preg_replace('/^\s*--.*$/m', '', $statement);
        $statement = trim($statement);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        $buffer = '';
    }

    $remainder = trim((string)preg_replace('/^\s*--.*$/m', '', $buffer));
    if ($remainder !== '') {
        throw new RuntimeException('Le script SQL contient une instruction incomplète.');
    }
    return $statements;
}
