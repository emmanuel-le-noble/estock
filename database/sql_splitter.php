<?php
/**
 * database/sql_splitter.php — Découpe un script SQL en instructions
 * individuelles, en respectant :
 *   - les chaînes simples '…' (échappées ''), doubles "…" et identifiants `…`
 *   - les commentaires --, # et /* … *​/
 *   - les blocs BEGIN … END des triggers (profondeur de parenthèses ne
 *     s'applique pas : BEGIN/END se ferment par mot-clé END)
 *
 * Retourne un tableau d'instructions non vides, commentaires et espaces
 * superflus retirés.
 */
function split_sql_statements(string $sql): array {
    $statements = [];
    $current    = '';
    $quote      = null;      // caractère de chaîne courant : ', ", `
    $comment    = null;      // 'line' ou 'block'
    $begin_depth = 0;        // profondeur BEGIN…END
    $block_probe = false;    // true si on attend le début d'un bloc après CREATE TRIGGER

    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        // --- Commentaires ---
        if ($comment === 'line') {
            if ($c === "\n") { $comment = null; $current .= $c; }
            continue;
        }
        if ($comment === 'block') {
            if ($c === '*' && $next === '/') { $comment = null; $i++; }
            continue;
        }
        if ($quote === null && $c === '-' && $next === '-') { $comment = 'line'; $i++; continue; }
        if ($quote === null && $c === '#') { $comment = 'line'; continue; }
        if ($quote === null && $c === '/' && $next === '*') { $comment = 'block'; $i++; continue; }

        // --- Chaînes ---
        if ($quote !== null) {
            $current .= $c;
            // Échappement backslash (mode MySQL par défaut) : la paire \x
            // n'ouvre ni ne ferme jamais la chaîne.
            if ($c === '\\') {
                $current .= $next;
                $i++;
                continue;
            }
            if ($c === $quote) {
                if ($next === $quote) { $current .= $next; $i++; }
                else { $quote = null; }
            }
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $quote = $c; $current .= $c; continue; }

        // --- Mots-clés de bloc (hors chaînes) ---
        // Nous suivons seulement la profondeur BEGIN/END pour éviter de
        // couper les triggers : aucun besoin d'analyse lexicale complète.
        if (preg_match('/^BEGIN\b/i', substr($sql, $i))) {
            $begin_depth++;
        } elseif (preg_match('/^END\s*(IF|WHILE|LOOP|REPEAT|CASE)\b/i', substr($sql, $i))) {
            // END IF / END WHILE / ... : ne ferme pas le bloc BEGIN…END
        } elseif (preg_match('/^END\b/i', substr($sql, $i))) {
            if ($begin_depth > 0) $begin_depth--;
        }

        // --- Fin d'instruction ---
        if ($c === ';' && $begin_depth === 0) {
            $stmt = trim($current);
            if ($stmt !== '') $statements[] = $stmt;
            $current = '';
            continue;
        }
        $current .= $c;
    }
    $last = trim($current);
    if ($last !== '') $statements[] = $last;
    return $statements;
}
