<?php
/**
 * db_functions.php - Fonctions centralisées d'accès aux données.
 *
 * Toutes les requêtes SQL du projet sont regroupées ici.
 * Chaque fonction reçoit $pdo en premier paramètre.
 * Toutes les requêtes utilisent des statements préparés (protection injection SQL).
 */

// ============================================================
//  ARTICLES
// ============================================================

/**
 * Vérifier l'unicité du code-barres (exclure un ID optionnel).
 */
function db_article_code_barre_exists(PDO $pdo, string $code_barre, int $exclude_id = 0): bool {
    $stmt = $pdo->prepare("SELECT id FROM articles WHERE code_barre = ? AND id <> ? LIMIT 1");
    $stmt->execute([$code_barre, $exclude_id]);
    return (bool)$stmt->fetch();
}

/**
 * Insérer un article. Retourne le nouvel ID.
 */
function db_article_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO articles (code_barre, nom, sku, prix_achat, prix_vente, quantite_stock, seuil_alerte, emplacement, fournisseur_id, categorie_id, taux_tva)
        VALUES (:code, :nom, :sku, :pa, :pv, :qte, :seuil, :emp, :four, :cat, :tva)
    ");
    $stmt->execute([
        ':code' => $data['code_barre'],
        ':nom'  => $data['nom'],
        ':sku'  => $data['sku'] ?? null,
        ':pa'   => $data['prix_achat'],
        ':pv'   => $data['prix_vente'],
        ':qte'  => $data['quantite_stock'],
        ':seuil'=> $data['seuil_alerte'],
        ':emp'  => $data['emplacement'] ?? null,
        ':four' => $data['fournisseur_id'] ?? null,
        ':cat'  => !empty($data['categorie_id']) ? (int)$data['categorie_id'] : null,
        ':tva'  => isset($data['taux_tva']) && $data['taux_tva'] !== null ? (float)$data['taux_tva'] : null,
    ]);
    $new_id = (int)$pdo->lastInsertId();

    // Initialiser le stock dans TOUS les magasins actifs
    db_stock_magasin_init_for_article($pdo, $new_id, (int)$data['quantite_stock'], (int)$data['seuil_alerte']);

    return $new_id;
}

/**
 * Mettre à jour un article existant.
 */
