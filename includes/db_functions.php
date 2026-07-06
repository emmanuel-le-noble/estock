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
        INSERT INTO articles (code_barre, nom, sku, prix_achat, prix_vente, quantite_stock, seuil_alerte, emplacement, fournisseur_id)
        VALUES (:code, :nom, :sku, :pa, :pv, :qte, :seuil, :emp, :four)
    ");
    $stmt->execute([
        ':code' => $data['code_barre'],
        ':nom'  => $data['nom'],
        ':sku'  => $data['sku'],
        ':pa'   => $data['prix_achat'],
        ':pv'   => $data['prix_vente'],
        ':qte'  => $data['quantite_stock'],
        ':seuil'=> $data['seuil_alerte'],
        ':emp'  => $data['emplacement'],
        ':four' => $data['fournisseur_id'],
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
            emplacement=:emp, fournisseur_id=:four
        WHERE id=:id
    ");
    $stmt->execute([
        ':code' => $data['code_barre'],
        ':nom'  => $data['nom'],
        ':sku'  => $data['sku'],
        ':pa'   => $data['prix_achat'],
        ':pv'   => $data['prix_vente'],
        ':qte'  => $data['quantite_stock'],
        ':seuil'=> $data['seuil_alerte'],
        ':emp'  => $data['emplacement'],
        ':four' => $data['fournisseur_id'],
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
    $stmt = $pdo->prepare("SELECT * FROM articles WHERE id = ?");
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
            SELECT a.id, a.code_barre, a.nom, a.sku, a.prix_vente,
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
            SELECT id, code_barre, nom, sku, prix_vente, quantite_stock, seuil_alerte
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
    $stmt = $pdo->prepare("SELECT id, nom, quantite_stock, prix_vente FROM articles WHERE id = ? FOR UPDATE");
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
            SELECT COALESCE(sm.quantite, 0) AS quantite_stock, a.nom
            FROM articles a
            LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = ?
            WHERE a.id = ? FOR UPDATE
        ");
        $stmt->execute([$magasin_id, $id]);
    } else {
        $stmt = $pdo->prepare("SELECT quantite_stock, nom FROM articles WHERE id = ? FOR UPDATE");
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
    $stmt = $pdo->prepare("SELECT id, nom, code_barre FROM articles WHERE actif=1 ORDER BY nom LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}

/**
 * Rechercher des articles avec filtres dynamiques (stock consultation).
 */
function db_articles_search_stock(PDO $pdo, string $search = '', int $limit = 200, int $magasin_id = 0): array {
    $limit = max(1, min(1000, (int)$limit));
    if ($magasin_id > 0) {
        $sql = "
            SELECT a.code_barre, a.nom, a.sku, a.prix_vente,
                   COALESCE(sm.quantite, 0) AS quantite_stock,
                   COALESCE(sm.stock_alerte, a.seuil_alerte) AS seuil_alerte,
                   a.emplacement
            FROM articles a
            LEFT JOIN stock_magasins sm ON sm.article_id = a.id AND sm.magasin_id = ?
            WHERE a.actif = 1
        ";
        $params = [$magasin_id];
    } else {
        $sql = "SELECT a.code_barre, a.nom, a.sku, a.prix_vente, a.quantite_stock, a.seuil_alerte, a.emplacement FROM articles a WHERE a.actif = 1";
        $params = [];
    }
    if ($search !== '') {
        $sql .= " AND (a.nom LIKE ? OR a.code_barre LIKE ? OR a.sku LIKE ? OR a.emplacement LIKE ?) ";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    $sql .= " ORDER BY a.nom LIMIT ?";
    $params[] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Construit SQL + params pour la recherche d'articles (compatible paginate()).
 */
function db_articles_search_sql(string $search = ''): array {
    $sql = "SELECT a.*, f.nom AS fournisseur_nom
        FROM articles a
        LEFT JOIN fournisseurs f ON f.id = a.fournisseur_id
        WHERE a.actif = 1
    ";
    $params = [];
    if ($search !== '') {
        $sql .= " AND (a.nom LIKE ? OR a.code_barre LIKE ? OR a.sku LIKE ?) ";
        $params = ["%$search%", "%$search%", "%$search%"];
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
            LIMIT ?
        ");
        $stmt->execute([$magasin_id, $limit]);
        return $stmt->fetchAll();
    }
    $stmt = $pdo->prepare("
        SELECT code_barre, nom, quantite_stock, seuil_alerte, emplacement
        FROM articles
        WHERE actif = 1 AND quantite_stock <= seuil_alerte
        ORDER BY (quantite_stock - seuil_alerte) ASC, nom ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
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
        $stmt->execute([':mid' => $magasin_id, ':limit' => $limit]);
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
        LIMIT ?
    ");
    $stmt->execute([$limit]);
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
        LIMIT ?
    ");
    $stmt->execute([$limit]);
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
function db_user_insert(PDO $pdo, string $nom, string $login, string $mdp_hash, string $role, int $actif): int {
    $stmt = $pdo->prepare("
        INSERT INTO utilisateurs (nom, login, mot_de_passe, role, actif)
        VALUES (:nom, :login, :mdp, :role, :actif)
    ");
    $stmt->execute([
        ':nom'   => $nom,
        ':login' => $login,
        ':mdp'   => $mdp_hash,
        ':role'  => $role,
        ':actif' => $actif,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Mettre à jour un utilisateur (avec mot de passe).
 */
function db_user_update_with_password(PDO $pdo, int $id, string $nom, string $login, string $role, int $actif, string $mdp_hash): void {
    $stmt = $pdo->prepare("
        UPDATE utilisateurs
        SET nom=:nom, login=:login, role=:role, actif=:actif, mot_de_passe=:mdp
        WHERE id=:id
    ");
    $stmt->execute([
        ':nom'   => $nom,
        ':login' => $login,
        ':role'  => $role,
        ':actif' => $actif,
        ':mdp'   => $mdp_hash,
        ':id'    => $id,
    ]);
}

/**
 * Mettre à jour un utilisateur (sans mot de passe).
 */
function db_user_update_without_password(PDO $pdo, int $id, string $nom, string $login, string $role, int $actif): void {
    $stmt = $pdo->prepare("
        UPDATE utilisateurs
        SET nom=:nom, login=:login, role=:role, actif=:actif
        WHERE id=:id
    ");
    $stmt->execute([
        ':nom'   => $nom,
        ':login' => $login,
        ':role'  => $role,
        ':actif' => $actif,
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
    $stmt = $pdo->prepare("SELECT id, nom, login, role, actif FROM utilisateurs WHERE id = ?");
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
    $stmt = $pdo->prepare("SELECT id, nom, login, role, actif, date_creation FROM utilisateurs ORDER BY nom LIMIT ?");
    $stmt->execute([$limit]);
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
    $columns = 'numero_facture, utilisateur_id, total_ht, tva_taux, total_ttc, montant_paye, monnaie_rendue, statut';
    $placeholders = '?, ?, ?, ?, ?, ?, ?, \'Payee\'';
    if ($has_magasin) {
        $columns .= ', magasin_id';
        $placeholders .= ', ?';
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
    $stmt->execute($params);
    return (int)$pdo->lastInsertId();
}

/**
 * Récupérer une facture par ID avec le nom du vendeur.
 */
function db_facture_get_by_id(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("SELECT f.*, u.nom AS vendeur_nom
        FROM factures f
        LEFT JOIN utilisateurs u ON u.id = f.utilisateur_id
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
function db_factures_search_sql(array $filters = []): array {
    $where  = [];
    $params = [];
    $search = $filters['search'] ?? '';
    $statut = $filters['statut'] ?? '';
    $debut  = $filters['debut'] ?? '';
    $fin    = $filters['fin'] ?? '';
    $utilisateur_id = (int)($filters['utilisateur_id'] ?? 0);

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
function db_stats_period(PDO $pdo, string $debut, string $fin): array {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS nb_ventes, COALESCE(SUM(total_ttc), 0) AS ca_total
        FROM factures
        WHERE DATE(date_facture) BETWEEN ? AND ? AND statut = 'Payee'");
    $stmt->execute([$debut, $fin]);
    return $stmt->fetch() ?: ['nb_ventes' => 0, 'ca_total' => 0];
}

/**
 * Top N articles les plus vendus sur une période.
 */
function db_stats_top_articles(PDO $pdo, string $debut, string $fin, int $limit = 5): array {
    $limit = max(1, min(100, (int)$limit));
    $stmt = $pdo->prepare("SELECT a.nom, a.code_barre, SUM(lf.quantite) AS total_qte,
            SUM(lf.quantite * lf.prix_unitaire) AS total_montant
        FROM lignes_facture lf
        JOIN articles a ON a.id = lf.article_id
        JOIN factures f ON f.id = lf.facture_id
        WHERE DATE(f.date_facture) BETWEEN ? AND ? AND f.statut = 'Payee'
        GROUP BY a.id, a.nom, a.code_barre
        ORDER BY total_qte DESC
        LIMIT ?");
    $stmt->execute([$debut, $fin, $limit]);
    return $stmt->fetchAll();
}

/**
 * Ventes jour par jour sur une période (pour graphique courbe).
 */
function db_stats_daily_sales(PDO $pdo, string $debut, string $fin): array {
    $stmt = $pdo->prepare("SELECT DATE(f.date_facture) AS jour, COUNT(*) AS nb_ventes,
            SUM(f.total_ttc) AS ca_jour
        FROM factures f
        WHERE DATE(f.date_facture) BETWEEN ? AND ? AND f.statut = 'Payee'
        GROUP BY DATE(f.date_facture)
        ORDER BY jour ASC");
    $stmt->execute([$debut, $fin]);
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
function db_ligne_facture_insert(PDO $pdo, int $facture_id, int $article_id, int $quantite, float $prix_unitaire, ?float $prix_original = null, ?float $remise_pct = null): void {
    $stmt = $pdo->prepare("
        INSERT INTO lignes_facture (facture_id, article_id, quantite, prix_unitaire, prix_original, remise_pct)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$facture_id, $article_id, $quantite, $prix_unitaire, $prix_original, $remise_pct]);
}

// ============================================================
//  MOUVEMENTS DE STOCK
// ============================================================

/**
 * Insérer un mouvement de stock.
 */
function db_mouvement_insert(PDO $pdo, int $article_id, ?int $user_id, string $type, int $quantite, ?string $motif = null, int $magasin_id = 0): void {
    $pdo->prepare("
        INSERT INTO mouvements_stock (article_id, utilisateur_id, type, quantite, motif, magasin_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$article_id, $user_id, $type, $quantite, $motif, $magasin_id > 0 ? $magasin_id : null]);
}

/**
 * Derniers mouvements (dashboard).
 */
function db_mouvements_recent(PDO $pdo, int $limit = 8): array {
    $limit = max(1, min(100, (int)$limit));
    $stmt = $pdo->prepare("
        SELECT m.*, a.nom AS article_nom, u.nom AS user_nom
        FROM mouvements_stock m
        JOIN articles a ON a.id = m.article_id
        LEFT JOIN utilisateurs u ON u.id = m.utilisateur_id
        ORDER BY m.date_mouvement DESC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
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
    if (in_array($f_type, ['Entree', 'Sortie', 'Vente'], true)) {
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
        // Table absente — on ignore
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
        // Table absente — on ignore
    }
}

// ============================================================
//  LOGS D'ACTIVITE (audit trail)
// ============================================================

/**
 * Construit SQL + params pour la recherche de logs d'activité (compatible paginate()).
 */
function db_logs_activite_search_sql(array $filters = []): array {
    $where  = [];
    $params = [];
    $search = $filters['search'] ?? '';
    $action = $filters['action'] ?? '';
    $debut  = $filters['debut'] ?? '';
    $fin    = $filters['fin'] ?? '';

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
    $stmt = $pdo->prepare("SELECT id, nom, adresse, code_postal FROM magasins WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/**
 * Lister tous les magasins (actifs + inactifs), triés par statut puis nom.
 */
function db_magasins_list_all(PDO $pdo): array {
    return $pdo->query("SELECT id, nom, adresse, code_postal, actif, date_creation FROM magasins ORDER BY actif DESC, nom")->fetchAll();
}

/**
 * Créer un nouveau magasin et initialiser le stock à 0 pour tous les articles actifs.
 */
function db_magasin_insert(PDO $pdo, string $nom, ?string $adresse, ?string $code_postal): int {
    $stmt = $pdo->prepare(
        "INSERT INTO magasins (nom, adresse, code_postal) VALUES (:nom, :adresse, :cp)"
    );
    $stmt->execute([
        ':nom'     => $nom,
        ':adresse' => $adresse !== '' ? $adresse : null,
        ':cp'      => $code_postal !== '' ? $code_postal : null,
    ]);
    $magasin_id = (int) $pdo->lastInsertId();

    // Initialiser le stock à 0 pour tous les articles actifs
    db_stock_magasin_init_for_articleBulk($pdo, $magasin_id);

    return $magasin_id;
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
    $params = [];
    if ($magasin_id > 0) {
        $sql .= " WHERE t.magasin_source_id = :mid OR t.magasin_destination_id = :mid2";
        $params[':mid']  = $magasin_id;
        $params[':mid2'] = $magasin_id;
    }
    $sql .= " ORDER BY t.date_transfert DESC LIMIT ?";
    $params[] = $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Initialiser le stock d'un article dans tous les magasins actifs (appelé à la création d'article).
 */
function db_stock_magasin_init_for_article(PDO $pdo, int $article_id, int $quantite = 0, int $alerte = 5): void {
    $magasins = $pdo->query("SELECT id FROM magasins WHERE actif = 1")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($magasins as $mid) {
        db_stock_magasin_upsert($pdo, (int)$mid, $article_id, $quantite, $alerte);
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
 * Formule : Σ(lignes_facture.quantite × articles.prix_achat)
 */
function db_stats_cout_achat_ventes(PDO $pdo, string $debut, string $fin, int $magasin_id = 0): float {
    $sql = "SELECT COALESCE(SUM(lf.quantite * a.prix_achat), 0)
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
    $stats     = db_stats_period($pdo, $debut, $fin);
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
 * Récupérer la dernière clôture d'un magasin.
 */
function db_cloture_get_derniere(PDO $pdo, int $magasin_id): ?array {
    $stmt = $pdo->prepare(
        "SELECT c.*, u.nom AS vendeur_nom
         FROM clotures_caisse c
         LEFT JOIN utilisateurs u ON u.id = c.utilisateur_id
         WHERE c.magasin_id = ? AND c.statut = 'VALIDE'
         ORDER BY c.date_cloture DESC, c.id DESC
         LIMIT 1"
    );
    $stmt->execute([$magasin_id]);
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
    // Vérifier si le lot existe déjà
    $stmt = $pdo->prepare(
        'SELECT id FROM article_lots WHERE article_id = :aid AND magasin_id = :mid AND numero_lot = :lot'
    );
    $stmt->execute([':aid' => $article_id, ':mid' => $magasin_id, ':lot' => $numero_lot]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Incrémenter la quantité
        $update = $pdo->prepare(
            'UPDATE article_lots SET quantite = quantite + :qte WHERE id = :id'
        );
        $update->execute([':qte' => $quantite, ':id' => $existing['id']]);
        return (int)$existing['id'];
    } else {
        // Créer un nouveau lot
        $insert = $pdo->prepare(
            'INSERT INTO article_lots (article_id, magasin_id, numero_lot, quantite, date_peremption)
             VALUES (:aid, :mid, :lot, :qte, :dlc)'
        );
        $insert->execute([
            ':aid' => $article_id,
            ':mid' => $magasin_id,
            ':lot' => $numero_lot,
            ':qte' => $quantite,
            ':dlc' => $date_peremption,
        ]);
        return (int)$pdo->lastInsertId();
    }
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
         LIMIT 1'
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

    // Récupérer le prix de vente de l'article
    $stmt = $pdo->prepare("SELECT prix_vente, quantite_stock FROM articles WHERE id = :id");
    $stmt->execute([':id' => $article_id]);
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
        $mode = $p['mode_paiement'] ?? 'Especes';
        $montant = (float)($p['montant'] ?? 0);
        $reference = !empty($p['reference']) ? $p['reference'] : null;
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