function db_article_update(PDO $pdo, int $id, array $data): void {
    $stmt = $pdo->prepare("
        UPDATE articles SET
            code_barre=:code, nom=:nom, sku=:sku,
            prix_achat=:pa, prix_vente=:pv,
            quantite_stock=:qte, seuil_alerte=:seuil,
            emplacement=:emp, fournisseur_id=:four,
            categorie_id=:cat, taux_tva=:tva
        WHERE id=:id
    ");
    $stmt->execute([
        ':code' => $data['code_barre'],
        ':nom'  => $data['nom'],
        ':sku'  => $data['sku'] ?? null,
        ':pa'   => $data['prix_achat'],
        ':pv'   => $data['prix_vente'],
        ':qte'  => $data['quantite_stock'],
        ':seuil'=> $data['seuil_alerte'],
        ':emp'  => $data['emplacement'] ?? null,
        ':four' => $data['fournisseur_id'] ?? null,
        ':cat'  => !empty($data['categorie_id']) ? (int)$data['categorie_id'] : null,
        ':tva'  => isset($data['taux_tva']) && $data['taux_tva'] !== null ? (float)$data['taux_tva'] : null,
        ':id'   => $id,
    ]);
}

/**
 * Désactiver un article (soft delete).
 */
function db_article_deactivate(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE articles SET actif = 0 WHERE id = ?")->execute([$id]);
}

/**
 * Récupérer un article par son ID.
 */
function db_article_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, c.nom AS categorie_nom, f.nom AS fournisseur_nom
        FROM articles a
        LEFT JOIN categories c ON c.id = a.categorie_id
        LEFT JOIN fournisseurs f ON f.id = a.fournisseur_id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Récupérer un article par code-barres (pour la caisse).
 * Si $magasin_id est fourni, retourne le stock de ce magasin.
 */
function db_article_get_by_barcode(PDO $pdo, string $code, int $magasin_id = 0): ?array {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.code_barre, a.nom, a.sku, a.prix_vente, a.taux_tva,
                   a.unite_mesure, a.vente_au_poids, a.poids_precision,
                   COALESCE(sm.quantite, 0) AS quantite_stock,
                   COALESCE(sm.stock_alerte, a.seuil_alerte) AS seuil_alerte
            FROM articles a
            LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = ?
            WHERE a.code_barre = ? AND a.actif = 1
            LIMIT 1
        ");
        $stmt->execute([$magasin_id, $code]);
    } else {
        $stmt = $pdo->prepare("
            SELECT id, code_barre, nom, sku, prix_vente, taux_tva,
                   unite_mesure, vente_au_poids, poids_precision, quantite_stock, seuil_alerte
            FROM articles WHERE code_barre = ? AND actif = 1 LIMIT 1
        ");
        $stmt->execute([$code]);
    }
    return $stmt->fetch() ?: null;
}

/**
 * Récupérer un article pour vérification de stock (FOR UPDATE).
 * Lock la ligne stock_magasins si magasin_id fourni.
 */
function db_article_get_for_update(PDO $pdo, int $id, int $magasin_id = 0): ?array {
    // Verrouiller la ligne articles
    $stmt = $pdo->prepare("SELECT id, nom, quantite_stock, prix_vente, taux_tva FROM articles WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $article = $stmt->fetch();
    if (!$article) return null;

    if ($magasin_id > 0) {
        // Verrouiller séparément la ligne stock_magasins
        $stmt2 = $pdo->prepare("SELECT quantite FROM stock_magasins WHERE magasin_id = ? AND article_id = ? FOR UPDATE");
        $stmt2->execute([$magasin_id, $id]);
        $sm = $stmt2->fetch();
        $article['quantite_stock'] = $sm ? (int)$sm['quantite'] : 0;
    }

    return $article;
}

/**
 * Récupérer un article pour mouvement (FOR UPDATE, avec seuil).
 * Lock la ligne stock_magasins si magasin_id fourni.
 */
function db_article_get_for_mouvement(PDO $pdo, int $id, int $magasin_id = 0): ?array {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare("
            SELECT COALESCE(sm.quantite, 0) AS quantite_stock, a.nom, a.prix_achat, a.cump
            FROM articles a
            LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = ?
            WHERE a.id = ? FOR UPDATE
        ");
        $stmt->execute([$magasin_id, $id]);
    } else {
        $stmt = $pdo->prepare("SELECT quantite_stock, nom, prix_achat, cump FROM articles WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
    }
    return $stmt->fetch() ?: null;
}

/**
 * Mettre à jour le stock d'un article (delta positif ou négatif).
 * Si magasin_id fourni, met à jour stock_magasins ; sinon articles (rétro-compat).
 */
function db_article_update_stock(PDO $pdo, int $id, int $delta, int $magasin_id = 0): void {
    if ($magasin_id > 0) {
        db_stock_magasin_update($pdo, $magasin_id, $id, $delta);
    } else {
        $pdo->prepare("UPDATE articles SET quantite_stock = quantite_stock + ? WHERE id = ?")
            ->execute([$delta, $id]);
    }
}

/**
 * Décrémenter le stock d'un article (vente).
 * Si magasin_id fourni, décrémente stock_magasins.
 */
function db_article_decrement_stock(PDO $pdo, int $id, int $quantite, int $magasin_id = 0): void {
    if ($magasin_id > 0) {
        db_stock_magasin_update($pdo, $magasin_id, $id, -$quantite);
    } else {
        $pdo->prepare("UPDATE articles SET quantite_stock = quantite_stock - ? WHERE id = ?")
            ->execute([$quantite, $id]);
    }
}

/**
 * Lister tous les articles actifs (pour dropdowns).
 */
function db_articles_list_active(PDO $pdo, int $limit = 500): array {
    $limit = max(1, min(1000, (int)$limit));
    $stmt = $pdo->prepare("SELECT id, nom, code_barre FROM articles WHERE actif=1 ORDER BY nom LIMIT :limit");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Rechercher des articles avec filtres dynamiques (stock consultation).
 */
function db_articles_search_stock(PDO $pdo, string $search = '', int $limit = 200, int $magasin_id = 0, int $categorie_id = 0): array {
    $limit = max(1, min(1000, (int)$limit));
    $where = ["a.actif = 1"];
    $params = [];

    if ($magasin_id > 0) {
        $sql = "
            SELECT a.id, a.code_barre, a.nom, a.sku, a.prix_achat, a.prix_vente,
                   COALESCE(sm.quantite, 0) AS quantite_stock,
                   COALESCE(sm.stock_alerte, a.seuil_alerte) AS seuil_alerte,
                   a.emplacement, c.nom AS categorie_nom
            FROM articles a
            LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = :mid
            LEFT JOIN categories c ON c.id = a.categorie_id
        ";
        $params[':mid'] = $magasin_id;
    } else {
        $sql = "
            SELECT a.id, a.code_barre, a.nom, a.sku, a.prix_achat, a.prix_vente, a.quantite_stock, a.seuil_alerte,
                   a.emplacement, c.nom AS categorie_nom
            FROM articles a
            LEFT JOIN categories c ON c.id = a.categorie_id
        ";
    }

    if ($search !== '') {
        $where[] = "(a.nom LIKE :s1 OR a.code_barre LIKE :s2 OR a.sku LIKE :s3 OR a.emplacement LIKE :s4)";
        $params[':s1'] = "%$search%";
        $params[':s2'] = "%$search%";
        $params[':s3'] = "%$search%";
        $params[':s4'] = "%$search%";
    }

    if ($categorie_id > 0) {
        $where[] = "a.categorie_id = :cid";
        $params[':cid'] = $categorie_id;
    }

    $sql .= " WHERE " . implode(" AND ", $where) . " ORDER BY a.nom LIMIT " . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Construit SQL + params pour la recherche d'articles (compatible paginate()).
 * Accepte une chaîne $search ou un tableau de filtres ['search' => ..., 'categorie_id' => ..., 'fournisseur_id' => ...].
 */
function db_articles_search_sql($search_or_filters = '', int $magasin_id = 0): array {
    $search = '';
    $cat_id = 0;
    $four_id = 0;

    if (is_array($search_or_filters)) {
        $search  = trim($search_or_filters['search'] ?? '');
        $cat_id  = (int)($search_or_filters['categorie_id'] ?? 0);
        $four_id = (int)($search_or_filters['fournisseur_id'] ?? 0);
    } else {
        $search = trim((string)$search_or_filters);
    }

    $params = [];
    if ($magasin_id > 0) {
        $stock_select = ", COALESCE(sm.quantite, 0) AS quantite_stock";
        $stock_join   = " LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = ?";
        $params[] = $magasin_id;
    } else {
        $stock_select = ", a.quantite_stock AS quantite_stock";
        $stock_join   = "";
    }

    $sql = "SELECT a.id, a.code_barre, a.nom, a.sku, a.prix_achat, a.prix_vente, a.taux_tva,
                   a.seuil_alerte, a.emplacement, a.fournisseur_id, a.categorie_id, a.actif, a.date_creation,
                   f.nom AS fournisseur_nom, c.nom AS categorie_nom{$stock_select}
        FROM articles a
        LEFT JOIN fournisseurs f ON f.id = a.fournisseur_id
        LEFT JOIN categories c ON c.id = a.categorie_id{$stock_join}
        WHERE a.actif = 1
    ";

    if ($search !== '') {
        $sql .= " AND (a.nom LIKE ? OR a.code_barre LIKE ? OR a.sku LIKE ?) ";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    if ($cat_id > 0) {
        $sql .= " AND a.categorie_id = ? ";
        $params[] = $cat_id;
    }

    if ($four_id > 0) {
        $sql .= " AND a.fournisseur_id = ? ";
        $params[] = $four_id;
    }

    $sql .= " ORDER BY a.nom ASC";
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Compter les articles actifs.
 */
function db_articles_count_active(PDO $pdo, int $magasin_id = 0): int {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT a.id) FROM articles a
             JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = :mid
             WHERE a.actif = 1"
        );
        $stmt->execute([':mid' => $magasin_id]);
        return (int)$stmt->fetchColumn();
    }
    return (int)$pdo->query("SELECT COUNT(*) FROM articles WHERE actif = 1")->fetchColumn();
}

/**
 * Valeur totale du stock (prix d'achat).
 * Si magasin_id fourni, calcule pour ce magasin uniquement.
 */
function db_articles_stock_value(PDO $pdo, int $magasin_id = 0): float {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(sm.quantite * a.prix_achat), 0)
             FROM stock_magasins sm
             JOIN articles a ON a.id = sm.article_id
             WHERE sm.magasin_id = :mid AND a.actif = 1"
        );
        $stmt->execute([':mid' => $magasin_id]);
        return (float)$stmt->fetchColumn();
    }
    return (float)$pdo->query("SELECT COALESCE(SUM(quantite_stock * prix_achat),0) FROM articles")->fetchColumn();
}

/**
 * Articles en alerte de stock (stock <= seuil).
 * Si magasin_id fourni, filtre par magasin.
 */
function db_articles_low_stock(PDO $pdo, int $limit = 50, int $magasin_id = 0): array {
    $limit = max(1, (int)$limit);
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare("
            SELECT a.code_barre, a.nom, COALESCE(sm.quantite, 0) AS quantite_stock,
                   COALESCE(sm.stock_alerte, a.seuil_alerte) AS seuil_alerte, a.emplacement
            FROM articles a
            LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = :mid
            WHERE a.actif = 1 AND COALESCE(sm.quantite, 0) <= COALESCE(sm.stock_alerte, a.seuil_alerte)
            ORDER BY (COALESCE(sm.quantite, 0) - COALESCE(sm.stock_alerte, a.seuil_alerte)) ASC, a.nom ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':mid', $magasin_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    $stmt = $pdo->prepare("
        SELECT code_barre, nom, quantite_stock, seuil_alerte, emplacement
        FROM articles
        WHERE actif = 1 AND quantite_stock <= seuil_alerte
        ORDER BY (quantite_stock - seuil_alerte) ASC, nom ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Ventes par article sur une période donnée (factures payées, magasin optionnel).
 */
function db_articles_ventes_periode(PDO $pdo, int $jours = 30, int $magasin_id = 0): array {
    $jours = max(1, min(365, (int)$jours));
    $sql = "
        SELECT l.article_id, SUM(l.quantite) AS quantite_vendue, COUNT(DISTINCT l.facture_id) AS nb_ventes
        FROM lignes_facture l
        JOIN factures f ON f.id = l.facture_id
        WHERE f.statut = 'Payee' AND f.date_facture >= DATE_SUB(NOW(), INTERVAL :jours DAY)
    ";
    if ($magasin_id > 0) {
        $sql .= " AND f.magasin_id = :mid";
    }
    $sql .= " GROUP BY l.article_id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':jours', $jours, PDO::PARAM_INT);
    if ($magasin_id > 0) {
        $stmt->bindValue(':mid', $magasin_id, PDO::PARAM_INT);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Articles à réapprovisionner avec analyse des ventes : vitesse de vente,
 * jours de couverture restants et quantité recommandée (couverture cible).
 */
function db_articles_achat_recommandes(PDO $pdo, int $magasin_id, int $jours_couverture = 30, int $jours_etude = 30): array {
    $magasin_id = max(0, (int)$magasin_id);
    $jours_couverture = max(7, min(90, (int)$jours_couverture));
    $jours_etude = max(7, min(365, (int)$jours_etude));

    $articles = db_articles_low_stock_with_supplier($pdo, 200, $magasin_id);
    $ventes_par_article = [];
    foreach (db_articles_ventes_periode($pdo, $jours_etude, $magasin_id) as $v) {
        $ventes_par_article[(int)$v['article_id']] = $v;
    }

    foreach ($articles as &$a) {
        $id = (int)$a['id'];
        $qte = (int)($a['quantite_stock'] ?? 0);
        $seuil = (int)($a['seuil_alerte'] ?? 0);
        $vendus = (int)($ventes_par_article[$id]['quantite_vendue'] ?? 0);
        $nb_ventes = (int)($ventes_par_article[$id]['nb_ventes'] ?? 0);
        $vitesse = $jours_etude > 0 ? $vendus / $jours_etude : 0;

        $a['quantite_vendue_30j'] = $vendus;
        $a['nb_ventes_30j'] = $nb_ventes;
        $a['vitesse_jour'] = round($vitesse, 2);
        $a['jours_couverture'] = $vitesse > 0 ? (int)floor($qte / $vitesse) : null;

        if ($vitesse > 0) {
            $a['quantite_recommandee'] = max(0, (int)ceil($vitesse * $jours_couverture) - $qte);
            if ($a['quantite_recommandee'] <= 0) {
                $a['quantite_recommandee'] = max(1, $seuil - $qte);
            }
        } else {
            $a['quantite_recommandee'] = max(1, $seuil - $qte);
        }
    }
    unset($a);

    usort($articles, function (array $x, array $y): int {
        $x_urg = (int)($x['quantite_stock'] ?? 0) - (int)($x['seuil_alerte'] ?? 0);
        $y_urg = (int)($y['quantite_stock'] ?? 0) - (int)($y['seuil_alerte'] ?? 0);
        if ($x_urg !== $y_urg) {
            return $x_urg <=> $y_urg;
        }
        return (float)($y['vitesse_jour'] ?? 0) <=> (float)($x['vitesse_jour'] ?? 0);
    });

    return $articles;
}

/**
 * Meilleurs vendeurs sur une période (articles actifs, même hors alerte).
 */
function db_articles_hautes_ventes(PDO $pdo, int $magasin_id, int $limit = 10, int $jours = 30): array {
    $limit = max(1, min(50, (int)$limit));
    $jours = max(7, min(365, (int)$jours));
    $mid = (int)$magasin_id;
    // NOTE : $jours et $mid sont des entiers bornés, interpolés car MySQL interdit
    // les placeholders dans INTERVAL ... DAY et refuse la répétition d'un même
    // paramètre nommé en mode natif (PDO::ATTR_EMULATE_PREPARES => false).
    $sql = "
        SELECT a.id, a.nom, a.code_barre, a.prix_vente, a.fournisseur_id, a.seuil_alerte,
               f.nom AS fournisseur_nom,
               COALESCE(sm.quantite, 0) AS quantite_stock,
               COALESCE(v.quantite_vendue, 0) AS quantite_vendue,
               COALESCE(v.nb_ventes, 0) AS nb_ventes
        FROM articles a
        LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = {$mid}
        LEFT JOIN fournisseurs f ON f.id = a.fournisseur_id
        LEFT JOIN (
            SELECT l.article_id, SUM(l.quantite) AS quantite_vendue, COUNT(DISTINCT l.facture_id) AS nb_ventes
            FROM lignes_facture l
            JOIN factures ff ON ff.id = l.facture_id
            WHERE ff.statut = 'Payee' AND ff.date_facture >= DATE_SUB(NOW(), INTERVAL {$jours} DAY) AND ff.magasin_id = {$mid}
            GROUP BY l.article_id
        ) v ON v.article_id = a.id
        WHERE a.actif = 1
        ORDER BY quantite_vendue DESC, a.nom ASC
        LIMIT :limit
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $articles = $stmt->fetchAll();
    foreach ($articles as &$a) {
        $a['vitesse_jour'] = round(((int)($a['quantite_vendue'] ?? 0)) / $jours, 2);
    }
    unset($a);
    return $articles;
}

/**
 * Articles en alerte de stock avec informations fournisseur (pour suggestions d'achat).
 * Si magasin_id fourni, filtre par magasin.
 */
function db_articles_low_stock_with_supplier(PDO $pdo, int $limit = 100, int $magasin_id = 0): array {
    $limit = max(1, (int)$limit);
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare("
            SELECT a.id, a.code_barre, a.nom, COALESCE(sm.quantite, 0) AS quantite_stock,
                   COALESCE(sm.stock_alerte, a.seuil_alerte) AS seuil_alerte,
                   a.emplacement, a.prix_vente, a.prix_achat, a.fournisseur_id,
                   f.nom AS fournisseur_nom, f.contact AS fournisseur_contact,
                   f.telephone AS fournisseur_telephone
            FROM articles a
            LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = :mid
            LEFT JOIN fournisseurs f ON f.id = a.fournisseur_id
            WHERE a.actif = 1 AND COALESCE(sm.quantite, 0) <= COALESCE(sm.stock_alerte, a.seuil_alerte)
            ORDER BY (COALESCE(sm.quantite, 0) - COALESCE(sm.stock_alerte, a.seuil_alerte)) ASC, a.nom ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':mid', $magasin_id, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    $stmt = $pdo->prepare("
        SELECT a.id, a.code_barre, a.nom, a.quantite_stock, a.seuil_alerte,
               a.emplacement, a.prix_vente, a.prix_achat, a.fournisseur_id,
               f.nom AS fournisseur_nom, f.contact AS fournisseur_contact,
               f.telephone AS fournisseur_telephone
        FROM articles a
        LEFT JOIN fournisseurs f ON f.id = a.fournisseur_id
        WHERE a.actif = 1 AND a.quantite_stock <= a.seuil_alerte
        ORDER BY (a.quantite_stock - a.seuil_alerte) ASC, a.nom ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Compter les articles en alerte de stock (pour le badge sidebar).
 * Si magasin_id fourni, filtre par magasin.
 */
function db_articles_low_stock_count(PDO $pdo, int $magasin_id = 0): int {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM stock_magasins sm
             JOIN articles a ON a.id = sm.article_id
             WHERE sm.magasin_id = :mid AND a.actif = 1
             AND sm.quantite <= sm.stock_alerte"
        );
        $stmt->execute([':mid' => $magasin_id]);
        return (int)$stmt->fetchColumn();
    }
    return (int)$pdo->query(
        "SELECT COUNT(*) FROM articles WHERE actif = 1 AND quantite_stock <= seuil_alerte"
    )->fetchColumn();
}

// ============================================================
//  FOURNISSEURS
// ============================================================

/**
 * Insérer un fournisseur. Retourne le nouvel ID.
 */
function db_fournisseur_insert(PDO $pdo, string $nom, ?string $contact, ?string $telephone): int {
    $stmt = $pdo->prepare("INSERT INTO fournisseurs (nom, contact, telephone) VALUES (:nom,:c,:t)");
    $stmt->execute([':nom' => $nom, ':c' => $contact, ':t' => $telephone]);
    return (int)$pdo->lastInsertId();
}

/**
 * Mettre à jour un fournisseur.
 */
function db_fournisseur_update(PDO $pdo, int $id, string $nom, ?string $contact, ?string $telephone): void {
    $stmt = $pdo->prepare("UPDATE fournisseurs SET nom=:nom, contact=:c, telephone=:t WHERE id=:id");
    $stmt->execute([':nom' => $nom, ':c' => $contact, ':t' => $telephone, ':id' => $id]);
}

/**
 * Supprimer un fournisseur.
 */
function db_fournisseur_delete(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM fournisseurs WHERE id = ?")->execute([$id]);
}

/**
 * Récupérer un fournisseur par ID.
 */
function db_fournisseur_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM fournisseurs WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Lister tous les fournisseurs avec le nombre d'articles liés.
 */
function db_fournisseurs_list_with_count(PDO $pdo, int $limit = 500): array {
    $limit = max(1, min(1000, (int)$limit));
    $stmt = $pdo->prepare("
        SELECT f.*, COUNT(a.id) AS nb_articles
        FROM fournisseurs f
        LEFT JOIN articles a ON a.fournisseur_id = f.id
        GROUP BY f.id
        ORDER BY f.nom
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Lister tous les fournisseurs (pour dropdowns).
 */
function db_fournisseurs_list(PDO $pdo): array {
    return $pdo->query("SELECT id, nom FROM fournisseurs ORDER BY nom")->fetchAll();
}

// ============================================================
//  UTILISATEURS
// ============================================================

/**
 * Vérifier l'unicité du login (exclure un ID optionnel).
 */
function db_user_login_exists(PDO $pdo, string $login, int $exclude_id = 0): bool {
    $stmt = $pdo->prepare("SELECT id FROM utilisateurs WHERE login = ? AND id <> ? LIMIT 1");
    $stmt->execute([$login, $exclude_id]);
    return (bool)$stmt->fetch();
}

/**
 * Insérer un utilisateur. Retourne le nouvel ID.
 */
function db_user_insert(PDO $pdo, string $nom, string $login, string $mdp_hash, string $role, int $actif, ?int $magasin_id = null): int {
    $stmt = $pdo->prepare("
        INSERT INTO utilisateurs (nom, login, mot_de_passe, role, magasin_id, actif)
        VALUES (:nom, :login, :mdp, :role, :mag, :actif)
    ");
    $stmt->execute([
        ':nom'   => $nom,
        ':login' => $login,
        ':mdp'   => $mdp_hash,
        ':role'  => $role,
        ':mag'   => $magasin_id > 0 ? $magasin_id : null,
        ':actif' => $actif,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Mettre à jour un utilisateur (avec mot de passe).
 */
function db_user_update_with_password(PDO $pdo, int $id, string $nom, string $login, string $role, int $actif, string $mdp_hash, ?int $magasin_id = null): void {
    $stmt = $pdo->prepare("
        UPDATE utilisateurs
        SET nom=:nom, login=:login, role=:role, actif=:actif, mot_de_passe=:mdp, magasin_id=:mag
        WHERE id=:id
    ");
    $stmt->execute([
        ':nom'   => $nom,
        ':login' => $login,
        ':role'  => $role,
        ':actif' => $actif,
        ':mdp'   => $mdp_hash,
        ':mag'   => $magasin_id > 0 ? $magasin_id : null,
        ':id'    => $id,
    ]);
}

/**
 * Mettre à jour un utilisateur (sans mot de passe).
 */
function db_user_update_without_password(PDO $pdo, int $id, string $nom, string $login, string $role, int $actif, ?int $magasin_id = null): void {
    $stmt = $pdo->prepare("
        UPDATE utilisateurs
        SET nom=:nom, login=:login, role=:role, actif=:actif, magasin_id=:mag
        WHERE id=:id
    ");
    $stmt->execute([
        ':nom'   => $nom,
        ':login' => $login,
        ':role'  => $role,
        ':actif' => $actif,
        ':mag'   => $magasin_id > 0 ? $magasin_id : null,
        ':id'    => $id,
    ]);
}

/**
 * Basculer l'état actif/inactif d'un utilisateur.
 */
function db_user_toggle_active(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE utilisateurs SET actif = 1 - actif WHERE id = ?")->execute([$id]);
}

/**
 * Récupérer un utilisateur par ID.
 */
function db_user_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT id, nom, login, role, actif, magasin_id FROM utilisateurs WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Récupérer un utilisateur par login (authentification).
 */
function db_user_get_by_login(PDO $pdo, string $login): ?array {
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE login = ? AND actif = 1 LIMIT 1");
    $stmt->execute([$login]);
    return $stmt->fetch() ?: null;
}

/**
 * Lister tous les utilisateurs.
 */
function db_users_list(PDO $pdo, int $limit = 200): array {
    $limit = max(1, min(1000, (int)$limit));
    $stmt = $pdo->prepare("
        SELECT u.id, u.nom, u.login, u.role, u.actif, u.date_creation, u.magasin_id, m.nom AS magasin_nom
        FROM utilisateurs u
        LEFT JOIN magasins m ON m.id = u.magasin_id
        ORDER BY u.nom LIMIT :limit"
    );
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Changer le mot de passe d'un utilisateur (usage profil personnel).
 */
function db_user_change_password(PDO $pdo, int $user_id, string $mdp_hash): void {
    $stmt = $pdo->prepare('UPDATE utilisateurs SET mot_de_passe = :mdp WHERE id = :id');
    $stmt->execute([':mdp' => $mdp_hash, ':id' => $user_id]);
}

// ============================================================
//  FACTURES
// ============================================================

/**
 * Compter les factures avec un préfixe donné (génération numéro).
 */
function db_facture_count_by_prefix(PDO $pdo, string $prefix): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE numero_facture LIKE ?");
    $stmt->execute([$prefix . '%']);
    return (int)$stmt->fetchColumn();
}

/**
 * Vérifier l'unicité d'un numéro de facture.
 */
function db_facture_number_exists(PDO $pdo, string $numero): bool {
    $stmt = $pdo->prepare("SELECT id FROM factures WHERE numero_facture = ? LIMIT 1");
    $stmt->execute([$numero]);
    return (bool)$stmt->fetch();
}

/**
 * Insérer une facture. Retourne le nouvel ID.
 */
function db_facture_insert(PDO $pdo, array $data): int {
    $has_magasin = array_key_exists('magasin_id', $data);
    $has_fidelite = array_key_exists('remise_fidelite', $data) || array_key_exists('points_utilises', $data);
    $columns = 'numero_facture, utilisateur_id, total_ht, tva_taux, total_ttc, montant_paye, monnaie_rendue, statut';
    $placeholders = '?, ?, ?, ?, ?, ?, ?, \'Payee\'';
    if ($has_magasin) {
        $columns .= ', magasin_id';
        $placeholders .= ', ?';
    }
    if ($has_fidelite) {
        $columns .= ', remise_fidelite, points_utilises';
        $placeholders .= ', ?, ?';
    }
    $stmt = $pdo->prepare("INSERT INTO factures ($columns) VALUES ($placeholders)");
    $params = [
        $data['numero_facture'],
        $data['utilisateur_id'],
        $data['total_ht'],
        $data['tva_taux'],
        $data['total_ttc'],
        $data['montant_paye'],
        $data['monnaie_rendue'],
    ];
    if ($has_magasin) {
        $params[] = $data['magasin_id'];
    }
    if ($has_fidelite) {
        $params[] = $data['remise_fidelite'] ?? 0.0;
        $params[] = $data['points_utilises'] ?? 0;
    }
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

/**
 * Récupérer une facture par ID avec le nom du vendeur.
 */
function db_facture_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT f.*, u.nom AS vendeur_nom,
        COALESCE(c.raison_sociale, c.nom) AS client_nom,
        c.nif AS client_nif, c.rccm AS client_rccm
        FROM factures f
        LEFT JOIN utilisateurs u ON u.id = f.utilisateur_id
        LEFT JOIN clients c ON c.id = f.client_id
        WHERE f.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Récupérer les lignes d'une facture.
 */
function db_facture_get_lignes(PDO $pdo, int $facture_id): array {
    $stmt = $pdo->prepare("SELECT lf.*, a.nom AS article_nom, a.code_barre
        FROM lignes_facture lf
        JOIN articles a ON a.id = lf.article_id
        WHERE lf.facture_id = ?
        ORDER BY lf.id
    ");
    $stmt->execute([$facture_id]);
    return $stmt->fetchAll();
}

/**
 * Construit SQL + params pour la recherche de factures (compatible paginate()).
 */
function db_factures_search_sql(array $filters = [], int $magasin_id = 0): array {
    $where  = [];
    $params = [];
    $search = $filters['search'] ?? '';
    $statut = $filters['statut'] ?? '';
    $debut  = $filters['debut'] ?? '';
    $fin    = $filters['fin'] ?? '';
    $utilisateur_id = (int)($filters['utilisateur_id'] ?? 0);

    if ($magasin_id > 0) {
        $where[] = "f.magasin_id = ?";
        $params[] = $magasin_id;
    }

    if ($search !== '') {
        $where[] = "(f.numero_facture LIKE ? OR u.nom LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if (in_array($statut, ['Payee', 'Annulee'], true)) {
        $where[] = "f.statut = ?";
        $params[] = $statut;
    }
    if ($debut !== '') {
        $where[] = "DATE(f.date_facture) >= ?";
        $params[] = $debut;
    }
    if ($fin !== '') {
        $where[] = "DATE(f.date_facture) <= ?";
        $params[] = $fin;
    }
    if ($utilisateur_id > 0) {
        $where[] = "f.utilisateur_id = ?";
        $params[] = $utilisateur_id;
    }

    $sql = "
        SELECT f.*, u.nom AS vendeur_nom,
               COUNT(lf.id) AS nb_lignes
        FROM factures f
        LEFT JOIN utilisateurs u ON u.id = f.utilisateur_id
        LEFT JOIN lignes_facture lf ON lf.facture_id = f.id
    ";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " GROUP BY f.id ORDER BY f.date_facture DESC, f.id DESC";
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Compter les ventes du jour.
 */
function db_factures_count_today(PDO $pdo, int $magasin_id = 0): int {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM factures
             WHERE DATE(date_facture) = CURDATE() AND statut = 'Payee' AND magasin_id = :mid"
        );
        $stmt->execute([':mid' => $magasin_id]);
        return (int)$stmt->fetchColumn();
    }
    return (int)$pdo->query("SELECT COUNT(*) FROM factures WHERE DATE(date_facture)=CURDATE() AND statut='Payee'")->fetchColumn();
}

/**
 * Chiffre d'affaires du jour.
 */
function db_factures_revenue_today(PDO $pdo, int $magasin_id = 0): float {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(total_ttc), 0) FROM factures
             WHERE DATE(date_facture) = CURDATE() AND statut = 'Payee' AND magasin_id = :mid"
        );
        $stmt->execute([':mid' => $magasin_id]);
        return (float)$stmt->fetchColumn();
    }
    return (float)$pdo->query("SELECT COALESCE(SUM(total_ttc),0) FROM factures WHERE DATE(date_facture)=CURDATE() AND statut='Payee'")->fetchColumn();
}

// ============================================================
//  STATISTIQUES PAR PÉRIODE
// ============================================================

/**
 * Stats globales sur une période : nombre de ventes + CA total.
 */
function db_stats_period(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT COUNT(*) AS nb_ventes, COALESCE(SUM(total_ttc), 0) AS ca_total,
            COALESCE(SUM(total_ttc - total_ht), 0) AS tva_collectee
        FROM factures
        WHERE DATE(date_facture) BETWEEN ? AND ? AND statut = 'Payee'";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND magasin_id = ?";
        $params[] = $magasin_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: ['nb_ventes' => 0, 'ca_total' => 0, 'tva_collectee' => 0];
}

/**
 * Top N articles les plus vendus sur une période.
 */
function db_stats_top_articles(PDO $pdo, string $debut, string $fin, int $limit = 5, int $magasin_id = 0): array {
    $limit = max(1, min(100, (int)$limit));
    $sql = "SELECT a.nom, a.code_barre, SUM(lf.quantite) AS total_qte,
            SUM(lf.quantite * lf.prix_unitaire) AS total_montant
        FROM lignes_facture lf
        JOIN articles a ON a.id = lf.article_id
        JOIN factures f ON f.id = lf.facture_id
        WHERE DATE(f.date_facture) BETWEEN :debut AND :fin AND f.statut = 'Payee'";
    if ($magasin_id > 0) {
        $sql .= " AND f.magasin_id = :mid";
    }
    $sql .= " GROUP BY a.id, a.nom, a.code_barre
        ORDER BY total_qte DESC
        LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':debut', $debut, PDO::PARAM_STR);
    $stmt->bindValue(':fin', $fin, PDO::PARAM_STR);
    if ($magasin_id > 0) {
        $stmt->bindValue(':mid', $magasin_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Ventes jour par jour sur une période (pour graphique courbe).
 */
function db_stats_daily_sales(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT DATE(f.date_facture) AS jour, COUNT(*) AS nb_ventes,
            SUM(f.total_ttc) AS ca_jour
        FROM factures f
        WHERE DATE(f.date_facture) BETWEEN ? AND ? AND f.statut = 'Payee'";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND f.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $sql .= " GROUP BY DATE(f.date_facture)
        ORDER BY jour ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================================
//  LIGNES DE FACTURE
// ============================================================

/**
 * Insérer une ligne de facture.
 * @param float|null $prix_original Prix unitaire avant remise (null si pas de remise)
 * @param float|null $remise_pct    Pourcentage de remise appliqué (null si pas de remise)
 */
function db_ligne_facture_insert(PDO $pdo, int $facture_id, int $article_id, int $quantite, float $prix_unitaire, ?float $prix_original = null, ?float $remise_pct = null, ?float $taux_tva = null, ?float $quantite_poids = null): void {
    $stmt = $pdo->prepare("
        INSERT INTO lignes_facture (facture_id, article_id, quantite, prix_unitaire, prix_original, remise_pct, taux_tva, quantite_poids)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$facture_id, $article_id, $quantite, $prix_unitaire, $prix_original, $remise_pct, $taux_tva, $quantite_poids]);
}

// ============================================================
//  MOUVEMENTS DE STOCK
// ============================================================

/**
 * Insérer un mouvement de stock.
 */
function db_mouvement_insert(PDO $pdo, int $article_id, ?int $user_id, string $type, int $quantite, ?string $motif = null, int $magasin_id = 0): void {
    $pdo->prepare("INSERT INTO mouvements_stock (article_id, utilisateur_id, type, quantite, motif, magasin_id) VALUES (?, ?, ?, ?, ?, ?)")->execute([$article_id, $user_id, $type, $quantite, $motif, $magasin_id > 0 ? $magasin_id : null]);
}

/**
 * Derniers mouvements (dashboard).
 */
function db_mouvements_recent(PDO $pdo, int $limit = 8, int $magasin_id = 0): array {
    $limit = max(1, min(100, (int)$limit));
    $sql = "
        SELECT m.*, a.nom AS article_nom, u.nom AS user_nom
        FROM mouvements_stock m
        JOIN articles a ON a.id = m.article_id
        LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id";
    if ($magasin_id > 0) {
        $sql .= " WHERE m.magasin_id = :mid";
    }
    $sql .= " ORDER BY m.date_mouvement DESC
        LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    if ($magasin_id > 0) {
        $stmt->bindValue(':mid', $magasin_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Construit SQL + params pour la recherche de mouvements (compatible paginate()).
 */
function db_mouvements_search_sql(array $filters = []): array {
    $where  = [];
    $params = [];
    $f_article = $filters['article'] ?? '';
    $f_type    = $filters['type'] ?? '';
    $f_debut   = $filters['debut'] ?? '';
    $f_fin     = $filters['fin'] ?? '';
    $f_magasin = (int)($filters['magasin'] ?? 0);

    if ($f_article !== '') {
        $where[] = "(a.nom LIKE ? OR a.code_barre LIKE ?)";
        $params[] = "%$f_article%";
        $params[] = "%$f_article%";
    }
    if (in_array($f_type, ['Entree', 'Sortie', 'Vente', 'Transfert', 'Ajustement', 'Retour_stock'], true)) {
        $where[] = "m.type = ?";
        $params[] = $f_type;
    }
    if ($f_debut !== '') {
        $where[] = "DATE(m.date_mouvement) >= ?";
        $params[] = $f_debut;
    }
    if ($f_fin !== '') {
        $where[] = "DATE(m.date_mouvement) <= ?";
        $params[] = $f_fin;
    }
    if ($f_magasin > 0) {
        $where[] = "m.magasin_id = ?";
        $params[] = $f_magasin;
    }

    $sql = "
        SELECT m.*, a.nom AS article_nom, a.code_barre, u.nom AS user_nom,
               mg.nom AS magasin_nom
        FROM mouvements_stock m
        JOIN articles a ON a.id = m.article_id
        LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
        LEFT JOIN magasins mg ON mg.id = m.magasin_id
    ";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY m.date_mouvement DESC, m.id DESC";
    return ['sql' => $sql, 'params' => $params];
}

// ============================================================
//  LOGIN ATTEMPTS (rate limiting)
// ============================================================

/**
 * Enregistrer une tentative de connexion échouée.
 */
function db_login_attempt_insert(PDO $pdo, string $ip, string $login): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, login) VALUES (?, ?)");
        $stmt->execute([$ip, $login]);
    } catch (PDOException $e) {
        error_log('[RATE-LIMIT] Tentative login non enregistree (table absente?): ' . $e->getMessage());
    }
}

/**
 * Compter les tentatives récentes d'une IP.
 */
function db_login_attempt_count(PDO $pdo, string $ip): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
        $stmt->execute([$ip]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('[SECURITE] Table login_attempts absente — rate limiting désactivé. Créez la table pour activer la protection brute-force.');
        return 0;
    }
}

/**
 * Supprimer les tentatives après connexion réussie.
 */
function db_login_attempt_clear(PDO $pdo, string $ip, string $login): void {
    try {
        $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND login = ?")->execute([$ip, $login]);
    } catch (PDOException $e) {
        error_log('[RATE-LIMIT] Tentatives non supprimees (table absente?): ' . $e->getMessage());
    }
}

// ============================================================
//  LOGS D'ACTIVITE (audit trail)
// ============================================================

/**
 * Construit SQL + params pour la recherche de logs d'activité (compatible paginate()).
 */
function db_logs_activite_search_sql(array $filters = [], int $magasin_id = 0): array {
    $where  = [];
    $params = [];
    $search = $filters['search'] ?? '';
    $action = $filters['action'] ?? '';
    $debut  = $filters['debut'] ?? '';
    $fin    = $filters['fin'] ?? '';

    if ($magasin_id > 0) {
        $where[] = "EXISTS (SELECT 1 FROM utilisateurs um WHERE um.id = la.utilisateur_id AND um.magasin_id = ?)";
        $params[] = $magasin_id;
    }

    if ($search !== '') {
        $where[] = "(la.action LIKE ? OR la.details LIKE ? OR la.utilisateur_id IN (SELECT id FROM utilisateurs WHERE nom LIKE ?))";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($action !== '') {
        $where[] = "la.action = ?";
        $params[] = $action;
    }
    if ($debut !== '') {
        $where[] = "DATE(la.date_action) >= ?";
        $params[] = $debut;
    }
    if ($fin !== '') {
        $where[] = "DATE(la.date_action) <= ?";
        $params[] = $fin;
    }

    $sql = "SELECT la.*, u.nom AS utilisateur_nom
            FROM logs_activite la
            LEFT JOIN utilisateurs u ON u.id = la.utilisateur_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY la.date_action DESC, la.id DESC";
    return ['sql' => $sql, 'params' => $params];
}

// ============================================================
//  MAGASINS & STOCK PAR MAGASIN
// ============================================================

/**
 * Lister les magasins actifs.
 */
function db_magasins_list(PDO $pdo): array {
    return $pdo->query("SELECT id, nom, adresse, code_postal FROM magasins WHERE actif = 1 ORDER BY nom")->fetchAll();
}

/**
 * Récupérer un magasin par ID.
 */
function db_magasin_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT id, nom, adresse, code_postal, nif, rccm, actif FROM magasins WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Lister tous les magasins (actifs + inactifs), triés par statut puis nom.
 */
function db_magasins_list_all(PDO $pdo): array {
    return $pdo->query("SELECT id, nom, adresse, code_postal, nif, rccm, actif, date_creation FROM magasins ORDER BY actif DESC, nom")->fetchAll();
}

/**
 * Créer un nouveau magasin et initialiser le stock à 0 pour tous les articles actifs.
 */
function db_magasin_insert(PDO $pdo, string $nom, ?string $adresse, ?string $code_postal, ?string $nif = null, ?string $rccm = null): int {
    $stmt = $pdo->prepare(
        "INSERT INTO magasins (nom, adresse, code_postal, nif, rccm) VALUES (:nom, :adresse, :cp, :nif, :rccm)"
    );
    $stmt->execute([
        ':nom'     => $nom,
        ':adresse' => $adresse !== '' ? $adresse : null,
        ':cp'      => $code_postal !== '' ? $code_postal : null,
        ':nif'     => $nif !== '' && $nif !== null ? $nif : null,
        ':rccm'    => $rccm !== '' && $rccm !== null ? $rccm : null,
    ]);
    $magasin_id = (int) $pdo->lastInsertId();

    // Initialiser le stock à 0 pour tous les articles actifs
    db_stock_magasin_init_for_articleBulk($pdo, $magasin_id);

    return $magasin_id;
}

/**
 * Mettre à jour les informations d'un magasin (identité + identification fiscale).
 */
function db_magasin_update(PDO $pdo, int $id, string $nom, ?string $adresse, ?string $code_postal, ?string $nif = null, ?string $rccm = null): void {
    $stmt = $pdo->prepare(
        "UPDATE magasins SET nom = :nom, adresse = :adresse, code_postal = :cp, nif = :nif, rccm = :rccm WHERE id = :id"
    );
    $stmt->execute([
        ':nom'     => $nom,
        ':adresse' => $adresse !== '' ? $adresse : null,
        ':cp'      => $code_postal !== '' ? $code_postal : null,
        ':nif'     => $nif !== '' && $nif !== null ? $nif : null,
        ':rccm'    => $rccm !== '' && $rccm !== null ? $rccm : null,
        ':id'      => $id,
    ]);
}

/**
 * Basculer le statut actif/inactif d'un magasin.
 */
function db_magasin_toggle_active(PDO $pdo, int $id): void {
    $stmt = $pdo->prepare("UPDATE magasins SET actif = NOT actif WHERE id = ?");
    $stmt->execute([$id]);
}

/**
 * Initialiser le stock d'un magasin pour tous les articles actifs (bulk, appelé à la création du magasin).
 */
function db_stock_magasin_init_for_articleBulk(PDO $pdo, int $magasin_id, int $quantite = 0, ?int $alerte = null): void {
    // Récupérer chaque article avec son seuil_alerte personnel
    $articles = $pdo->query("SELECT id, seuil_alerte FROM articles WHERE actif = 1")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($articles)) return;

    $stmt = $pdo->prepare(
        "INSERT INTO stock_magasins (magasin_id, article_id, quantite, stock_alerte)
         VALUES (:mag, :art, :qte, :alerte)
         ON DUPLICATE KEY UPDATE quantite = :qte2"
    );
    foreach ($articles as $article) {
        $seuil = $alerte ?? (int)$article['seuil_alerte'];
        $stmt->execute([
            ':mag'    => $magasin_id,
            ':art'    => (int)$article['id'],
            ':qte'    => $quantite,
            ':alerte' => $seuil,
            ':qte2'   => $quantite,
        ]);
    }
}

/**
 * Récupérer le stock d'un article dans un magasin donné.
 */
function db_stock_magasin_get(PDO $pdo, int $magasin_id, int $article_id): ?array {
    $stmt = $pdo->prepare("SELECT quantite, stock_alerte FROM stock_magasins WHERE magasin_id = ? AND article_id = ?");
    $stmt->execute([$magasin_id, $article_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Récupérer le stock d'un article dans un magasin avec verrouillage (FOR UPDATE).
 */
function db_stock_magasin_get_for_update(PDO $pdo, int $magasin_id, int $article_id): ?array {
    $stmt = $pdo->prepare("SELECT quantite, stock_alerte FROM stock_magasins WHERE magasin_id = ? AND article_id = ? FOR UPDATE");
    $stmt->execute([$magasin_id, $article_id]);
    return $stmt->fetch() ?: null;
}

/**
 * Mettre à jour le stock d'un article dans un magasin (delta positif ou négatif).
 */
function db_stock_magasin_update(PDO $pdo, int $magasin_id, int $article_id, int $delta): void {
    $stmt = $pdo->prepare("UPDATE stock_magasins SET quantite = quantite + :delta WHERE magasin_id = :mag AND article_id = :art");
    $stmt->execute([':delta' => $delta, ':mag' => $magasin_id, ':art' => $article_id]);

    // Conserver le stock global cohérent avec les mouvements par magasin
    $pdo->prepare("UPDATE articles SET quantite_stock = GREATEST(0, quantite_stock + :delta) WHERE id = :art")
        ->execute([':delta' => $delta, ':art' => $article_id]);
}

/**
 * Insérer ou mettre à jour le stock d'un article dans un magasin (UPSERT).
 */
function db_stock_magasin_upsert(PDO $pdo, int $magasin_id, int $article_id, int $quantite, int $alerte = 5): void {
    $stmt = $pdo->prepare(
        "INSERT INTO stock_magasins (magasin_id, article_id, quantite, stock_alerte)
         VALUES (:mag, :art, :qte, :alerte)
         ON DUPLICATE KEY UPDATE quantite = :qte2, stock_alerte = :alerte2"
    );
    $stmt->execute([
        ':mag'     => $magasin_id,
        ':art'     => $article_id,
        ':qte'     => $quantite,
        ':alerte'  => $alerte,
        ':qte2'    => $quantite,
        ':alerte2' => $alerte,
    ]);
}

/**
 * Incrémenter le stock d'un article dans un magasin (ajout de lot).
 * Utilise UPSERT : crée la ligne si elle n'existe pas, sinon ajoute la quantité.
 */
function db_stock_magasin_increment(PDO $pdo, int $magasin_id, int $article_id, int $quantite_ajoutee): void {
    $stmt = $pdo->prepare(
        "INSERT INTO stock_magasins (magasin_id, article_id, quantite, stock_alerte)
         VALUES (:mag, :art, :qte, 5)
         ON DUPLICATE KEY UPDATE quantite = quantite + :qte2"
    );
    $stmt->execute([
        ':mag'  => $magasin_id,
        ':art'  => $article_id,
        ':qte'  => $quantite_ajoutee,
        ':qte2' => $quantite_ajoutee,
    ]);

    // Maintenir le stock global (articles.quantite_stock) synchronisé
    $pdo->prepare("UPDATE articles SET quantite_stock = quantite_stock + :qte WHERE id = :art")
        ->execute([':qte' => $quantite_ajoutee, ':art' => $article_id]);
}

/**
 * Transférer du stock entre deux magasins (transactionnel, avec FOR UPDATE).
 */
function db_transferer_stock(
    PDO $pdo,
    int $article_id,
    int $source_id,
    int $destination_id,
    int $quantite,
    ?string $motif = null
): void {
    if ($source_id === $destination_id) {
        throw new RuntimeException('Les magasins source et destination sont identiques.');
    }
    if ($quantite <= 0) {
        throw new RuntimeException('La quantité doit être supérieure à zéro.');
    }

    $pdo->beginTransaction();
    try {
        // 1. Verrouiller la ligne de stock SOURCE
        $stmt = $pdo->prepare(
            'SELECT id, quantite FROM stock_magasins
             WHERE magasin_id = :src AND article_id = :art FOR UPDATE'
        );
        $stmt->execute([':src' => $source_id, ':art' => $article_id]);
        $stock_src = $stmt->fetch();

        if (!$stock_src) {
            throw new RuntimeException('Article non présent en stock dans le magasin source.');
        }
        if ((int)$stock_src['quantite'] < $quantite) {
            throw new RuntimeException(
                'Stock insuffisant dans le magasin source. Disponible : '
                . $stock_src['quantite'] . ', demandé : ' . $quantite
            );
        }

        // 1bis. Transfert des lots (traçabilité FEFO) si l'article en possède dans la source
        $lots_dispo = db_lots_by_article($pdo, $article_id, $source_id);
        $total_lots = !empty($lots_dispo) ? array_sum(array_column($lots_dispo, 'quantite')) : 0;
        if ($total_lots > 0) {
            if ($quantite > $total_lots) {
                throw new RuntimeException(
                    'Stock insuffisant par lots dans le magasin source. Disponible en lots : '
                    . $total_lots . ', demandé : ' . $quantite
                );
            }
            $lots_decrementes = db_lot_decrement_fefo($pdo, $article_id, $source_id, $quantite);
            foreach ($lots_decrementes as $part) {
                db_lot_upsert($pdo, $article_id, $destination_id, $part['numero_lot'], (int)$part['quantite_prise'], $part['date_peremption']);
            }
        }

        // 2. Décrémenter le stock source
        $upd = $pdo->prepare(
            'UPDATE stock_magasins SET quantite = quantite - :qte
             WHERE magasin_id = :src AND article_id = :art'
        );
        $upd->execute([':qte' => $quantite, ':src' => $source_id, ':art' => $article_id]);

        // 3. Insérer ou incrémenter le stock destination (UPSERT)
        $ins = $pdo->prepare(
            'INSERT INTO stock_magasins (magasin_id, article_id, quantite, stock_alerte)
             VALUES (:dst, :art, :qte, 5)
             ON DUPLICATE KEY UPDATE quantite = quantite + :qte2'
        );
        $ins->execute([
            ':dst'  => $destination_id,
            ':art'  => $article_id,
            ':qte'  => $quantite,
            ':qte2' => $quantite,
        ]);

        // 4. Enregistrer le transfert
        $log = $pdo->prepare(
            'INSERT INTO transferts_stock (article_id, magasin_source_id, magasin_destination_id, quantite, utilisateur_id, motif)
             VALUES (:art, :src, :dst, :qte, :uid, :motif)'
        );
        $log->execute([
            ':art'   => $article_id,
            ':src'   => $source_id,
            ':dst'   => $destination_id,
            ':qte'   => $quantite,
            ':uid'   => $_SESSION['user']['id'] ?? null,
            ':motif' => $motif,
        ]);

        // 5. Traçabilité : un mouvement pour chaque magasin (sortie source, entrée destination)
        $uid = $_SESSION['user']['id'] ?? null;
        db_mouvement_insert($pdo, $article_id, $uid, 'Transfert', $quantite, $motif, $source_id);
        db_mouvement_insert($pdo, $article_id, $uid, 'Entree', $quantite, $motif, $destination_id);

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Lister les transferts récents avec noms des magasins et articles.
 */
function db_transferts_list(PDO $pdo, int $magasin_id = 0, int $limit = 50): array {
    $limit = max(1, min(500, (int)$limit));
    $sql = "SELECT t.*, a.nom AS article_nom, a.code_barre,
                   ms.nom AS source_nom, md.nom AS destination_nom,
                   u.nom AS utilisateur_nom
            FROM transferts_stock t
            JOIN articles a ON a.id = t.article_id
            JOIN magasins ms ON ms.id = t.magasin_source_id
            JOIN magasins md ON md.id = t.magasin_destination_id
            LEFT JOIN utilisateurs u ON u.id = t.utilisateur_id";
    
    if ($magasin_id > 0) {
        $sql .= " WHERE t.magasin_source_id = :mid OR t.magasin_destination_id = :mid2";
    }
    $sql .= " ORDER BY t.date_transfert DESC LIMIT :limit";
    
    $stmt = $pdo->prepare($sql);
    if ($magasin_id > 0) {
        $stmt->bindValue(':mid', $magasin_id, PDO::PARAM_INT);
        $stmt->bindValue(':mid2', $magasin_id, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Initialiser le stock d'un article dans les magasins actifs (appelé à la création d'article).
 * Le stock initial est affecté au PREMIER magasin actif uniquement pour éviter de
 * dupliquer la quantité dans chaque magasin (la somme des stocks magasins doit rester
 * égale à articles.quantite_stock). Les autres magasins démarrent à 0.
 */
function db_stock_magasin_init_for_article(PDO $pdo, int $article_id, int $quantite = 0, int $alerte = 5): void {
    $magasins = $pdo->query("SELECT id FROM magasins WHERE actif = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $qte_magasin = $quantite;
    foreach ($magasins as $mid) {
        db_stock_magasin_upsert($pdo, (int)$mid, $article_id, $qte_magasin, $alerte);
        $qte_magasin = 0;
    }
}

// ============================================================
//  DÉPENSES (Charges d'exploitation)
// ============================================================

/**
 * Insérer une dépense.
 */
function db_depense_insert(PDO $pdo, ?int $magasin_id, int $utilisateur_id, string $titre, string $categorie, float $montant, string $date_depense, string $description = ''): int {
    $stmt = $pdo->prepare("
        INSERT INTO depenses (magasin_id, utilisateur_id, titre, categorie, montant, date_depense, description)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$magasin_id > 0 ? $magasin_id : null, $utilisateur_id, $titre, $categorie, $montant, $date_depense, $description ?: null]);
    return (int)$pdo->lastInsertId();
}

/**
 * Lister les dépenses sur une période, avec filtre magasin optionnel.
 */
function db_depenses_list(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT d.*, u.nom AS utilisateur_nom, m.nom AS magasin_nom
            FROM depenses d
            LEFT JOIN utilisateurs u ON u.id = d.utilisateur_id
            LEFT JOIN magasins m     ON m.id = d.magasin_id
            WHERE d.date_depense BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND (d.magasin_id = ? OR d.magasin_id IS NULL)";
        $params[] = $magasin_id;
    }
    $sql .= " ORDER BY d.date_depense DESC, d.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Total des dépenses sur une période, avec filtre magasin optionnel.
 */
function db_depenses_total(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): float {
    $sql = "SELECT COALESCE(SUM(montant), 0) FROM depenses WHERE date_depense BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND (magasin_id = ? OR magasin_id IS NULL)";
        $params[] = $magasin_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

/**
 * Dépenses regroupées par catégorie sur une période.
 */
function db_depenses_by_categorie(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT categorie, SUM(montant) AS total
            FROM depenses
            WHERE date_depense BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND (magasin_id = ? OR magasin_id IS NULL)";
        $params[] = $magasin_id;
    }
    $sql .= " GROUP BY categorie ORDER BY total DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Dépenses jour par jour sur une période (pour graphique).
 */
function db_depenses_daily(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT DATE(date_depense) AS jour, SUM(montant) AS total
            FROM depenses
            WHERE date_depense BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND (magasin_id = ? OR magasin_id IS NULL)";
        $params[] = $magasin_id;
    }
    $sql .= " GROUP BY DATE(date_depense) ORDER BY jour ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================================
//  STATISTIQUES COMPTABLES : Bénéfice Net
// ============================================================

/**
 * Coût d'achat des marchandises vendues (CAMV) sur une période.
 * Coût retenu par ligne : CUMP courant de l'article quand il est alimenté
 * (valorisation_stock.sql), sinon prix_achat de la fiche article (rétro-compat
 * pour les articles créés avant l'activation de la valorisation).
 */
function db_stats_cout_achat_ventes(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): float {
    $sql = "SELECT COALESCE(SUM(lf.quantite * COALESCE(NULLIF(a.cump, 0), a.prix_achat)), 0)
            FROM lignes_facture lf
            JOIN articles a ON a.id = lf.article_id
            JOIN factures f ON f.id = lf.facture_id
            WHERE DATE(f.date_facture) BETWEEN ? AND ? AND f.statut = 'Payee'";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND f.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

/**
 * Bénéfice net = CA − CAMV − Dépenses.
 */
function db_stats_benefice_net(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    // CA, CAMV et dépenses filtrés sur le MÊME magasin pour un bénéfice cohérent en multi-magasins
    $stats     = db_stats_period($pdo, $debut, $fin, $magasin_id);
    $ca_total  = (float)($stats['ca_total'] ?? 0);
    $camv      = db_stats_cout_achat_ventes($pdo, $debut, $fin, $magasin_id);
    $depenses  = db_depenses_total($pdo, $debut, $fin, $magasin_id);
    $benefice  = $ca_total - $camv - $depenses;
    return [
        'ca_total'    => $ca_total,
        'camv'        => $camv,
        'depenses'    => $depenses,
        'benefice'    => $benefice,
        'marge_brute' => $ca_total - $camv,
    ];
}

// ============================================================
//  CLÔTURE DE CAISSE JOURNALIÈRE (Z de Caisse)
// ============================================================

/**
 * Calculer la somme des ventes non clôturées du jour pour un utilisateur.
 * Retourne le montant total TTC (float).
 */
function db_calculer_ventes_du_jour(PDO $pdo, int $magasin_id, int $utilisateur_id): float {
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(total_ttc), 0)
         FROM factures
         WHERE magasin_id = ?
           AND utilisateur_id = ?
           AND DATE(date_facture) = CURDATE()
           AND statut = 'Payee'
           AND cloture_id IS NULL"
    );
    $stmt->execute([$magasin_id, $utilisateur_id]);
    return (float)$stmt->fetchColumn();
}

/**
 * Compter le nombre de ventes non clôturées du jour pour un utilisateur.
 */
function db_compter_ventes_du_jour(PDO $pdo, int $magasin_id, int $utilisateur_id): int {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM factures
         WHERE magasin_id = ?
           AND utilisateur_id = ?
           AND DATE(date_facture) = CURDATE()
           AND statut = 'Payee'
           AND cloture_id IS NULL"
    );
    $stmt->execute([$magasin_id, $utilisateur_id]);
    return (int)$stmt->fetchColumn();
}

/**
 * Vérifier si une clôture existe déjà pour aujourd'hui (magasin + utilisateur).
 * Retourne true si la caisse est déjà fermée.
 * Sécurité : si $utilisateur_id est 0 ou nul, retourne true (bloquer par précaution).
 */
function db_cloture_deja_ferme(PDO $pdo, int $magasin_id, ?int $utilisateur_id): bool {
    if ($utilisateur_id <= 0) {
        return true;
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM clotures_caisse
         WHERE magasin_id = ? AND utilisateur_id = ? AND date_cloture = CURDATE() AND statut = 'VALIDE'
         LIMIT 1"
    );
    $stmt->execute([$magasin_id, $utilisateur_id]);
    return (bool)$stmt->fetch();
}

/**
 * Insérer une clôture de caisse. Retourne le nouvel ID.
 */
function db_cloture_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare(
        "INSERT INTO clotures_caisse (magasin_id, utilisateur_id, date_cloture, montant_attendu, montant_reel, ecart, statut)
         VALUES (?, ?, CURDATE(), ?, ?, ?, 'VALIDE')"
    );
    $stmt->execute([
        $data['magasin_id'],
        $data['utilisateur_id'],
        $data['montant_attendu'],
        $data['montant_reel'],
        $data['ecart'],
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Verrouiller toutes les ventes du jour en les liant à une clôture.
 */
function db_cloture_verrouiller_ventes(PDO $pdo, int $cloture_id, int $magasin_id, int $utilisateur_id): void {
    $stmt = $pdo->prepare(
        "UPDATE factures SET cloture_id = ?
         WHERE magasin_id = ?
           AND utilisateur_id = ?
           AND DATE(date_facture) = CURDATE()
           AND statut = 'Payee'
           AND cloture_id IS NULL"
    );
    $stmt->execute([$cloture_id, $magasin_id, $utilisateur_id]);
}

/**
 * Récupérer la dernière clôture d'un magasin (et éventuellement d'un utilisateur précis).
 */
function db_cloture_get_derniere(PDO $pdo, int $magasin_id, ?int $utilisateur_id = null): ?array {
    $sql = "SELECT c.*, u.nom AS vendeur_nom
            FROM clotures_caisse c
            LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
            WHERE c.magasin_id = ? AND c.statut = 'VALIDE'";
    $params = [$magasin_id];
    if ($utilisateur_id !== null && $utilisateur_id > 0) {
        $sql .= " AND c.utilisateur_id = ?";
        $params[] = $utilisateur_id;
    }
    $sql .= " ORDER BY c.date_cloture DESC, c.id DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/**
 * Lister les clôtures d'un magasin sur une période (pour l'historique).
 */
function db_clotures_list(PDO $pdo, int $magasin_id, string $date_debut = '', string $date_fin = ''): array {
    $sql = "SELECT c.*, u.nom AS vendeur_nom
            FROM clotures_caisse c
            LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
            WHERE c.magasin_id = ? AND c.statut = 'VALIDE'";
    $params = [$magasin_id];
    if ($date_debut !== '') {
        $sql .= " AND c.date_cloture >= ?";
        $params[] = $date_debut;
    }
    if ($date_fin !== '') {
        $sql .= " AND c.date_cloture <= ?";
        $params[] = $date_fin;
    }
    $sql .= " ORDER BY c.date_cloture DESC, c.id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================================
//  PERMISSIONS & RBAC
// ============================================================

/**
 * Récupérer toutes les permissions avec leur état pour un rôle donné.
 * Retourne un tableau de ['id', 'cle_permission', 'description', 'categorie', 'active']
 */
function db_permissions_get_for_role(PDO $pdo, string $role_nom): array {
    $stmt = $pdo->prepare(
        'SELECT p.id, p.cle_permission, p.description, p.categorie,
                CASE WHEN rp.role_nom IS NOT NULL THEN 1 ELSE 0 END AS active
         FROM permissions p
         LEFT JOIN role_permissions rp ON rp.permission_id = p.id AND rp.role_nom = :role
         ORDER BY p.categorie, p.cle_permission'
    );
    $stmt->execute([':role' => $role_nom]);
    return $stmt->fetchAll();
}

/**
 * Récupérer toutes les permissions groupées par catégorie.
 * Retourne ['categorie' => [['id' => ..., 'cle_permission' => ..., 'description' => ...]]]
 */
function db_permissions_all_grouped(PDO $pdo): array {
    $stmt = $pdo->query(
        'SELECT id, cle_permission, description, categorie
         FROM permissions ORDER BY categorie, cle_permission'
    );
    $perms = $stmt->fetchAll();
    $grouped = [];
    foreach ($perms as $p) {
        $grouped[$p['categorie']][] = $p;
    }
    return $grouped;
}

/**
 * Récupérer tous les rôles uniques (pour les colonnes du tableau matriciel).
 */
function db_permissions_get_roles(PDO $pdo): array {
    $stmt = $pdo->query(
        "SELECT DISTINCT role_nom FROM role_permissions ORDER BY FIELD(role_nom, 'Directeur', 'Admin', 'Magasinier', 'Vendeur')"
    );
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Récupérer toutes les permissions plates (liste simple).
 */
function db_permissions_all(PDO $pdo): array {
    $stmt = $pdo->query('SELECT id, cle_permission, description, categorie FROM permissions ORDER BY categorie, cle_permission');
    return $stmt->fetchAll();
}

/**
 * Récupérer les permissions d'un rôle sous forme de tableau de clés.
 * Ex: ['stock_consulter', 'stock_gerer', ...]
 */
function db_permissions_keys_for_role(PDO $pdo, string $role_nom): array {
    $stmt = $pdo->prepare(
        'SELECT p.cle_permission
         FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_nom = :role'
    );
    $stmt->execute([':role' => $role_nom]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Sauvegarder les permissions d'un rôle (remplacement complet).
 * Supprime toutes les anciennes associations puis insère les nouvelles.
 * Dans une transaction.
 */
function db_permissions_save_for_role(PDO $pdo, string $role_nom, array $permission_ids): void {
    $pdo->beginTransaction();
    try {
        // Supprimer les anciennes
        $stmt = $pdo->prepare('DELETE FROM role_permissions WHERE role_nom = :role');
        $stmt->execute([':role' => $role_nom]);
        
        // Insérer les nouvelles
        if (!empty($permission_ids)) {
            $stmt = $pdo->prepare(
                'INSERT INTO role_permissions (role_nom, permission_id) VALUES (:role, :pid)'
            );
            foreach ($permission_ids as $pid) {
                $stmt->execute([':role' => $role_nom, ':pid' => (int)$pid]);
            }
        }
        
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Vérifier si un rôle a une permission spécifique.
 */
function db_role_has_permission(PDO $pdo, string $role_nom, string $cle_permission): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_nom = :role AND p.cle_permission = :perm'
    );
    $stmt->execute([':role' => $role_nom, ':perm' => $cle_permission]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Compter le nombre total de permissions.
 */
function db_permissions_count(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
}

/**
 * Compter le nombre de permissions actives pour un rôle.
 */
function db_permissions_count_for_role(PDO $pdo, string $role_nom): int {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_nom = :role');
    $stmt->execute([':role' => $role_nom]);
    return (int)$stmt->fetchColumn();
}

// ============================================================
//  GESTION PAR LOTS (DLC/DDM)
// ============================================================

/**
 * Créer ou incrémenter un lot d'article.
 * Si le lot (numero_lot + article_id + magasin_id) existe déjà, incrémente la quantité.
 * Sinon, crée un nouveau lot.
 * Retourne l'ID du lot.
 */
function db_lot_upsert(PDO $pdo, int $article_id, int $magasin_id, string $numero_lot, 
                       int $quantite, ?string $date_peremption = null): int {
    if ($date_peremption !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO article_lots (article_id, magasin_id, numero_lot, quantite, date_peremption)
             VALUES (:aid, :mid, :lot, :qte, :dlc)
             ON DUPLICATE KEY UPDATE 
                 quantite = quantite + :qte,
                 date_peremption = :dlc'
        );
        $stmt->execute([':aid' => $article_id, ':mid' => $magasin_id, ':lot' => $numero_lot, ':qte' => $quantite, ':dlc' => $date_peremption]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO article_lots (article_id, magasin_id, numero_lot, quantite, date_peremption)
             VALUES (:aid, :mid, :lot, :qte, NULL)
             ON DUPLICATE KEY UPDATE 
                 quantite = quantite + :qte'
        );
        $stmt->execute([':aid' => $article_id, ':mid' => $magasin_id, ':lot' => $numero_lot, ':qte' => $quantite]);
    }

    $lotId = (int)$pdo->lastInsertId();
    if ($lotId > 0) {
        return $lotId;
    }

    $sel = $pdo->prepare('SELECT id FROM article_lots WHERE article_id = :aid AND magasin_id = :mid AND numero_lot = :lot');
    $sel->execute([':aid' => $article_id, ':mid' => $magasin_id, ':lot' => $numero_lot]);
    return (int)$sel->fetchColumn();
}

/**
 * Trouver le lot FEFO (First Expired, First Out) pour un article dans un magasin.
 * Retourne le lot dont la date de péremption est la plus proche et qui a du stock.
 * Les lots sans DLC sont retournés EN DERNIER (considérés comme péremption longue).
 * Retourne NULL si aucun lot disponible.
 */
function db_lot_get_fefo(PDO $pdo, int $article_id, int $magasin_id): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, numero_lot, quantite, date_peremption
         FROM article_lots
         WHERE article_id = :aid
           AND magasin_id = :mid
           AND quantite > 0
           AND (date_peremption IS NULL OR date_peremption >= CURDATE())
         ORDER BY 
             CASE WHEN date_peremption IS NULL THEN 1 ELSE 0 END,
             date_peremption ASC
         LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([':aid' => $article_id, ':mid' => $magasin_id]);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Décrémenter un lot spécifique.
 * Retourne true si la déduction a réussi, false si stock insuffisant.
 */
function db_lot_decrement(PDO $pdo, int $lot_id, int $quantite): bool {
    $stmt = $pdo->prepare(
        'UPDATE article_lots SET quantite = quantite - :qte
         WHERE id = :id AND quantite >= :qte'
    );
    $stmt->execute([':qte' => $quantite, ':id' => $lot_id]);
    return $stmt->rowCount() > 0;
}

/**
 * Décrémenter par FEFO : retire la quantité du lot le plus proche de la péremption.
 * Gère le cas où la quantité dépasse le lot (s'arrête sur le lot suivant).
 * Retourne un tableau des lots décrémentés (pour traçabilité).
 * Lance une RuntimeException si stock insuffisant.
 */
function db_lot_decrement_fefo(PDO $pdo, int $article_id, int $magasin_id, int $quantite): array {
    $lots_decrementes = [];
    $restant = $quantite;
    
    while ($restant > 0) {
        $lot = db_lot_get_fefo($pdo, $article_id, $magasin_id);
        if (!$lot) {
            throw new RuntimeException("Stock insuffisant par lots pour l'article $article_id (manque $restant unités)");
        }
        
        $a_prendre = min($restant, (int)$lot['quantite']);
        
        if (!db_lot_decrement($pdo, (int)$lot['id'], $a_prendre)) {
            throw new RuntimeException("Erreur de déduction du lot {$lot['numero_lot']}");
        }
        
        $lots_decrementes[] = [
            'lot_id'         => (int)$lot['id'],
            'numero_lot'     => $lot['numero_lot'],
            'quantite_prise' => $a_prendre,
            'date_peremption'=> $lot['date_peremption'],
        ];
        
        $restant -= $a_prendre;
    }
    
    return $lots_decrementes;
}

/**
 * Récupérer les lots dont la date de péremption approche.
 * Retourne les lots expirés + les lots expirant dans N jours.
 */
function db_get_expiring_lots(PDO $pdo, int $jours = 30, int $magasin_id = 0): array {
    $jours = max(1, (int)$jours);
    
    $params_expired = [];
    $where_expired = '';
    if ($magasin_id > 0) {
        $where_expired = 'AND al.magasin_id = :mid';
        $params_expired[':mid'] = $magasin_id;
    }
    $stmt_expired = $pdo->prepare("
        SELECT al.id, al.numero_lot, al.quantite, al.date_peremption,
               a.nom AS article_nom, a.code_barre, m.nom AS magasin_nom,
               DATEDIFF(CURDATE(), al.date_peremption) AS jours_depasse
        FROM article_lots al
        JOIN articles a ON a.id = al.article_id
        JOIN magasins m ON m.id = al.magasin_id
        WHERE al.quantite > 0
          AND al.date_peremption IS NOT NULL
          AND al.date_peremption < CURDATE()
          $where_expired
        ORDER BY al.date_peremption ASC
    ");
    $stmt_expired->execute($params_expired);
    $expired = $stmt_expired->fetchAll();
    
    $params_expiring = [':jours' => $jours];
    $where_expiring = '';
    if ($magasin_id > 0) {
        $where_expiring = 'AND al.magasin_id = :mid';
        $params_expiring[':mid'] = $magasin_id;
    }
    $stmt_expiring = $pdo->prepare("
        SELECT al.id, al.numero_lot, al.quantite, al.date_peremption,
               a.nom AS article_nom, a.code_barre, m.nom AS magasin_nom,
               DATEDIFF(al.date_peremption, CURDATE()) AS jours_restants
        FROM article_lots al
        JOIN articles a ON a.id = al.article_id
        JOIN magasins m ON m.id = al.magasin_id
        WHERE al.quantite > 0
          AND al.date_peremption IS NOT NULL
          AND al.date_peremption >= CURDATE()
          AND al.date_peremption <= DATE_ADD(CURDATE(), INTERVAL :jours DAY)
          $where_expiring
        ORDER BY al.date_peremption ASC
    ");
    $stmt_expiring->execute($params_expiring);
    $expiring = $stmt_expiring->fetchAll();
    
    return [
        'expired'  => $expired,
        'expiring' => $expiring,
        'count'    => count($expired ?? []) + count($expiring ?? []),
    ];
}

/**
 * Compter les alertes de péremption pour le badge de la topbar.
 */
function db_peremption_alert_count(PDO $pdo, int $jours = 30, int $magasin_id = 0): int {
    $jours = max(1, (int)$jours);
    $params = [':jours' => $jours];
    $where = '';
    if ($magasin_id > 0) {
        $where = 'AND al.magasin_id = :mid';
        $params[':mid'] = $magasin_id;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM article_lots al
        WHERE al.quantite > 0
          AND al.date_peremption IS NOT NULL
          AND al.date_peremption <= DATE_ADD(CURDATE(), INTERVAL :jours DAY)
          $where
    ");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Lister tous les lots d'un article dans un magasin.
 */
function db_lots_by_article(PDO $pdo, int $article_id, int $magasin_id): array {
    $stmt = $pdo->prepare(
        'SELECT id, numero_lot, quantite, date_peremption, date_reception
         FROM article_lots
         WHERE article_id = :aid AND magasin_id = :mid
         ORDER BY 
             CASE WHEN date_peremption IS NULL THEN 1 ELSE 0 END,
             date_peremption ASC'
    );
    $stmt->execute([':aid' => $article_id, ':mid' => $magasin_id]);
    return $stmt->fetchAll();
}

/**
 * Compter le total des lots avec du stock dans un magasin (pour statistiques).
 */
function db_lots_count(PDO $pdo, int $magasin_id = 0): int {
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM article_lots WHERE quantite > 0 AND magasin_id = :mid");
        $stmt->execute([':mid' => $magasin_id]);
        return (int)$stmt->fetchColumn();
    }
    return (int)$pdo->query("SELECT COUNT(*) FROM article_lots WHERE quantite > 0")->fetchColumn();
}

/**
 * Lister tous les lots avec infos article (pour tableau complet des péremptions).
 */
function db_lots_all_with_details(PDO $pdo, int $magasin_id = 0): array {
    $params = [];
    $where = '';
    if ($magasin_id > 0) {
        $where = 'AND al.magasin_id = :mid';
        $params[':mid'] = $magasin_id;
    }
    $stmt = $pdo->prepare("
        SELECT al.id, al.numero_lot, al.quantite, al.date_peremption, al.date_reception,
               a.nom AS article_nom, a.code_barre, a.prix_vente,
               m.nom AS magasin_nom,
               CASE 
                   WHEN al.date_peremption IS NULL THEN 'sans_dlc'
                   WHEN al.date_peremption < CURDATE() THEN 'expire'
                   WHEN al.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'urgent'
                   WHEN al.date_peremption <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'attention'
                   ELSE 'ok'
               END AS statut
        FROM article_lots al
        JOIN articles a ON a.id = al.article_id
        JOIN magasins m ON m.id = al.magasin_id
        WHERE al.quantite > 0
           $where
        ORDER BY 
            CASE 
                WHEN al.date_peremption IS NULL THEN 2
                WHEN al.date_peremption < CURDATE() THEN 0
                ELSE 1
            END,
            al.date_peremption ASC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ============================================================
//  VENTES FLASH — Moteur de remises automatiques
// ============================================================

/**
 * Calculer le prix d'un article en appliquant les promotions actives.
 * Vérifie le lot FEFO (péremption) et le niveau de stock.
 * 
 * @return array|null null si aucune promo, sinon :
 *   ['prix_remise' => float, 'prix_original' => float, 'remise_pct' => float, 'label' => string]
 */
function db_calculer_prix_article(PDO $pdo, int $article_id, int $magasin_id): ?array {
    // Règles actives, triées par priorité (la plus forte remise d'abord)
    $rules = $pdo->query("
        SELECT id, nom, condition_type, jours_limite, seuil_stock, pourcentage_remise
        FROM regles_promotions
        WHERE actif = 1
        ORDER BY pourcentage_remise DESC
    ")->fetchAll();

    if (empty($rules)) {
        return null;
    }

    // Récupérer le prix de vente de l'article et le stock pertinent :
    // stock du magasin si magasin défini, sinon stock global (rétro-compat)
    if ($magasin_id > 0) {
        $stmt = $pdo->prepare(
            "SELECT a.prix_vente, COALESCE(sm.quantite, 0) AS quantite_stock
             FROM articles a
             LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = :mag
             WHERE a.id = :id"
        );
        $stmt->execute([':id' => $article_id, ':mag' => $magasin_id]);
    } else {
        $stmt = $pdo->prepare("SELECT prix_vente, quantite_stock FROM articles WHERE id = :id");
        $stmt->execute([':id' => $article_id]);
    }
    $article = $stmt->fetch();
    if (!$article) {
        return null;
    }

    $prix_original = (float)$article['prix_vente'];
    $best_match = null;

    foreach ($rules as $rule) {
        $condition = $rule['condition_type'];

        if ($condition === 'PEREMPTION_PROCHE' && $magasin_id > 0) {
            // Vérifier le lot FEFO le plus proche de la péremption
            $lot = db_lot_get_fefo($pdo, $article_id, $magasin_id);
            if ($lot && !empty($lot['date_peremption'])) {
                $date_peremption = new DateTime((string)$lot['date_peremption']);
                $aujourd_hui = new DateTime('today');
                if ($date_peremption >= $aujourd_hui) {
                    $jours_restants = (int)$aujourd_hui->diff($date_peremption)->days;
                    $jours_limite = (int)$rule['jours_limite'];
                    if ($jours_restants <= $jours_limite) {
                        $pct = (float)$rule['pourcentage_remise'];
                        if ($best_match === null || $pct > $best_match['remise_pct']) {
                            $best_match = [
                                'prix_remise'   => round($prix_original * (1 - $pct / 100), 2),
                                'prix_original' => $prix_original,
                                'remise_pct'    => $pct,
                                'label'         => $rule['nom'],
                            ];
                        }
                    }
                }
            }
        }

        if ($condition === 'SURSTOCK') {
            $stock = (int)$article['quantite_stock'];
            $seuil = (int)$rule['seuil_stock'];
            if ($stock >= $seuil) {
                $pct = (float)$rule['pourcentage_remise'];
                if ($best_match === null || $pct > $best_match['remise_pct']) {
                    $best_match = [
                        'prix_remise'   => round($prix_original * (1 - $pct / 100), 2),
                        'prix_original' => $prix_original,
                        'remise_pct'    => $pct,
                        'label'         => $rule['nom'],
                    ];
                }
            }
        }
    }

    return $best_match;
}

/**
 * Lister toutes les règles de promotion.
 */
function db_promotions_get_all(PDO $pdo): array {
    return $pdo->query("
        SELECT id, nom, condition_type, jours_limite, seuil_stock, pourcentage_remise, actif, cree_le
        FROM regles_promotions
        ORDER BY condition_type ASC, pourcentage_remise DESC
    ")->fetchAll();
}

/**
 * Sauvegarder une règle de promotion (insert ou update).
 */
function db_promotion_save(PDO $pdo, array $data): int {
    $id = (int)($data['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE regles_promotions
            SET nom = :nom, condition_type = :type, jours_limite = :jl, seuil_stock = :ss,
                pourcentage_remise = :remise, actif = :actif
            WHERE id = :id
        ");
        $stmt->execute([
            ':nom'    => $data['nom'],
            ':type'   => $data['condition_type'],
            ':jl'     => $data['jours_limite'] ?? null,
            ':ss'     => $data['seuil_stock'] ?? null,
            ':remise' => $data['pourcentage_remise'],
            ':actif'  => (int)($data['actif'] ?? 1),
            ':id'     => $id,
        ]);
        return $id;
    }
    $stmt = $pdo->prepare("
        INSERT INTO regles_promotions (nom, condition_type, jours_limite, seuil_stock, pourcentage_remise, actif)
        VALUES (:nom, :type, :jl, :ss, :remise, :actif)
    ");
    $stmt->execute([
        ':nom'    => $data['nom'],
        ':type'   => $data['condition_type'],
        ':jl'     => $data['jours_limite'] ?? null,
        ':ss'     => $data['seuil_stock'] ?? null,
        ':remise' => $data['pourcentage_remise'],
        ':actif'  => (int)($data['actif'] ?? 1),
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Supprimer une règle de promotion.
 */
function db_promotion_delete(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("DELETE FROM regles_promotions WHERE id = :id");
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

// ============================================================
//  DÉDUCTION ATOMIQUE DE STOCK PAR LOT (FEFO)
// ============================================================

/**
 * Déduire le stock d'un article via la stratégie FEFO (First Expired, First Out).
 * 
 * Opérations atomiques (dans la transaction courante) :
 *   1. Sélectionner le lot FEFO actif le plus proche de la péremption
 *   2. Valider que le lot a suffisamment de quantité
 *   3. Décrémenter la quantité du lot (article_lots)
 *   4. Décrémenter le stock global du magasin (stock_magasins)
 *   5. Enregistrer la traçabilité (suivre_activite)
 * 
 * @param PDO    $pdo         Connexion PDO (transaction ouverte par l'appelant)
 * @param int    $article_id  ID de l'article
 * @param int    $magasin_id  ID du magasin
 * @param int    $quantite    Quantité à déduire (doit être > 0)
 * @param string $numero_facture Numéro de facture pour la traçabilité
 * @return array Informations sur les lots décrémentés
 * @throws RuntimeException Si stock insuffisant ou lot introuvable
 */
function db_deduire_stock_lot(PDO $pdo, int $article_id, int $magasin_id, int $quantite, string $numero_facture = ''): array {
    if ($quantite <= 0) {
        throw new RuntimeException("La quantité à déduire doit être supérieure à 0.");
    }
    if ($magasin_id <= 0) {
        throw new RuntimeException("L'ID du magasin doit être supérieur à 0 pour la déduction par lot.");
    }

    $lots_decrementes = [];
    $restant = $quantite;
    $article_nom = '';

    // Récupérer le nom de l'article pour la traçabilité
    $stmt_nom = $pdo->prepare("SELECT nom FROM articles WHERE id = :id");
    $stmt_nom->execute([':id' => $article_id]);
    $row_nom = $stmt_nom->fetch();
    $article_nom = $row_nom ? $row_nom['nom'] : "Article #$article_id";

    // Boucle FEFO : déduire du lot le plus proche de la péremption
    while ($restant > 0) {
        // 1. Trouver le lot FEFO actif (quantite > 0, non périmé)
        $lot = db_lot_get_fefo($pdo, $article_id, $magasin_id);
        if (!$lot) {
            throw new RuntimeException(
                "Stock insuffisant par lots pour « {$article_nom} » : "
                . "aucun lot actif disponible (manque {$restant} unité(s))."
            );
        }

        $lot_id = (int)$lot['id'];
        $lot_numero = $lot['numero_lot'];
        $lot_quantite = (int)$lot['quantite'];
        $a_prendre = min($restant, $lot_quantite);

        // 2. Décrémenter le lot (avec vérification stock insuffisant)
        $stmt_lot = $pdo->prepare(
            "UPDATE article_lots SET quantite = quantite - :qte
             WHERE id = :id AND quantite >= :qte2"
        );
        $stmt_lot->execute([':qte' => $a_prendre, ':id' => $lot_id, ':qte2' => $a_prendre]);
        if ($stmt_lot->rowCount() === 0) {
            throw new RuntimeException(
                "Échec de la déduction du lot {$lot_numero} : "
                . "stock insuffisant ({$lot_quantite} disponible(s), {$a_prendre} demandé(s))."
            );
        }

        // 3. Décrémenter le stock global du magasin
        $stmt_sm = $pdo->prepare(
            "UPDATE stock_magasins
             SET quantite = quantite - :qte
             WHERE magasin_id = :mag AND article_id = :art AND quantite >= :qte2"
        );
        $stmt_sm->execute([':qte' => $a_prendre, ':mag' => $magasin_id, ':art' => $article_id, ':qte2' => $a_prendre]);
        if ($stmt_sm->rowCount() === 0) {
            throw new RuntimeException(
                "Stock insuffisant dans le magasin pour « {$article_nom} » : "
                . "quantité magasin inférieure à la quantité demandée."
            );
        }

        // 3bis. Maintenir le stock global (articles.quantite_stock) synchronisé
        $pdo->prepare("UPDATE articles SET quantite_stock = GREATEST(0, quantite_stock - :qte) WHERE id = :art")
            ->execute([':qte' => $a_prendre, ':art' => $article_id]);

        // 4. Enregistrer la traçabilité
        $date_peremption = $lot['date_peremption'] ? " (DLC: {$lot['date_peremption']})" : '';
        $motif = "Vente {$numero_facture} : -{$a_prendre} unité(s) du lot {$lot_numero}{$date_peremption}";
        suivre_activite('SORTIE_STOCK_LOT', $motif);

        $lots_decrementes[] = [
            'lot_id'         => $lot_id,
            'numero_lot'     => $lot_numero,
            'quantite_prise' => $a_prendre,
            'date_peremption'=> $lot['date_peremption'],
        ];

        $restant -= $a_prendre;
    }

    return $lots_decrementes;
}

// ============================================================
//  PAIEMENTS MULTI-MODES
// ============================================================

/**
 * Enregistrer les paiements d'une facture (bulk insert).
 * $paiements = [['mode_paiement' => 'Especes', 'montant' => 5000, 'reference' => null], ...]
 */
function db_paiements_insert(PDO $pdo, int $facture_id, array $paiements): void {
    $stmt = $pdo->prepare("
        INSERT INTO paiements_facture (facture_id, mode_paiement, montant, reference)
        VALUES (?, ?, ?, ?)
    ");
    foreach ($paiements as $p) {
        if (!is_array($p)) {
            continue;
        }
        $mode = input_string($p['mode_paiement'] ?? 'Especes') ?: 'Especes';
        $montant_brut = input_string($p['montant'] ?? '');
        $montant = is_numeric($montant_brut) ? (float)$montant_brut : 0.0;
        $reference = input_string($p['reference'] ?? '') ?: null;
        if ($montant > 0) {
            $stmt->execute([$facture_id, $mode, $montant, $reference]);
        }
    }
}

/**
 * Récupérer les paiements d'une facture.
 */
function db_paiements_by_facture(PDO $pdo, int $facture_id): array {
    $stmt = $pdo->prepare("
        SELECT mode_paiement, montant, reference, date_paiement
        FROM paiements_facture
        WHERE facture_id = ?
        ORDER BY id
    ");
    $stmt->execute([$facture_id]);
    return $stmt->fetchAll();
}

/**
 * Total payé par mode pour une facture.
 */
function db_paiements_total_by_mode(PDO $pdo, int $facture_id): array {
    $stmt = $pdo->prepare("
        SELECT mode_paiement, SUM(montant) AS total
        FROM paiements_facture
        WHERE facture_id = ?
        GROUP BY mode_paiement
    ");
    $stmt->execute([$facture_id]);
    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['mode_paiement']] = (float)$row['total'];
    }
    return $result;
}

// ============================================================
//  COMMANDES FOURNISSEURS (Module 1)
// ============================================================

/**
 * Construit SQL + params pour la recherche de commandes fournisseurs (compatible avec paginate()).
 */
function db_commandes_fournisseur_search_sql(array $filters = []): array {
    $where = ["1=1"];
    $params = [];

    if (!empty($filters['statut'])) {
        $where[] = "cf.statut = ?";
        $params[] = $filters['statut'];
    }

    if (!empty($filters['fournisseur_id'])) {
        $where[] = "cf.fournisseur_id = ?";
        $params[] = (int)$filters['fournisseur_id'];
    }

    if (!empty($filters['magasin_id'])) {
        $where[] = "cf.magasin_id = ?";
        $params[] = (int)$filters['magasin_id'];
    }

    if (!empty($filters['date_debut'])) {
        $where[] = "DATE(cf.date_commande) >= ?";
        $params[] = $filters['date_debut'];
    }

    if (!empty($filters['date_fin'])) {
        $where[] = "DATE(cf.date_commande) <= ?";
        $params[] = $filters['date_fin'];
    }

    $where_sql = implode(' AND ', $where);

    $sql = "
        SELECT cf.*, f.nom AS fournisseur_nom, m.nom AS magasin_nom, u.nom AS utilisateur_nom,
               COUNT(lcf.id) AS nb_lignes,
               COALESCE(SUM(lcf.quantite_commandee * lcf.prix_achat_unitaire), 0) AS total_ht
        FROM commandes_fournisseur cf
        LEFT JOIN fournisseurs f ON f.id = cf.fournisseur_id
        LEFT JOIN magasins m ON m.id = cf.magasin_id
        LEFT JOIN utilisateurs u ON u.id = cf.utilisateur_id
        LEFT JOIN lignes_commande_fournisseur lcf ON lcf.commande_id = cf.id
        WHERE {$where_sql}
        GROUP BY cf.id
        ORDER BY cf.date_commande DESC, cf.id DESC
    ";

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Récupérer une commande fournisseur par son ID avec infos fournisseur, magasin, utilisateur.
 */
function db_commande_fournisseur_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT cf.*, f.nom AS fournisseur_nom, f.telephone AS fournisseur_telephone,
               m.nom AS magasin_nom, u.nom AS utilisateur_nom
        FROM commandes_fournisseur cf
        LEFT JOIN fournisseurs f ON f.id = cf.fournisseur_id
        LEFT JOIN magasins m ON m.id = cf.magasin_id
        LEFT JOIN utilisateurs u ON u.id = cf.utilisateur_id
        WHERE cf.id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Insérer une nouvelle commande fournisseur.
 */
function db_commande_fournisseur_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO commandes_fournisseur (fournisseur_id, magasin_id, utilisateur_id, statut, date_commande, date_reception_prevue, notes)
        VALUES (:four, :mag, :user, :statut, :date_cmd, :date_rec, :notes)
    ");
    $stmt->execute([
        ':four'     => $data['fournisseur_id'],
        ':mag'      => $data['magasin_id'],
        ':user'     => $data['utilisateur_id'],
        ':statut'   => $data['statut'] ?? 'Brouillon',
        ':date_cmd' => $data['date_commande'] ?? date('Y-m-d H:i:s'),
        ':date_rec' => !empty($data['date_reception_prevue']) ? $data['date_reception_prevue'] : null,
        ':notes'    => $data['notes'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Mettre à jour les informations d'une commande fournisseur (si statut Brouillon).
 */
function db_commande_fournisseur_update(PDO $pdo, int $id, array $data): void {
    $stmt = $pdo->prepare("
        UPDATE commandes_fournisseur SET
            fournisseur_id = :four,
            magasin_id = :mag,
            date_reception_prevue = :date_rec,
            notes = :notes
        WHERE id = :id AND statut = 'Brouillon'
    ");
    $stmt->execute([
        ':four'     => $data['fournisseur_id'],
        ':mag'      => $data['magasin_id'],
        ':date_rec' => !empty($data['date_reception_prevue']) ? $data['date_reception_prevue'] : null,
        ':notes'    => $data['notes'] ?? null,
        ':id'       => $id,
    ]);
}

/**
 * Changer le statut d'une commande fournisseur.
 */
function db_commande_fournisseur_update_statut(PDO $pdo, int $id, string $statut): void {
    $stmt = $pdo->prepare("UPDATE commandes_fournisseur SET statut = ? WHERE id = ?");
    $stmt->execute([$statut, $id]);
}

/**
 * Récupérer les lignes d'une commande fournisseur.
 */
function db_commande_fournisseur_lignes_get(PDO $pdo, int $commande_id): array {
    $stmt = $pdo->prepare("
        SELECT lcf.*, a.nom AS article_nom, a.code_barre, a.sku
        FROM lignes_commande_fournisseur lcf
        JOIN articles a ON a.id = lcf.article_id
        WHERE lcf.commande_id = ?
        ORDER BY lcf.id ASC
    ");
    $stmt->execute([$commande_id]);
    return $stmt->fetchAll();
}

/**
 * Enregistrer (remplacer) les lignes d'une commande fournisseur.
 * $lignes = [['article_id' => ..., 'quantite_commandee' => ..., 'prix_achat_unitaire' => ...], ...]
 */
function db_commande_fournisseur_lignes_save(PDO $pdo, int $commande_id, array $lignes): void {
    $pdo->prepare("DELETE FROM lignes_commande_fournisseur WHERE commande_id = ?")->execute([$commande_id]);

    if (empty($lignes)) return;

    $stmt = $pdo->prepare("
        INSERT INTO lignes_commande_fournisseur (commande_id, article_id, quantite_commandee, quantite_recue, prix_achat_unitaire)
        VALUES (?, ?, ?, ?, ?)
    ");
    foreach ($lignes as $l) {
        $article_id = (int)($l['article_id'] ?? 0);
        $qte_cmd    = (int)($l['quantite_commandee'] ?? 0);
        $qte_rec    = (int)($l['quantite_recue'] ?? 0);
        $pa         = (float)($l['prix_achat_unitaire'] ?? 0);
        if ($article_id > 0 && $qte_cmd > 0) {
            $stmt->execute([$commande_id, $article_id, $qte_cmd, $qte_rec, $pa]);
        }
    }
}

/**
 * Mettre à jour la quantité reçue pour une ligne spécifique de commande.
 */
function db_commande_fournisseur_lignes_update_reception(PDO $pdo, int $ligne_id, int $qte_recue_ajout): void {
    $stmt = $pdo->prepare("
        UPDATE lignes_commande_fournisseur 
        SET quantite_recue = quantite_recue + ? 
        WHERE id = ?
    ");
    $stmt->execute([$qte_recue_ajout, $ligne_id]);
}

// ============================================================
//  CATÉGORIES D'ARTICLES (Module 2)
// ============================================================

/**
 * Récupérer toutes les catégories d'articles actives.
 */
function db_categories_all(PDO $pdo, bool $only_active = true): array {
    $sql = "SELECT c.*, COUNT(a.id) AS nb_articles
            FROM categories c
            LEFT JOIN articles a ON a.categorie_id = c.id AND a.actif = 1";
    if ($only_active) {
        $sql .= " WHERE c.actif = 1";
    }
    $sql .= " GROUP BY c.id ORDER BY c.nom ASC";
    return $pdo->query($sql)->fetchAll();
}

/**
 * Récupérer une catégorie par son ID.
 */
function db_categorie_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Insérer une nouvelle catégorie d'articles.
 */
function db_categorie_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("INSERT INTO categories (nom, description, actif) VALUES (:nom, :desc, :actif)");
    $stmt->execute([
        ':nom'   => $data['nom'],
        ':desc'  => $data['description'] ?? null,
        ':actif' => isset($data['actif']) ? (int)$data['actif'] : 1,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Mettre à jour une catégorie d'articles.
 */
function db_categorie_update(PDO $pdo, int $id, array $data): void {
    $stmt = $pdo->prepare("UPDATE categories SET nom = :nom, description = :desc, actif = :actif WHERE id = :id");
    $stmt->execute([
        ':nom'   => $data['nom'],
        ':desc'  => $data['description'] ?? null,
        ':actif' => isset($data['actif']) ? (int)$data['actif'] : 1,
        ':id'    => $id,
    ]);
}

/**
 * Désactiver une catégorie d'articles (soft delete).
 */
function db_categorie_deactivate(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE categories SET actif = 0 WHERE id = ?")->execute([$id]);
}

// ============================================================
//  MODULE 4 — INVENTAIRE PHYSIQUE (COMPTAGE CYCLIQUE)
// ============================================================

/**
 * Créer une session d'inventaire et retourner son ID.
 */
function db_inventaire_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO inventaires (reference, magasin_id, utilisateur_id, notes)
        VALUES (:ref, :mag, :uid, :notes)
    ");
    $stmt->execute([
        ':ref'   => $data['reference'],
        ':mag'   => (int)($data['magasin_id'] ?? 1),
        ':uid'   => $data['utilisateur_id'] ?? null,
        ':notes' => $data['notes'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Récupérer une session d'inventaire par son ID.
 */
function db_inventaire_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT i.*, u.nom AS createur_nom, m.nom AS nom_magasin
        FROM inventaires i
        LEFT JOIN utilisateurs u ON u.id = i.utilisateur_id
        LEFT JOIN magasins m ON m.id = i.magasin_id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Construire SQL + params pour la liste paginée des inventaires.
 */
function db_inventaires_search_sql(array $filters = []): array {
    $where  = [];
    $params = [];
    $statut = $filters['statut'] ?? '';
    $magasin_id = (int)($filters['magasin_id'] ?? 0);
    $search = $filters['search'] ?? '';

    if ($statut !== '') {
        $where[] = 'i.statut = ?';
        $params[] = $statut;
    }
    if ($magasin_id > 0) {
        $where[] = 'i.magasin_id = ?';
        $params[] = $magasin_id;
    }
    if ($search !== '') {
        $where[] = '(i.reference LIKE ? OR u.nom LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql = "SELECT i.*, u.nom AS createur_nom, m.nom AS nom_magasin,
                   COUNT(il.id) AS nb_lignes
            FROM inventaires i
            LEFT JOIN utilisateurs u  ON u.id  = i.utilisateur_id
            LEFT JOIN magasins m      ON m.id  = i.magasin_id
            LEFT JOIN inventaire_lignes il ON il.inventaire_id = i.id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " GROUP BY i.id ORDER BY i.created_at DESC";
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Mettre à jour le statut d'une session d'inventaire.
 */
function db_inventaire_update_statut(PDO $pdo, int $id, string $statut): void {
    $allowed = ['En cours', 'Validé', 'Annulé'];
    if (!in_array($statut, $allowed, true)) return;
    $date_fin = in_array($statut, ['Validé', 'Annulé'], true) ? date('Y-m-d H:i:s') : null;
    $stmt = $pdo->prepare("UPDATE inventaires SET statut = ?, date_fin = ? WHERE id = ?");
    $stmt->execute([$statut, $date_fin, $id]);
}

/**
 * Récupérer toutes les lignes d'une session d'inventaire.
 */
function db_inventaire_lignes_get(PDO $pdo, int $inventaire_id): array {
    $stmt = $pdo->prepare("
        SELECT il.*, a.nom AS article_nom, a.code_barre, a.sku
        FROM inventaire_lignes il
        JOIN articles a ON a.id = il.article_id
        WHERE il.inventaire_id = ?
        ORDER BY a.nom
    ");
    $stmt->execute([$inventaire_id]);
    return $stmt->fetchAll();
}

/**
 * Ajouter ou mettre à jour la ligne de comptage pour un article dans une session.
 * Utilise INSERT … ON DUPLICATE KEY UPDATE pour l'idempotence.
 */
function db_inventaire_ligne_upsert(PDO $pdo, int $inventaire_id, int $article_id, int $qte_comptee, int $qte_theorique, ?string $notes = null): void {
    $ecart = $qte_comptee - $qte_theorique;
    $stmt = $pdo->prepare("
        INSERT INTO inventaire_lignes (inventaire_id, article_id, quantite_theorique, quantite_comptee, ecart, notes)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            quantite_theorique = VALUES(quantite_theorique),
            quantite_comptee   = VALUES(quantite_comptee),
            ecart              = VALUES(ecart),
            notes              = VALUES(notes)
    ");
    $stmt->execute([$inventaire_id, $article_id, $qte_theorique, $qte_comptee, $ecart, $notes]);
}

/**
 * Appliquer les écarts d'inventaire : met à jour le stock système selon
 * la quantité physiquement comptée, et enregistre un mouvement de type Ajustement.
 * Doit être appelé dans une transaction.
 */
function db_inventaire_appliquer_ecarts(PDO $pdo, int $inventaire_id, int $user_id): void {
    $lignes = db_inventaire_lignes_get($pdo, $inventaire_id);
    $inv = db_inventaire_get_by_id($pdo, $inventaire_id);
    $magasin_id = (int)($inv['magasin_id'] ?? 0);

    foreach ($lignes as $l) {
        $ecart = (int)$l['ecart'];
        if ($ecart === 0) continue;

        $article_id = (int)$l['article_id'];

        // Sans magasin défini : mise à jour directe du stock global uniquement.
        // Avec magasin, process_stock_movement() synchronise déjà le stock global (évite tout double décompte).
        if ($magasin_id <= 0) {
            $pdo->prepare("UPDATE articles SET quantite_stock = GREATEST(0, quantite_stock + :delta) WHERE id = :id")
                ->execute([':delta' => $ecart, ':id' => $article_id]);
        }

        // Maj du stock magasin + mouvement de traçabilité (Entree/Sortie)
        if ($magasin_id > 0) {
            $type  = $ecart > 0 ? 'Entree' : 'Sortie';
            $motif = 'Ajustement inventaire #' . $inventaire_id;
            if (function_exists('process_stock_movement')) {
                process_stock_movement($pdo, $article_id, $type, abs($ecart), $motif, $user_id, $magasin_id);
            }
        }
    }
}

// ============================================================
//  MODULE 5 — RETOURS & AVOIRS (SAV)
// ============================================================

/**
 * Insérer un retour de vente.
 */
function db_retour_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO retours_factures (numero_retour, facture_id, magasin_id, utilisateur_id, montant_total, motif)
        VALUES (:num, :fid, :mag, :uid, :montant, :motif)
    ");
    $stmt->execute([
        ':num'     => $data['numero_retour'],
        ':fid'     => (int)$data['facture_id'],
        ':mag'     => (int)($data['magasin_id'] ?? 1),
        ':uid'     => $data['utilisateur_id'] ?? null,
        ':montant' => (float)($data['montant_total'] ?? 0),
        ':motif'   => $data['motif'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Récupérer un retour par son ID.
 */
function db_retour_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT r.*, f.numero_facture, u.nom AS agent_nom, m.nom AS nom_magasin
        FROM retours_factures r
        JOIN factures f ON f.id = r.facture_id
        LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
        LEFT JOIN magasins m ON m.id = r.magasin_id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Récupérer les lignes d'un retour.
 */
function db_retour_lignes_get(PDO $pdo, int $retour_id): array {
    $stmt = $pdo->prepare("
        SELECT lr.*, a.nom AS article_nom, a.code_barre
        FROM lignes_retour lr
        JOIN articles a ON a.id = lr.article_id
        WHERE lr.retour_id = ?
        ORDER BY lr.id
    ");
    $stmt->execute([$retour_id]);
    return $stmt->fetchAll();
}

/**
 * Recherche paginée des retours.
 */
function db_retours_search_sql(array $filters = []): array {
    $where  = [];
    $params = [];
    $magasin_id = (int)($filters['magasin_id'] ?? 0);
    $search     = $filters['search'] ?? '';

    if ($magasin_id > 0) {
        $where[] = 'r.magasin_id = ?';
        $params[] = $magasin_id;
    }
    if ($search !== '') {
        $where[] = '(r.numero_retour LIKE ? OR f.numero_facture LIKE ? OR u.nom LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql = "SELECT r.*, f.numero_facture, u.nom AS agent_nom, m.nom AS nom_magasin,
                   COUNT(lr.id) AS nb_articles
            FROM retours_factures r
            JOIN factures f ON f.id = r.facture_id
            LEFT JOIN utilisateurs u ON u.id = r.utilisateur_id
            LEFT JOIN magasins m ON m.id = r.magasin_id
            LEFT JOIN lignes_retour lr ON lr.retour_id = r.id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " GROUP BY r.id ORDER BY r.created_at DESC";
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Créer un retour complet avec ré-incrémentation du stock.
 * Doit être exécuté dans une transaction.
 */
function db_retour_creer(PDO $pdo, int $facture_id, array $lignes_retour, string $motif, int $user_id, int $magasin_id): int {
    $num = generate_return_number($pdo);

    // Calculer le montant total du retour
    $montantTotal = 0.0;
    foreach ($lignes_retour as $l) {
        $montantTotal += round((float)$l['prix_unitaire'] * (int)$l['quantite'], 2);
    }

    $retourId = db_retour_insert($pdo, [
        'numero_retour'  => $num,
        'facture_id'     => $facture_id,
        'magasin_id'     => $magasin_id,
        'utilisateur_id' => $user_id,
        'montant_total'  => $montantTotal,
        'motif'          => $motif,
    ]);

    $stmtLigne = $pdo->prepare("
        INSERT INTO lignes_retour (retour_id, ligne_facture_id, article_id, quantite, prix_unitaire)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($lignes_retour as $l) {
        $stmtLigne->execute([
            $retourId,
            (int)$l['ligne_facture_id'],
            (int)$l['article_id'],
            (int)$l['quantite'],
            (float)$l['prix_unitaire'],
        ]);

        // Remettre en stock via process_stock_movement (Entrée / Retour SAV)
        if (function_exists('process_stock_movement')) {
            process_stock_movement(
                $pdo,
                (int)$l['article_id'],
                'Entree',
                (int)$l['quantite'],
                'Retour SAV #' . $num . ' (Facture #' . $facture_id . ')',
                $user_id,
                $magasin_id
            );
        }
    }

    return $retourId;
}

// ============================================================
//  MODULE 6 — REMISES ET PROMOTIONS (CODES PROMO & PROMOTIONS)
// ============================================================

/**
 * Insérer une promotion.
 */
function db_promotion_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO promotions (nom, code_promo, type_reduction, valeur, article_id, categorie_id, montant_min_achat, date_debut, date_fin, limite_utilisations, actif)
        VALUES (:nom, :code, :type, :valeur, :art_id, :cat_id, :min_achat, :debut, :fin, :limite, :actif)
    ");
    $stmt->execute([
        ':nom'       => $data['nom'],
        ':code'      => !empty($data['code_promo']) ? strtoupper(trim($data['code_promo'])) : null,
        ':type'      => in_array($data['type_reduction'] ?? '', ['pourcentage', 'montant_fixe'], true) ? $data['type_reduction'] : 'pourcentage',
        ':valeur'    => (float)($data['valeur'] ?? 0),
        ':art_id'    => !empty($data['article_id']) ? (int)$data['article_id'] : null,
        ':cat_id'    => !empty($data['categorie_id']) ? (int)$data['categorie_id'] : null,
        ':min_achat' => (float)($data['montant_min_achat'] ?? 0),
        ':debut'     => !empty($data['date_debut']) ? $data['date_debut'] : null,
        ':fin'       => !empty($data['date_fin']) ? $data['date_fin'] : null,
        ':limite'    => !empty($data['limite_utilisations']) ? (int)$data['limite_utilisations'] : null,
        ':actif'     => isset($data['actif']) ? (int)$data['actif'] : 1,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Mettre à jour une promotion.
 */
function db_promotion_update(PDO $pdo, int $id, array $data): void {
    $stmt = $pdo->prepare("
        UPDATE promotions SET
            nom = :nom, code_promo = :code, type_reduction = :type, valeur = :valeur,
            article_id = :art_id, categorie_id = :cat_id, montant_min_achat = :min_achat,
            date_debut = :debut, date_fin = :fin, limite_utilisations = :limite, actif = :actif
        WHERE id = :id
    ");
    $stmt->execute([
        ':nom'       => $data['nom'],
        ':code'      => !empty($data['code_promo']) ? strtoupper(trim($data['code_promo'])) : null,
        ':type'      => in_array($data['type_reduction'] ?? '', ['pourcentage', 'montant_fixe'], true) ? $data['type_reduction'] : 'pourcentage',
        ':valeur'    => (float)($data['valeur'] ?? 0),
        ':art_id'    => !empty($data['article_id']) ? (int)$data['article_id'] : null,
        ':cat_id'    => !empty($data['categorie_id']) ? (int)$data['categorie_id'] : null,
        ':min_achat' => (float)($data['montant_min_achat'] ?? 0),
        ':debut'     => !empty($data['date_debut']) ? $data['date_debut'] : null,
        ':fin'       => !empty($data['date_fin']) ? $data['date_fin'] : null,
        ':limite'    => !empty($data['limite_utilisations']) ? (int)$data['limite_utilisations'] : null,
        ':actif'     => isset($data['actif']) ? (int)$data['actif'] : 1,
        ':id'        => $id,
    ]);
}

/**
 * Récupérer une promotion par ID.
 */
function db_promotion_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT p.*, a.nom AS article_nom, c.nom AS categorie_nom
        FROM promotions p
        LEFT JOIN articles a ON a.id = p.article_id
        LEFT JOIN categories c ON c.id = p.categorie_id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Récupérer et valider un code promo actif.
 */
function db_promotion_get_by_code(PDO $pdo, string $code, float $total_achat = 0.0): ?array {
    $code = strtoupper(trim($code));
    if ($code === '') return null;

    $stmt = $pdo->prepare("
        SELECT p.*, a.nom AS article_nom
        FROM promotions p
        LEFT JOIN articles a ON a.id = p.article_id
        WHERE p.code_promo = :code
          AND p.actif = 1
          AND (p.date_debut IS NULL OR p.date_debut <= NOW())
          AND (p.date_fin IS NULL OR p.date_fin >= NOW())
          AND (p.limite_utilisations IS NULL OR p.nb_utilisations < p.limite_utilisations)
          AND (p.montant_min_achat <= :total)
    ");
    $stmt->execute([':code' => $code, ':total' => $total_achat]);
    return $stmt->fetch() ?: null;
}

/**
 * Incrémenter l'utilisation d'un code promo.
 */
function db_promotion_increment_usage(PDO $pdo, int $promo_id): void {
    $pdo->prepare("UPDATE promotions SET nb_utilisations = nb_utilisations + 1 WHERE id = ?")->execute([$promo_id]);
}

/**
 * Désactiver une promotion.
 */
function db_promotion_deactivate(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE promotions SET actif = 0 WHERE id = ?")->execute([$id]);
}

/**
 * Recherche paginée des promotions.
 */
function db_promotions_search_sql(array $filters = []): array {
    $where  = [];
    $params = [];
    $search = $filters['search'] ?? '';
    $actif  = $filters['actif'] ?? '';

    if ($search !== '') {
        $where[] = '(p.nom LIKE ? OR p.code_promo LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($actif !== '') {
        $where[] = 'p.actif = ?';
        $params[] = (int)$actif;
    }

    $sql = "SELECT p.*, a.nom AS article_nom, c.nom AS categorie_nom
            FROM promotions p
            LEFT JOIN articles a ON a.id = p.article_id
            LEFT JOIN categories c ON c.id = p.categorie_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= " ORDER BY p.created_at DESC";
    return ['sql' => $sql, 'params' => $params];
}

// ============================================================
//  INTÉGRITÉ — CHAÎNAGE CRYPTOGRAPHIQUE DES FACTURES
// ============================================================

/** Gènes de genèse de la chaîne (première facture / première clôture). */
const GENESIS_FACTURE_INTEGRITE = 'GENESIS-ESTOCK-INTEGRITE-V1';
const GENESIS_CLOTURE_INTEGRITE = 'GENESIS-ESTOCK-CLOTURES-INTEGRITE-V1';

/** Compatibilité : gènes historiques des bases chaînées avant août 2026. */
const GENESIS_FACTURE_INTEGRITE_LEGACY = 'GENESIS-ESTOCK-NF' . '525-V1';
const GENESIS_CLOTURE_INTEGRITE_LEGACY = 'GENESIS-ESTOCK-CLOTURES-NF' . '525-V1';

/** Vrai si la table sequences est disponible (numérotation atomique). */
function db_sequences_exists(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = (bool)$pdo->query("SHOW TABLES LIKE 'sequences'")->fetchColumn();
    return $cache;
}

/** Vrai si le chaînage cryptographique est actif (colonnes installées). */
function db_facture_chainage_actif(PDO $pdo): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = (bool)$pdo->query("SHOW COLUMNS FROM factures LIKE 'hash_chaine'")->fetchColumn();
    return $cache;
}

/**
 * Incrémenter atomiquement un compteur de numérotation (table sequences).
 *
 * Implémentation testée sous concurrence réelle (8 processus parallèles,
 * cf. tests/Integration/ConcurrenceNumerotationTest.php) : le seul schéma
 * sans perte de mise à jour sur MySQL 8 est
 * `INSERT … ON DUPLICATE KEY UPDATE valeur = LAST_INSERT_ID(valeur + 1)`
 * suivi de `SELECT LAST_INSERT_ID()` — le schéma UPDATE+SELECT classique
 * peut rendre un même rang deux fois en cas de ventes simultanées.
 *
 * @param string $cle     Clé unique du compteur (ex: numero_facture:2026-08-13)
 * @param int    $minimum Valeur minimale garantie (alignement sur les
 *                        factures créées avant l'installation de la table).
 */
function db_sequence_next(PDO $pdo, string $cle, int $minimum = 0): int {
    if ($minimum > 0) {
        // Alignement : le compteur devient au moins minimum-1 pour que le
        // premier numéro DÉLIVRÉ soit exactement minimum (pas de trou).
        $pdo->prepare("INSERT IGNORE INTO sequences (cle, valeur) VALUES (?, ?)")
            ->execute([$cle, $minimum - 1]);
        $pdo->prepare("UPDATE sequences SET valeur = ? WHERE cle = ? AND valeur < ?")
            ->execute([$minimum - 1, $cle, $minimum - 1]);
    }
    $pdo->prepare(
        "INSERT INTO sequences (cle, valeur) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE valeur = LAST_INSERT_ID(valeur + 1), date_maj = CURRENT_TIMESTAMP"
    )->execute([$cle]);
    return (int)$pdo->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

/**
 * Construire la chaîne canonique d'une facture (partie stable du hash).
 * Déterministe : doit produire exactement la même valeur à la création
 * et à la vérification.
 */
function db_facture_canonic(PDO $pdo, array $facture, array $lignes): string {
    $lignesJson = json_encode(array_map(static function (array $l) {
        return [
            'a'  => (int)$l['article_id'],
            'q'  => (int)$l['quantite'],
            'pu' => rtrim(rtrim(number_format((float)($l['prix_unitaire'] ?? 0), 2, '.', ''), '0'), '.'),
            't'  => ($l['taux_tva'] ?? '') === '' ? '' : rtrim(rtrim(number_format((float)$l['taux_tva'], 2, '.', ''), '0'), '.'),
        ];
    }, $lignes));

    return implode('|', [
        (string)$facture['numero_facture'],
        (string)$facture['date_facture'],
        rtrim(rtrim(number_format((float)$facture['total_ht'], 2, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format((float)$facture['tva_taux'], 2, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format((float)$facture['total_ttc'], 2, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format((float)$facture['montant_paye'], 2, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format((float)$facture['monnaie_rendue'], 2, '.', ''), '0'), '.'),
        (string)(int)($facture['magasin_id'] ?? 0),
        (string)(int)($facture['utilisateur_id'] ?? 0),
        (string)$facture['statut'],
        $lignesJson,
    ]);
}

/**
 * Hash de la dernière facture chaînée (prédécesseur).
 * Ne tient compte que des factures COMMITTÉES (hash non NULL), ce qui
 * garantit la continuité même en cas de ventes concurrentes.
 */
function db_facture_predecesseur_hash(PDO $pdo, int $facture_id): string {
    $st = $pdo->prepare("SELECT hash_chaine FROM factures WHERE hash_chaine IS NOT NULL AND id < ? ORDER BY id DESC LIMIT 1");
    $st->execute([$facture_id]);
    $hash = $st->fetchColumn();
    return $hash !== false && $hash !== null ? (string)$hash : GENESIS_FACTURE_INTEGRITE;
}

/**
 * Calculer le hash d'une facture (inclut le hash de la précédente).
 */
function db_facture_calculer_hash(PDO $pdo, array $facture, array $lignes, ?string $predecesseur = null): string {
    $prev = $predecesseur ?? db_facture_predecesseur_hash($pdo, (int)$facture['id']);
    return hash('sha256', db_facture_canonic($pdo, $facture, $lignes) . '|' . $prev);
}

/**
 * Finaliser le chaînage d'une facture venant d'être validée.
 * À appeler dans la transaction, APRÈS insertion des lignes et paiements.
 * Si une seule ligne de la chaîne est altérée, la suivante ne correspond plus.
 */
function db_facture_chainer(PDO $pdo, int $facture_id): void {
    if (!db_facture_chainage_actif($pdo)) return;
    $facture = db_facture_get_by_id($pdo, $facture_id);
    if (!$facture) return;
    $lignes = db_facture_get_lignes($pdo, $facture_id);
    $prev = db_facture_predecesseur_hash($pdo, $facture_id);
    $hash = db_facture_calculer_hash($pdo, $facture, $lignes, $prev);
    $certifie = gmdate('Y-m-d\TH:i:s\Z');
    $stmt = $pdo->prepare(
        "UPDATE factures SET hash_chaine = ?, hash_chaine_precedent = ?, horodatage_certifie = ? WHERE id = ?"
    );
    $stmt->execute([$hash, $prev, $certifie, $facture_id]);
}

/**
 * Rembourser les factures datant d'avant l'activation du chaînage
 * (hash NULL), dans l'ordre des ids. Idempotent.
 */
function db_facture_chainage_backfill(PDO $pdo, int $limite = 5000): int {
    $rows = $pdo->query("SELECT id FROM factures WHERE hash_chaine IS NULL ORDER BY id ASC LIMIT " . (int)$limite)->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $id) {
        db_facture_chainer($pdo, (int)$id);
    }
    return count($rows);
}

/**
 * Vérifier l'intégrité complète de la chaîne des factures.
 * Retourne ['ok' => bool, 'total' => int, 'verifiees' => int, 'manquantes' => int, 'erreurs' => [ids...]]
 */
function db_facture_chainage_verifier(PDO $pdo, int $limite = 50000): array {
    $factures = $pdo->query(
        "SELECT * FROM factures ORDER BY id ASC LIMIT " . (int)$limite
    )->fetchAll();

    $prev = null;
    $verifiees = 0;
    $manquantes = 0;
    $erreurs = [];

    foreach ($factures as $f) {
        if ($f['hash_chaine'] === null || $f['hash_chaine'] === '') {
            $manquantes++;
            continue;
        }
        $lignes = db_facture_get_lignes($pdo, (int)$f['id']);
        $genese = $prev ?? GENESIS_FACTURE_INTEGRITE;
        $attendu = db_facture_calculer_hash($pdo, $f, $lignes, $genese);
        if (!hash_equals($attendu, (string)$f['hash_chaine']) && $prev === null) {
            // Tolérance historique : première facture chaînée avec l'ancien gène
            $attendu = db_facture_calculer_hash($pdo, $f, $lignes, GENESIS_FACTURE_INTEGRITE_LEGACY);
        }
        if (!hash_equals($attendu, (string)$f['hash_chaine'])) {
            $erreurs[] = (int)$f['id'];
        } else {
            $verifiees++;
        }
        $prev = (string)$f['hash_chaine'];
    }

    return [
        'ok'          => empty($erreurs),
        'total'       => count($factures),
        'verifiees'   => $verifiees,
        'manquantes'  => $manquantes,
        'erreurs'     => $erreurs,
    ];
}

/**
 * Annulation comptable d'une facture (soft-delete).
 * Transition Payee -> Annulee avec date + motif (exigés par le trigger).
 * Aucun DELETE physique n'est possible (trigger d'inaltérabilité).
 */
function db_facture_annuler(PDO $pdo, int $facture_id, string $motif, int $par_utilisateur_id): void {
    $stmt = $pdo->prepare(
        "UPDATE factures
         SET statut = 'Annulee', date_annulation = NOW(), motif_annulation = ?, annulee_par = ?
         WHERE id = ? AND statut = 'Payee'"
    );
    $stmt->execute([mb_substr($motif, 0, 250), $par_utilisateur_id, $facture_id]);

    if ($stmt->rowCount() > 0) {
        // Fidélité : annulation des points gagnés + restitution des points utilisés
        db_points_reverser_facture($pdo, $facture_id, 'Annulation facture #' . $facture_id . ' : ' . mb_substr($motif, 0, 150));
    }
}

// ============================================================
//  INTÉGRITÉ — CHAÎNAGE DES CLÔTURES DE CAISSE
// ============================================================

/**
 * Empreinte du journal de caisse d'une journée : hash de l'ensemble
 * des factures (Payee) de la journée, par magasin + utilisateur.
 */
function db_cloture_journal_hash(PDO $pdo, int $magasin_id, int $utilisateur_id): string {
    $rows = $pdo->prepare(
        "SELECT id, hash_chaine FROM factures
         WHERE magasin_id = ? AND utilisateur_id = ?
           AND DATE(date_facture) = CURDATE() AND statut = 'Payee'
         ORDER BY id ASC"
    );
    $rows->execute([$magasin_id, $utilisateur_id]);
    $concats = [];
    foreach ($rows->fetchAll() as $r) {
        $concats[] = $r['id'] . ':' . ($r['hash_chaine'] ?? '-');
    }
    return hash('sha256', implode('|', $concats));
}

/** Hash de la dernière clôture chaînée (prédécesseur global). */
function db_cloture_predecesseur_hash(PDO $pdo, int $cloture_id): string {
    $st = $pdo->prepare("SELECT hash_chaine FROM clotures_caisse WHERE hash_chaine IS NOT NULL AND id < ? ORDER BY id DESC LIMIT 1");
    $st->execute([$cloture_id]);
    $hash = $st->fetchColumn();
    return $hash !== false && $hash !== null ? (string)$hash : GENESIS_CLOTURE_INTEGRITE;
}

/**
 * Finaliser le chaînage d'une clôture (dans la transaction de création).
 */
function db_cloture_chainer(PDO $pdo, int $cloture_id, int $magasin_id, int $utilisateur_id): void {
    $st = $pdo->prepare("SELECT * FROM clotures_caisse WHERE id = ?");
    $st->execute([$cloture_id]);
    $c = $st->fetch();
    if (!$c) return;

    $empreinte = db_cloture_journal_hash($pdo, $magasin_id, $utilisateur_id);
    $prev = db_cloture_predecesseur_hash($pdo, $cloture_id);
    $canonique = implode('|', [
        (string)$c['id'],
        (string)$c['magasin_id'],
        (string)$c['utilisateur_id'],
        (string)$c['date_cloture'],
        rtrim(rtrim(number_format((float)$c['montant_attendu'], 2, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format((float)$c['montant_reel'], 2, '.', ''), '0'), '.'),
        rtrim(rtrim(number_format((float)$c['ecart'], 2, '.', ''), '0'), '.'),
        $empreinte,
    ]);
    $hash = hash('sha256', $canonique . '|' . $prev);

    $upd = $pdo->prepare(
        "UPDATE clotures_caisse
         SET hash_chaine = ?, hash_chaine_precedent = ?, empreinte_journal = ?, horodatage_certifie = ?
         WHERE id = ?"
    );
    $upd->execute([$hash, $prev, $empreinte, gmdate('Y-m-d\TH:i:s\Z'), $cloture_id]);
}

/** Vérifier l'intégrité de la chaîne des clôtures. */
function db_cloture_chainage_verifier(PDO $pdo, int $limite = 2000): array {
    $rows = $pdo->query("SELECT * FROM clotures_caisse ORDER BY id ASC LIMIT " . (int)$limite)->fetchAll();
    $prev = null;
    $verifiees = 0;
    $manquantes = 0;
    $erreurs = [];

    foreach ($rows as $c) {
        if ($c['hash_chaine'] === null || $c['hash_chaine'] === '') {
            $manquantes++;
            continue;
        }
        $empreinte = db_cloture_journal_hash($pdo, (int)$c['magasin_id'], (int)$c['utilisateur_id']);
        $canonique = implode('|', [
            (string)$c['id'],
            (string)$c['magasin_id'],
            (string)$c['utilisateur_id'],
            (string)$c['date_cloture'],
            rtrim(rtrim(number_format((float)$c['montant_attendu'], 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format((float)$c['montant_reel'], 2, '.', ''), '0'), '.'),
            rtrim(rtrim(number_format((float)$c['ecart'], 2, '.', ''), '0'), '.'),
            $empreinte,
        ]);
        $genese = $prev ?? GENESIS_CLOTURE_INTEGRITE;
        $attendu = hash('sha256', $canonique . '|' . $genese);
        if (!hash_equals($attendu, (string)$c['hash_chaine']) && $prev === null) {
            // Tolérance historique : première clôture chaînée avec l'ancien gène
            $attendu = hash('sha256', $canonique . '|' . GENESIS_CLOTURE_INTEGRITE_LEGACY);
        }
        if (!hash_equals($attendu, (string)$c['hash_chaine'])) {
            $erreurs[] = (int)$c['id'];
        } else {
            $verifiees++;
        }
        $prev = (string)$c['hash_chaine'];
    }

    return [
        'ok'         => empty($erreurs),
        'total'      => count($rows),
        'verifiees'  => $verifiees,
        'manquantes' => $manquantes,
        'erreurs'    => $erreurs,
    ];
}

// ============================================================
//  ARCHIVAGE CAISSE (archives_caisse)
// ============================================================

/**
 * Données d'une période pour archivage (factures + paiements + totaux).
 */
function db_archives_periode_data(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT f.*, u.nom AS vendeur_nom
            FROM factures f
            LEFT JOIN utilisateurs u ON u.id = f.utilisateur_id
            WHERE DATE(f.date_facture) BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND f.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $sql .= " ORDER BY f.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $factures = $stmt->fetchAll();

    $nb_paiements = 0;
    $total_ht = 0.0;
    $total_tva = 0.0;
    $total_ttc = 0.0;
    $hash_sommet = '';

    foreach ($factures as $f) {
        if ($f['statut'] !== 'Payee') continue;
        $total_ht  += (float)$f['total_ht'];
        $total_tva += max(0.0, (float)$f['total_ttc'] - (float)$f['total_ht']);
        $total_ttc += (float)$f['total_ttc'];
        $hash_sommet = hash('sha256', $hash_sommet . '|' . ($f['hash_chaine'] ?? '-'));
    }
    if (!empty($factures)) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM paiements_facture pf JOIN factures f ON f.id = pf.facture_id WHERE DATE(f.date_facture) BETWEEN ? AND ? AND f.statut = 'Payee'" . ($magasin_id > 0 ? " AND f.magasin_id = ?" : ""));
        $params = [$debut, $fin];
        if ($magasin_id > 0) $params[] = $magasin_id;
        $st->execute($params);
        $nb_paiements = (int)$st->fetchColumn();
    }

    return [
        'factures'    => $factures,
        'nb_factures' => count($factures),
        'nb_paiements'=> $nb_paiements,
        'total_ht'    => $total_ht,
        'total_tva'   => $total_tva,
        'total_ttc'   => $total_ttc,
        'hash_sommet' => $hash_sommet,
    ];
}

/**
 * Enregistrer une archive en base (avec signature HMAC du sommet).
 */
function db_archive_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare(
        "INSERT INTO archives_caisse
         (magasin_id, periode_debut, periode_fin, nb_factures, nb_paiements, total_ht, total_tva, total_ttc, hash_sommet, signature, fichier_archive, conserve_jusqua, statut)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CONSERVE')"
    );
    $stmt->execute([
        $data['magasin_id'],
        $data['periode_debut'],
        $data['periode_fin'],
        $data['nb_factures'],
        $data['nb_paiements'],
        $data['total_ht'],
        $data['total_tva'],
        $data['total_ttc'],
        $data['hash_sommet'],
        $data['signature'],
        $data['fichier_archive'],
        $data['conserve_jusqua'],
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Lister les archives (recherche paginée).
 */
function db_archives_search_sql(array $filters = [], int $magasin_id = 0): array {
    $where = [];
    $params = [];
    if ($magasin_id > 0) {
        $where[] = "a.magasin_id = ?";
        $params[] = $magasin_id;
    }
    if (!empty($filters['debut'])) {
        $where[] = "a.periode_debut >= ?";
        $params[] = $filters['debut'];
    }
    if (!empty($filters['fin'])) {
        $where[] = "a.periode_fin <= ?";
        $params[] = $filters['fin'];
    }
    if (!empty($filters['statut']) && in_array($filters['statut'], ['CONSERVE', 'EXPURGE'], true)) {
        $where[] = "a.statut = ?";
        $params[] = $filters['statut'];
    }
    $sql = "SELECT a.*, m.nom AS magasin_nom FROM archives_caisse a LEFT JOIN magasins m ON m.id = a.magasin_id";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY a.periode_debut DESC, a.id DESC";
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Fichiers d'archives : récupérer une archive ou tester l'existence d'une
 * archive pour une période donnée.
 */
function db_archive_get_by_periode(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): ?array {
    $sql = "SELECT * FROM archives_caisse WHERE periode_debut = ? AND periode_fin = ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND magasin_id = ?";
        $params[] = $magasin_id;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetch() ?: null;
}

// ============================================================
//  JOURNAUX COMPTABLES (SYSCOHADA / OHADA)
//
//  Écritures comptables brutes issues de l'activité, destinées aux
//  exports comptables (plan SYSCOHADA révisé — OHADA) :
//  VT (ventes), AC (achats), DG (dépenses), INV (variation de stocks).
// ============================================================

/**
 * Écritures issues des ventes (factures Payee / Annulee de la période).
 * JournalCode = VT (ventes), CompteNum 411/7070/4457 + modes de paiement.
 */
function db_journal_ventes(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT f.*, u.nom AS vendeur_nom, cl.nom AS client_nom FROM factures f
            LEFT JOIN utilisateurs u ON u.id = f.utilisateur_id
            LEFT JOIN clients cl ON cl.id = f.client_id
            WHERE DATE(f.date_facture) BETWEEN ? AND ? AND f.statut IN ('Payee','Annulee')";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND f.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $sql .= " ORDER BY f.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $factures = $stmt->fetchAll();

    $ecritures = [];
    foreach ($factures as $f) {
        $lignes = db_facture_get_lignes($pdo, (int)$f['id']);
        $piece = 'FAC-' . (int)$f['id'];
        $dateE = substr((string)$f['date_facture'], 0, 10);
        $dateEnr = (string)$f['date_facture'];

        // TVA par taux (recalcul depuis les lignes quand disponible)
        $tvaTotale = max(0.0, (float)$f['total_ttc'] - (float)$f['total_ht']);
        $taux = (float)$f['tva_taux'];
        $baseHtTotale = (float)$f['total_ht'];

        // Journal VT — écriture de vente (débit client-caisse / crédit ventes + TVA)
        $modeComptes = [
            'Especes'       => '53',
            'Mobile_Money'  => '58',
            'Carte_Bancaire'=> '512',
            'Virement'      => '512',
            'Autre'         => '53',
        ];
        $compteCaisse = '53';
        $paiements = db_paiements_by_facture($pdo, (int)$f['id']);
        if (count($paiements) === 1) {
            $compteCaisse = $modeComptes[$paiements[0]['mode_paiement']] ?? '53';
        }

        $lib = 'Vente ' . $f['numero_facture'] . ($f['client_nom'] ? ' - ' . $f['client_nom'] : '');

        if ($f['statut'] === 'Annulee') {
            // Contre-passation : débit 707/4457, crédit caisse
            $ecritures[] = [
                'JournalCode' => 'VT', 'JournalLib' => 'Ventes',
                'EcritureNum' => (string)$f['id'], 'EcritureDate' => $dateEnr,
                'CompteNum' => $compteCaisse, 'CompteLib' => 'Caisse et banques',
                'PieceRef' => $piece, 'PieceDate' => $dateE,
                'EcritureLib' => 'Annulation ' . $f['numero_facture'],
                'MontantDebit' => '', 'MontantCredit' => number_format((float)$f['total_ttc'], 2, '.', ''),
            ];
            $ecritures[] = [
                'JournalCode' => 'VT', 'JournalLib' => 'Ventes',
                'EcritureNum' => (string)$f['id'], 'EcritureDate' => $dateEnr,
                'CompteNum' => '707', 'CompteLib' => 'Ventes de marchandises',
                'PieceRef' => $piece, 'PieceDate' => $dateE,
                'EcritureLib' => 'Annulation ' . $f['numero_facture'],
                'MontantDebit' => number_format($baseHtTotale, 2, '.', ''), 'MontantCredit' => '',
            ];
            if ($tvaTotale > 0) {
                $ecritures[] = [
                    'JournalCode' => 'VT', 'JournalLib' => 'Ventes',
                    'EcritureNum' => (string)$f['id'], 'EcritureDate' => $dateEnr,
                    'CompteNum' => '4457', 'CompteLib' => 'TVA collectee',
                    'PieceRef' => $piece, 'PieceDate' => $dateE,
                    'EcritureLib' => 'Annulation ' . $f['numero_facture'],
                    'MontantDebit' => number_format($tvaTotale, 2, '.', ''), 'MontantCredit' => '',
                ];
            }
            continue;
        }

        // Débit : caisse / banque / mobile money
        $ecritures[] = [
            'JournalCode' => 'VT', 'JournalLib' => 'Ventes',
            'EcritureNum' => (string)$f['id'], 'EcritureDate' => $dateEnr,
            'CompteNum' => $compteCaisse, 'CompteLib' => 'Caisse et banques',
            'PieceRef' => $piece, 'PieceDate' => $dateE,
            'EcritureLib' => $lib,
            'MontantDebit' => number_format((float)$f['total_ttc'], 2, '.', ''), 'MontantCredit' => '',
        ];
        // Crédit : ventes HT + TVA collectée
        $ecritures[] = [
            'JournalCode' => 'VT', 'JournalLib' => 'Ventes',
            'EcritureNum' => (string)$f['id'], 'EcritureDate' => $dateEnr,
            'CompteNum' => '707', 'CompteLib' => 'Ventes de marchandises',
            'PieceRef' => $piece, 'PieceDate' => $dateE,
            'EcritureLib' => $lib,
            'MontantDebit' => '', 'MontantCredit' => number_format($baseHtTotale, 2, '.', ''),
        ];
        if ($tvaTotale > 0) {
            $ecritures[] = [
                'JournalCode' => 'VT', 'JournalLib' => 'Ventes',
                'EcritureNum' => (string)$f['id'], 'EcritureDate' => $dateEnr,
                'CompteNum' => '4457', 'CompteLib' => 'TVA collectee',
                'PieceRef' => $piece, 'PieceDate' => $dateE,
                'EcritureLib' => $lib,
                'MontantDebit' => '', 'MontantCredit' => number_format($tvaTotale, 2, '.', ''),
            ];
        }
    }
    return $ecritures;
}

/**
 * Écritures issues des dépenses (JournalCode = DG).
 */
function db_journal_depenses(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT d.*, m.nom AS magasin_nom FROM depenses d
            LEFT JOIN magasins m ON m.id = d.magasin_id
            WHERE d.date_depense BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND d.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $sql .= " ORDER BY d.date_depense ASC, d.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $depenses = $stmt->fetchAll();

    $comptesChecks = [
        'Électricité'   => '606',
        'Eau'           => '606',
        'Loyer'         => '613',
        'Salaires'      => '641',
        'Transport'     => '624',
        'Fournitures'   => '606',
        'Telecom'       => '626',
        'Publicité'     => '623',
        'Maintenance'   => '615',
        'Assurance'     => '616',
        'Taxes'         => '635',
        'Interets'      => '661',
    ];
    $ecritures = [];
    foreach ($depenses as $d) {
        $compte = $comptesChecks[ucfirst(mb_strtolower((string)$d['categorie']))]
            ?? (str_starts_with((string)$d['categorie'], '6') ? (string)$d['categorie'] : '6');
        $dateD = substr((string)$d['date_depense'], 0, 10);
        $ecritures[] = [
            'JournalCode' => 'DG', 'JournalLib' => 'Depenses',
            'EcritureNum' => (string)$d['id'], 'EcritureDate' => (string)$d['date_depense'] . ' 00:00:00',
            'CompteNum' => $compte, 'CompteLib' => 'Charges',
            'PieceRef' => 'DEP-' . (int)$d['id'], 'PieceDate' => $dateD,
            'EcritureLib' => mb_substr((string)$d['titre'], 0, 200),
            'MontantDebit' => number_format((float)$d['montant'], 2, '.', ''), 'MontantCredit' => '',
        ];
        $ecritures[] = [
            'JournalCode' => 'DG', 'JournalLib' => 'Depenses',
            'EcritureNum' => (string)$d['id'], 'EcritureDate' => (string)$d['date_depense'] . ' 00:00:00',
            'CompteNum' => '401', 'CompteLib' => 'Fournisseurs',
            'PieceRef' => 'DEP-' . (int)$d['id'], 'PieceDate' => $dateD,
            'EcritureLib' => mb_substr((string)$d['titre'], 0, 200),
            'MontantDebit' => '', 'MontantCredit' => number_format((float)$d['montant'], 2, '.', ''),
        ];
    }
    return $ecritures;
}

/**
 * Écritures issues des commandes fournisseur reçues (JournalCode = AC).
 */
function db_journal_achats(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT c.*, f.nom AS fournisseur_nom FROM commandes_fournisseur c
            LEFT JOIN fournisseurs f ON f.id = c.fournisseur_id
            WHERE c.statut IN ('Recue','Recue_Partielle') AND DATE(c.date_commande) BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND c.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $sql .= " ORDER BY c.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $commandes = $stmt->fetchAll();

    $ecritures = [];
    foreach ($commandes as $c) {
        $lignes = db_commande_fournisseur_lignes_get($pdo, (int)$c['id']);
        $total = 0.0;
        foreach ($lignes as $l) {
            $total += round((float)($l['prix_achat_unitaire'] ?? 0) * (float)($l['quantite_recue'] ?? 0), 2);
        }
        $total = round($total * (float)($c['taux_change'] ?? 1), 2);
        if ($total <= 0) continue;
        $dateE = substr((string)$c['date_commande'], 0, 10);
        $ecritures[] = [
            'JournalCode' => 'AC', 'JournalLib' => 'Achats marchandises',
            'EcritureNum' => (string)$c['id'], 'EcritureDate' => (string)$c['date_commande'],
            'CompteNum' => '607', 'CompteLib' => 'Achats de marchandises',
            'PieceRef' => 'CMD-' . (int)$c['id'], 'PieceDate' => $dateE,
            'EcritureLib' => 'Reception commande ' . (string)$c['id'] . ' - ' . ($c['fournisseur_nom'] ?? ''),
            'MontantDebit' => number_format($total, 2, '.', ''), 'MontantCredit' => '',
        ];
        $ecritures[] = [
            'JournalCode' => 'AC', 'JournalLib' => 'Achats marchandises',
            'EcritureNum' => (string)$c['id'], 'EcritureDate' => (string)$c['date_commande'],
            'CompteNum' => '401', 'CompteLib' => 'Fournisseurs',
            'PieceRef' => 'CMD-' . (int)$c['id'], 'PieceDate' => $dateE,
            'EcritureLib' => 'Reception commande ' . (string)$c['id'] . ' - ' . ($c['fournisseur_nom'] ?? ''),
            'MontantDebit' => '', 'MontantCredit' => number_format($total, 2, '.', ''),
        ];
    }
    return $ecritures;
}

/**
 * Écritures de variation de stocks (JournalCode = INV) sur la période,
 * évaluées au CUMP (fallback prix_achat) — compte 31 (Stocks de
 * marchandises) en contrepartie du 6037 (Variation des stocks).
 * Une variation positive (entrées > sorties) est débitée au 31.
 */
function db_journal_stocks(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): array {
    $sql = "SELECT m.article_id, a.nom AS article_nom, a.code_barre,
                   COALESCE(NULLIF(a.cump, 0), a.prix_achat) AS cout_unitaire,
                   SUM(CASE WHEN m.type IN ('Entree','Retour_stock') THEN m.quantite ELSE 0 END) AS entrees,
                   SUM(CASE WHEN m.type IN ('Sortie','Vente','Transfert') THEN m.quantite ELSE 0 END) AS sorties
            FROM mouvements_stock m
            JOIN articles a ON a.id = m.article_id
            WHERE DATE(m.date_mouvement) BETWEEN ? AND ?";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND m.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $sql .= " GROUP BY m.article_id, a.nom, a.code_barre, a.cump, a.prix_achat
              HAVING (entrees - sorties) <> 0";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $articles = $stmt->fetchAll();

    $ecritures = [];
    $piece = 'INV-' . str_replace('-', '', $debut) . '_' . str_replace('-', '', $fin);
    $i = 0;
    foreach ($articles as $a) {
        $i++;
        $variation = (int)$a['entrees'] - (int)$a['sorties'];
        $montant = round(abs($variation) * (float)$a['cout_unitaire'], 2);
        if ($montant <= 0) continue;
        $lib = 'Variation de stock ' . ($a['article_nom'] ?? '') . ' (' . ($variation > 0 ? '+' : '') . $variation . ')';
        if ($variation > 0) {
            $ecritures[] = [
                'JournalCode' => 'INV', 'JournalLib' => 'Variation de stocks',
                'EcritureNum' => 'INV' . $i, 'EcritureDate' => $fin . ' 23:59:59',
                'CompteNum' => '31', 'CompteLib' => 'Stocks de marchandises',
                'PieceRef' => $piece, 'PieceDate' => $fin,
                'EcritureLib' => $lib,
                'MontantDebit' => number_format($montant, 2, '.', ''), 'MontantCredit' => '',
            ];
            $ecritures[] = [
                'JournalCode' => 'INV', 'JournalLib' => 'Variation de stocks',
                'EcritureNum' => 'INV' . $i, 'EcritureDate' => $fin . ' 23:59:59',
                'CompteNum' => '6037', 'CompteLib' => 'Variation des stocks de marchandises',
                'PieceRef' => $piece, 'PieceDate' => $fin,
                'EcritureLib' => $lib,
                'MontantDebit' => '', 'MontantCredit' => number_format($montant, 2, '.', ''),
            ];
        } else {
            $ecritures[] = [
                'JournalCode' => 'INV', 'JournalLib' => 'Variation de stocks',
                'EcritureNum' => 'INV' . $i, 'EcritureDate' => $fin . ' 23:59:59',
                'CompteNum' => '6037', 'CompteLib' => 'Variation des stocks de marchandises',
                'PieceRef' => $piece, 'PieceDate' => $fin,
                'EcritureLib' => $lib,
                'MontantDebit' => number_format($montant, 2, '.', ''), 'MontantCredit' => '',
            ];
            $ecritures[] = [
                'JournalCode' => 'INV', 'JournalLib' => 'Variation de stocks',
                'EcritureNum' => 'INV' . $i, 'EcritureDate' => $fin . ' 23:59:59',
                'CompteNum' => '31', 'CompteLib' => 'Stocks de marchandises',
                'PieceRef' => $piece, 'PieceDate' => $fin,
                'EcritureLib' => $lib,
                'MontantDebit' => '', 'MontantCredit' => number_format($montant, 2, '.', ''),
            ];
        }
    }
    return $ecritures;
}

// ============================================================
//  CLIENTS & FIDÉLITÉ (protection des données + programme de points)
// ============================================================

/** Insérer un client. Retourne l'ID. */
function db_client_insert(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare(
        "INSERT INTO clients (nom, raison_sociale, nif, rccm, adresse, telephone, email, date_naissance,
                              code_fidelite, consentement_fidelite, date_consentement, source_consentement)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $data['nom'] ?? null,
        $data['raison_sociale'] ?? null,
        $data['nif'] ?? null,
        $data['rccm'] ?? null,
        $data['adresse'] ?? null,
        $data['telephone'] ?? null,
        $data['email'] ?? null,
        $data['date_naissance'] ?? null,
        $data['code_fidelite'] ?? null,
        !empty($data['consentement_fidelite']) ? 1 : 0,
        !empty($data['consentement_fidelite']) ? date('Y-m-d H:i:s') : null,
        $data['source_consentement'] ?? 'caisse',
    ]);
    return (int)$pdo->lastInsertId();
}

/** Rechercher un client (par id, code fidélité, email ou tel). */
function db_client_get_by_id(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM clients WHERE id = ? AND anonymise = 0");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function db_client_get_by_code(PDO $pdo, string $code): ?array {
    $st = $pdo->prepare("SELECT * FROM clients WHERE code_fidelite = ? AND anonymise = 0 LIMIT 1");
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

function db_client_search(string $search): string {
    return '%' . $search . '%';
}

/** Recherche paginée des clients. */
function db_clients_search_sql(array $filters = [], int $magasin_id = 0): array {
    $where = ['c.anonymise = 0'];
    $params = [];
    $q = trim($filters['search'] ?? '');
    if ($q !== '') {
        $where[] = "(c.nom LIKE ? OR c.raison_sociale LIKE ? OR c.email LIKE ? OR c.telephone LIKE ? OR c.code_fidelite LIKE ?)";
        foreach (['%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%'] as $p) {
            $params[] = $p;
        }
    }
    $sql = "SELECT c.* FROM clients c";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY c.date_creation DESC";
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Mettre à jour les données d'un client + consentement du programme de fidélité.
 */
function db_client_update(PDO $pdo, int $id, array $data): void {
    $champs = ['nom', 'raison_sociale', 'nif', 'rccm', 'adresse', 'telephone', 'email', 'date_naissance', 'code_fidelite'];
    $sets = [];
    $params = [];
    foreach ($champs as $c) {
        if (array_key_exists($c, $data)) {
            $sets[] = "`$c` = ?";
            $params[] = $data[$c];
        }
    }
    if (array_key_exists('consentement_fidelite', $data)) {
        $consenti = !empty($data['consentement_fidelite']) ? 1 : 0;
        $sets[] = "consentement_fidelite = ?";
        $params[] = $consenti;
        if ($consenti) {
            $sets[] = "date_consentement = NOW()";
            $sets[] = "source_consentement = ?";
            $params[] = $data['source_consentement'] ?? 'caisse';
        }
    }
    if (empty($sets)) return;
    $sets[] = "id = ?";
    $params[] = $id;
    $pdo->prepare("UPDATE clients SET " . implode(', ', $sets))->execute($params);
}

/**
 * Anonymisation (protection des données clients) : les factures et
 * écritures comptables restent intactes (obligation de conservation),
 * les données personnelles sont remplacées par des placeholders
 * irréversibles.
 */
function db_client_anonymiser(PDO $pdo, int $id, ?string $motif = null): bool {
    $client = db_client_get_by_id($pdo, $id);
    if (!$client) return false;
    $tag = 'ANON-' . dechex(crc32($client['email'] ?? $client['nom'] ?? (string)$id));
    $st = $pdo->prepare(
        "UPDATE clients SET
            nom = ?, raison_sociale = NULL, nif = NULL, rccm = NULL, siret = NULL, adresse = NULL,
            telephone = NULL, email = NULL, date_naissance = NULL,
            code_fidelite = NULL, points_fidelite = 0,
            consentement_fidelite = 0, date_consentement = NULL,
            anonymise = 1, date_anonymisation = NOW()
         WHERE id = ?"
    );
    $st->execute([$tag, $id]);
    if ($motif !== null) {
        $log = "Anonymisation du client #$id ($tag) : " . mb_substr($motif, 0, 200);
    } else {
        $log = "Anonymisation du client #$id ($tag)";
    }
    suivre_activite('ANONYMISATION_CLIENT', $log);
    return true;
}

/**
 * Purge automatisée (politique de conservation) : anonymise les clients
 * sans achat depuis N mois et sans consentement actif ; supprime
 * physiquement les clients anonymisés de plus de M mois sans écritures.
 * Retourne ['anonymises' => n, 'supprimes' => n].
 */
function db_clients_purger(PDO $pdo, int $mois_sans_activite = 36, int $mois_anonyme_avant_suppression = 12): array {
    $result = ['anonymises' => 0, 'supprimes' => 0];

    $st = $pdo->prepare(
        "SELECT id FROM clients
         WHERE anonymise = 0 AND consentement_fidelite = 0
           AND (date_dernier_achat IS NULL OR date_dernier_achat < DATE_SUB(NOW(), INTERVAL ? MONTH))
         LIMIT 500"
    );
    $st->execute([$mois_sans_activite]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        db_client_anonymiser($pdo, (int)$id, 'Purge automatique (conservation ' . $mois_sans_activite . ' mois)');
        $result['anonymises']++;
    }

    // Suppression physique DES CLIENTS (jamais des factures) :
    // uniquement ceux déjà anonymisés et sans lien vers une facture.
    $st = $pdo->prepare(
        "SELECT c.id FROM clients c
         LEFT JOIN factures f ON f.client_id = c.id
         WHERE c.anonymise = 1
           AND c.date_anonymisation < DATE_SUB(NOW(), INTERVAL ? MONTH)
           AND f.id IS NULL
         LIMIT 200"
    );
    $st->execute([$mois_anonyme_avant_suppression]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare("DELETE FROM consentements_log WHERE client_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM historique_points WHERE client_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
        $result['supprimes']++;
    }
    return $result;
}

/**
 * Attribution de points à la validation d'une facture (fidélité).
 * Idempotent grâce à la contrainte UNIQUE uk_hp_facture.
 */
function db_client_attribuer_points(PDO $pdo, int $client_id, int $facture_id, float $montant_ttc): int {
    $actif = (int)param('fidelite_actif', '0');
    if (!$actif || $client_id <= 0 || $facture_id <= 0) return 0;

    $par = (float)param('fidelite_points_par_devise', '100');
    if ($par <= 0) return 0;
    $points = (int)floor($montant_ttc / $par);

    if ($points > 0) {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "INSERT INTO historique_points (client_id, facture_id, points, type_operation, commentaire, utilisateur_id)
                 VALUES (?, ?, ?, 'GAIN', 'Points fidélité - facture', ?)
                 ON DUPLICATE KEY UPDATE points = points"
            );
            $st->execute([$client_id, $facture_id, $points, user_id()]);
            $pdo->prepare("UPDATE clients SET points_fidelite = points_fidelite + ?, date_dernier_achat = NOW() WHERE id = ?")
                ->execute([$points, $client_id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Attribution points fidélité: ' . $e->getMessage());
            return 0;
        }
    } else {
        $pdo->prepare("UPDATE clients SET date_dernier_achat = NOW() WHERE id = ?")->execute([$client_id]);
    }
    return $points;
}

/**
 * Utilisation de points en caisse : retourne la remise max applicable
 * (points convertis en devise) et consomme les points en transaction.
 */
function db_client_utiliser_points(PDO $pdo, int $client_id, int $points_a_utiliser): float {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ? FOR UPDATE");
    $stmt->execute([$client_id]);
    $client = $stmt->fetch();
    if (!$client) return 0.0;
    $min = (int)param('fidelite_min_points_usage', '10');
    $valeur_point = (float)param('fidelite_valeur_point', '1');
    $dispo = (int)$client['points_fidelite'];
    $usage = min($dispo, max(0, $points_a_utiliser));
    if ($usage < $min) return 0.0;

    $remise = round(round($usage * $valeur_point, 2) / 100, 2);
    if ($usage > 0) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE clients SET points_fidelite = points_fidelite - ? WHERE id = ?")
                ->execute([$usage, $client_id]);
            $pdo->prepare(
                "INSERT INTO historique_points (client_id, facture_id, points, type_operation, commentaire, utilisateur_id)
                 VALUES (?, NULL, ?, 'UTILISATION', 'Utilisation points en caisse', ?)"
            )->execute([$client_id, -$usage, user_id()]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Utilisation points fidélité: ' . $e->getMessage());
            return 0.0;
        }
    }
    return $remise;
}

/** Historique des points d'un client. */
function db_client_points_historique(PDO $pdo, int $client_id, int $limit = 50): array {
    $st = $pdo->prepare(
        "SELECT hp.*, f.numero_facture FROM historique_points hp
         LEFT JOIN factures f ON f.id = hp.facture_id
         WHERE hp.client_id = ?
         ORDER BY hp.date_operation DESC, hp.id DESC LIMIT ?"
    );
    $st->bindValue(1, $client_id, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/** Consigner un consentement (traçabilité). */
function db_consentement_log(PDO $pdo, int $client_id, string $type, bool $consenti, ?string $ip = null): void {
    $st = $pdo->prepare(
        "INSERT INTO consentements_log (client_id, type_consentement, consenti, ip_source, utilisateur_id)
         VALUES (?, ?, ?, ?, ?)"
    );
    $st->execute([$client_id, $type, $consenti ? 1 : 0, $ip ?: ($_SERVER['REMOTE_ADDR'] ?? null), user_id()]);
}

/**
 * Points gagnés sur une facture (ligne GAIN, idempotente).
 */
function db_points_gagnes_facture(PDO $pdo, int $facture_id): int {
    $st = $pdo->prepare(
        "SELECT points FROM historique_points WHERE facture_id = ? AND type_operation = 'GAIN'"
    );
    $st->execute([$facture_id]);
    return (int)($st->fetchColumn() ?: 0);
}

/**
 * Inverser les points d'une facture (annulation ou retour SAV).
 *
 *  - Retire les points gagnés (proportionnellement au montant retourné).
 *  - Restitue les points utilisés comme remise (factures.points_utilises).
 *  - Trace UNE ligne 'ANNULE' cumulée par facture (clé unique composite),
 *    mise à jour en upsert : idempotent et cumulatif en cas de retours partiels.
 *  - Le solde client est toujours borné à 0 (jamais négatif).
 *
 * À appeler DANS une transaction. Ne fait rien si la facture n'a pas de client.
 */
function db_points_reverser_facture(PDO $pdo, int $facture_id, string $motif, float $fraction = 1.0): void {
    $st = $pdo->prepare("SELECT client_id, points_utilises FROM factures WHERE id = ?");
    $st->execute([$facture_id]);
    $f = $st->fetch();
    if (!$f || (int)$f['client_id'] <= 0) {
        return;
    }
    $client_id = (int)$f['client_id'];
    $fraction = max(0.0, min(1.0, $fraction));

    $gains_reverses = (int)round(db_points_gagnes_facture($pdo, $facture_id) * $fraction);
    $restituer = (int)$f['points_utilises'];
    if ($fraction < 1.0) {
        // Retour partiel : on ne restitue les points utilisés que si le retour
        // couvre la totalité de la facture (cohérence avec le gain partiel).
        $restituer = 0;
    }
    if ($gains_reverses <= 0 && $restituer <= 0) {
        return;
    }

    // Valeur cumulée enregistrée actuellement pour cette facture
    $st = $pdo->prepare(
        "SELECT points FROM historique_points WHERE client_id = ? AND facture_id = ? AND type_operation = 'ANNULE'"
    );
    $st->execute([$client_id, $facture_id]);
    $avant = (int)($st->fetchColumn() ?: 0);

    $nouveau = $avant - $gains_reverses + $restituer; // cumul (négatif = prélèvement)

    $pdo->prepare(
        "INSERT INTO historique_points (client_id, facture_id, points, type_operation, commentaire, utilisateur_id)
         VALUES (?, ?, ?, 'ANNULE', ?, ?)
         ON DUPLICATE KEY UPDATE points = VALUES(points)"
    )->execute([$client_id, $facture_id, $nouveau, mb_substr($motif, 0, 250), user_id()]);

    $delta = $nouveau - $avant; // variation de solde à appliquer au client
    if ($delta !== 0) {
        $pdo->prepare("UPDATE clients SET points_fidelite = GREATEST(0, points_fidelite + ?) WHERE id = ?")
            ->execute([$delta, $client_id]);
    }
}

/**
 * Ajustement manuel du solde de points (bonus, correction, geste commercial).
 * Refuse si le solde deviendrait négatif (retrait plafonné au solde courant).
 */
function db_client_ajuster_points(PDO $pdo, int $client_id, int $delta, string $motif): bool {
    $client = db_client_get_by_id($pdo, $client_id);
    if (!$client) return false;
    $delta = (int)$delta;
    $motif = mb_substr(trim($motif), 0, 250);
    if ($delta === 0) return true;

    $solde_actuel = (int)$client['points_fidelite'];
    if ($delta < 0 && abs($delta) > $solde_actuel) {
        $delta = -$solde_actuel;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE clients SET points_fidelite = points_fidelite + ? WHERE id = ?")
            ->execute([$delta, $client_id]);
        $pdo->prepare(
            "INSERT INTO historique_points (client_id, facture_id, points, type_operation, commentaire, utilisateur_id)
             VALUES (?, NULL, ?, 'AJUSTEMENT', ?, ?)"
        )->execute([$client_id, $delta, $motif !== '' ? $motif : 'Ajustement manuel du solde', user_id()]);
        $pdo->commit();
        suivre_activite('POINTS_AJUSTES', 'Ajustement ' . ($delta > 0 ? '+' : '') . $delta . ' pts — client #' . $client_id . ' : ' . $motif);
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Ajustement points fidélité: ' . $e->getMessage());
        return false;
    }
}

// ============================================================
//  VALORISATION DU STOCK (CUMP / FIFO)
// ============================================================

/**
 * Mettre à jour le CUMP d'un article après une entrée en stock.
 * Formule : nouveau CUMP = (CUMP actuel × quantité existante + coût × quantité entrée)
 *                            / (quantité totale).
 */
function db_article_cump_entree(PDO $pdo, int $article_id, int $quantite_entree, float $cout_unitaire, ?string $reference = null, int $magasin_id = 1): void {
    $quantite_entree = max(0, (int)$quantite_entree);
    if ($quantite_entree <= 0) return;

    $st = $pdo->prepare("SELECT quantite_stock, cump FROM articles WHERE id = ? FOR UPDATE");
    $st->execute([$article_id]);
    $art = $st->fetch();
    if (!$art) return;

    $qte_avant = (int)$art['quantite_stock'];
    $cump_avant = (float)$art['cump'];
    $valeur_avant = $qte_avant * $cump_avant;
    $valeur_entree = $quantite_entree * $cout_unitaire;
    $qte_apres = $qte_avant + $quantite_entree;
    $cump_apres = $qte_apres > 0 ? ($valeur_avant + $valeur_entree) / $qte_apres : $cout_unitaire;

    $upd = $pdo->prepare("UPDATE articles SET cump = ?, valeur_stock = ? WHERE id = ?");
    $upd->execute([round($cump_apres, 4), round($qte_apres * $cump_apres, 2), $article_id]);

    // Couche de coût FIFO (par magasin)
    if ($magasin_id > 0) {
        $pdo->prepare(
            "INSERT INTO article_couts (article_id, magasin_id, quantite, cout_unitaire, reference)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$article_id, $magasin_id, $quantite_entree, $cout_unitaire, $reference]);

        // Valeur comptable par magasin
        $pdo->prepare(
            "UPDATE stock_magasins SET valeur_stock = COALESCE(valeur_stock, 0) + ?
             WHERE article_id = ? AND magasin_id = ?"
        )->execute([round($quantite_entree * $cout_unitaire, 2), $article_id, $magasin_id]);
    }
}

/**
 * Coût de sortie de stock selon la méthode configurée :
 *   - CUMP : articles.cump (par défaut)
 *   - FIFO : coût des couches les plus anciennes (consommation FIFO)
 */
function db_article_cout_sortie(PDO $pdo, int $article_id, int $quantite): float {
    $methode = param('valorisation_methode', 'CUMP');
    if ($methode === 'FIFO') {
        $st = $pdo->prepare(
            "SELECT cout_unitaire FROM article_couts
             WHERE article_id = ? AND quantite > 0
             ORDER BY date_entree ASC, id ASC
             LIMIT 1"
        );
        $st->execute([$article_id]);
        $cout = $st->fetchColumn();
        return $cout !== false ? round((float)$cout * max(0, $quantite), 2) : 0.0;
    }
    $st = $pdo->prepare("SELECT cump FROM articles WHERE id = ?");
    $st->execute([$article_id]);
    $cump = (float)$st->fetchColumn();
    return round($cump * max(0, $quantite), 2);
}

/**
 * Consommation FIFO des couches de coût lors d'une sortie (met à jour
 * article_couts.quantite). À appeler dans la transaction de vente.
 */
function db_article_couts_consommer(PDO $pdo, int $article_id, int $quantite): void {
    if ($quantite <= 0) return;
    $couches = $pdo->prepare(
        "SELECT id, quantite FROM article_couts
         WHERE article_id = ? AND quantite > 0
         ORDER BY date_entree ASC, id ASC"
    );
    $couches->execute([$article_id]);
    $reste = $quantite;
    foreach ($couches->fetchAll() as $couche) {
        if ($reste <= 0) break;
        $prise = min($reste, (int)$couche['quantite']);
        $pdo->prepare("UPDATE article_couts SET quantite = quantite - ? WHERE id = ?")
            ->execute([$prise, (int)$couche['id']]);
        $reste -= $prise;
    }
}

/**
 * Coût des achats consommés sur une période (marge réelle) :
 * somme des coûts de sortie des ventes de la période.
 */
function db_stats_cout_reel_ventes(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): float {
    $sql = "SELECT lf.article_id, lf.quantite, a.cump, lf.facture_id, f.id AS fid
            FROM lignes_facture lf
            JOIN factures f ON f.id = lf.facture_id
            JOIN articles a ON a.id = lf.article_id
            WHERE DATE(f.date_facture) BETWEEN ? AND ? AND f.statut = 'Payee'";
    $params = [$debut, $fin];
    if ($magasin_id > 0) {
        $sql .= " AND f.magasin_id = ?";
        $params[] = $magasin_id;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $cout = 0.0;
    foreach ($st->fetchAll() as $l) {
        $cout += (float)$l['cump'] * (int)$l['quantite'];
    }
    return round($cout, 2);
}



