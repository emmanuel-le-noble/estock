<?php
/**
 * parametres.php - Configuration globale de la boutique + Gestion des droits RBAC.
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN);

// Déterminer l'onglet actif
$tab_courant = $_GET['tab'] ?? 'config';

$sections = [
    'boutique' => [
        'titre' => 'Boutique / magasin',
        'icone' => 'bi-shop',
        'champs' => [
            ['cle'=>'nom_boutique', 'label'=>'Nom de la boutique', 'type'=>'text', 'defaut'=>'eStock Market', 'required'=>true],
            ['cle'=>'slogan_boutique', 'label'=>'Slogan', 'type'=>'text', 'defaut'=>'Gérez votre stock et votre caisse. Simplement.'],
            ['cle'=>'adresse_boutique', 'label'=>'Adresse', 'type'=>'textarea', 'defaut'=>''],
            ['cle'=>'code_postal', 'label'=>'Code postal / ville', 'type'=>'text', 'defaut'=>''],
            ['cle'=>'pays', 'label'=>'Pays', 'type'=>'text', 'defaut'=>'Togo'],
            ['cle'=>'telephone_boutique', 'label'=>'Téléphone', 'type'=>'tel', 'defaut'=>''],
            ['cle'=>'email_boutique', 'label'=>'E-mail', 'type'=>'email', 'defaut'=>''],
            ['cle'=>'site_boutique', 'label'=>'Site web', 'type'=>'text', 'defaut'=>''],
            ['cle'=>'nif_boutique', 'label'=>'NIF (Numéro d\'Identification Fiscale — OTR)', 'type'=>'text', 'defaut'=>''],
            ['cle'=>'rccm_boutique', 'label'=>'RCCM (immatriculation au registre du commerce)', 'type'=>'text', 'defaut'=>''],
        ],
    ],
    'financier' => [
        'titre' => 'Devise, régime fiscal & taxes',
        'icone' => 'bi-cash-coin',
        'champs' => [
            ['cle'=>'regime_fiscal', 'label'=>'Régime fiscal', 'type'=>'select', 'defaut'=>'TPU', 'options'=>['TPU'=>'TPU — Taxe Professionnelle Unique (factures hors taxes)', 'TVA'=>'TVA — régime classique (18 %)']],
            ['cle'=>'tva_taux_defaut', 'label'=>'Taux de TVA (%)', 'type'=>'number', 'defaut'=>'18.00', 'min'=>0, 'max'=>100, 'step'=>'0.01'],
            ['cle'=>'tva_active', 'label'=>'TVA active (articles assujettis)', 'type'=>'checkbox', 'defaut'=>'1'],
            ['cle'=>'facture_prefixe', 'label'=>'Préfixe des factures (ex : FAC-20260814-0001)', 'type'=>'text', 'defaut'=>'FAC', 'maxlength'=>10],
            ['cle'=>'devise_nom', 'label'=>'Nom de la devise', 'type'=>'text', 'defaut'=>'Franc CFA'],
            ['cle'=>'devise_code', 'label'=>'Code devise', 'type'=>'text', 'defaut'=>'XOF', 'maxlength'=>3],
            ['cle'=>'devise_symbole', 'label'=>'Symbole affiché', 'type'=>'text', 'defaut'=>'FCFA'],
            ['cle'=>'devise_position', 'label'=>'Position du symbole', 'type'=>'select', 'defaut'=>'apres', 'options'=>['apres'=>'Après le montant', 'avant'=>'Avant le montant']],
            ['cle'=>'devise_decimales', 'label'=>'Décimales', 'type'=>'number', 'defaut'=>'2', 'min'=>0, 'max'=>4, 'step'=>'1'],
            ['cle'=>'separateur_decimal', 'label'=>'Séparateur décimal', 'type'=>'text', 'defaut'=>',', 'maxlength'=>2],
            ['cle'=>'separateur_milliers', 'label'=>'Séparateur milliers', 'type'=>'text', 'defaut'=>' ', 'maxlength'=>2],
            ['cle'=>'paiement_rapide_1', 'label'=>'Montant rapide 1', 'type'=>'number', 'defaut'=>'20.00', 'min'=>0, 'step'=>'0.01'],
            ['cle'=>'paiement_rapide_2', 'label'=>'Montant rapide 2', 'type'=>'number', 'defaut'=>'50.00', 'min'=>0, 'step'=>'0.01'],
            ['cle'=>'paiement_rapide_3', 'label'=>'Montant rapide 3', 'type'=>'number', 'defaut'=>'100.00', 'min'=>0, 'step'=>'0.01'],
        ],
    ],
    'ticket' => [
        'titre' => 'Ticket de caisse',
        'icone' => 'bi-receipt',
        'champs' => [
            ['cle'=>'ticket_entete', 'label'=>'Message de fin', 'type'=>'text', 'defaut'=>'Merci de votre visite !'],
            ['cle'=>'ticket_remarque', 'label'=>'Remarque', 'type'=>'textarea', 'defaut'=>''],
            ['cle'=>'ticket_format', 'label'=>'Format papier', 'type'=>'select', 'defaut'=>'80mm', 'options'=>['80mm'=>'80 mm', '58mm'=>'58 mm']],
        ],
    ],
    'fidelite' => [
        'titre' => 'Programme de fidélité',
        'icone' => 'bi-stars',
        'champs' => [
            ['cle'=>'fidelite_actif', 'label'=>'Activer le programme de fidélité', 'type'=>'checkbox', 'defaut'=>'0'],
            ['cle'=>'fidelite_points_par_devise', 'label'=>'Devise dépensée pour gagner 1 point (ex : 100 = 1 pt / 100 FCFA)', 'type'=>'number', 'defaut'=>'100', 'min'=>1, 'step'=>'1'],
            ['cle'=>'fidelite_valeur_point', 'label'=>'Valeur d\'un point (1 point = montant × 100)', 'type'=>'number', 'defaut'=>'1', 'min'=>1, 'step'=>'1'],
            ['cle'=>'fidelite_min_points_usage', 'label'=>'Seuil minimal de points pour une remise', 'type'=>'number', 'defaut'=>'10', 'min'=>1, 'step'=>'1'],
        ],
    ],
    'conformite' => [
        'titre' => 'Traçabilité & conformité',
        'icone' => 'bi-shield-check',
        'champs' => [
            ['cle'=>'archives_conservation_annees', 'label'=>'Conservation des archives de caisse (années)', 'type'=>'number', 'defaut'=>'6', 'min'=>1, 'step'=>'1'],
            ['cle'=>'retention_clients_mois', 'label'=>'Rétention clients sans activité avant anonymisation (mois)', 'type'=>'number', 'defaut'=>'36', 'min'=>1, 'step'=>'1'],
        ],
    ],
    'apparence' => [
        'titre' => 'Application',
        'icone' => 'bi-window-sidebar',
        'champs' => [
            ['cle'=>'app_nom', 'label'=>'Nom court dans le menu', 'type'=>'text', 'defaut'=>'eStock'],
            ['cle'=>'langue', 'label'=>'Langue', 'type'=>'select', 'defaut'=>'fr', 'options'=>['fr'=>'Français', 'en'=>'English', 'es'=>'Español', 'ar'=>'العربية', 'zh'=>'中文']],
            ['cle'=>'theme_couleur', 'label'=>'Couleur du thème', 'type'=>'select', 'defaut'=>'indigo', 'options'=>['indigo'=>'Indigo', 'emerald'=>'Vert', 'sky'=>'Bleu', 'rose'=>'Rose', 'amber'=>'Ambre']],
            ['cle'=>'fuseau_horaire', 'label'=>'Fuseau horaire', 'type'=>'select', 'defaut'=>'Africa/Lome', 'options'=>
                ['Africa/Lome'=>'Africa/Lome (UTC+0 — Togo)',
                 'Africa/Abidjan'=>'Africa/Abidjan (UTC+0 — Côte d\'Ivoire)',
                 'Africa/Accra'=>'Africa/Accra (UTC+0 — Ghana)',
                 'Africa/Kinshasa'=>'Africa/Kinshasa (UTC+1 — RD Congo)',
                 'Africa/Lagos'=>'Africa/Lagos (UTC+1 — Nigeria)',
                 'Africa/Porto-Novo'=>'Africa/Porto-Novo (UTC+1 — Bénin)',
                 'Africa/Douala'=>'Africa/Douala (UTC+1 — Cameroun)',
                 'Africa/Ndjamena'=>'Africa/Ndjamena (UTC+1 — Tchad)',
                 'Africa/Brazzaville'=>'Africa/Brazzaville (UTC+1 — Congo)',
                 'Africa/Libreville'=>'Africa/Libreville (UTC+1 — Gabon)',
                 'Africa/Bangui'=>'Africa/Bangui (UTC+1 — RCA)',
                 'Africa/Niamey'=>'Africa/Niamey (UTC+1 — Niger)',
                 'Africa/Bamako'=>'Africa/Bamako (UTC+0 — Mali)',
                 'Africa/Ouagadougou'=>'Africa/Ouagadougou (UTC+0 — Burkina Faso)',
                 'Africa/Conakry'=>'Africa/Conakry (UTC+0 — Guinée)',
                 'Africa/Dakar'=>'Africa/Dakar (UTC+0 — Sénégal)',
                 'Africa/Nouakchott'=>'Africa/Nouakchott (UTC+0 — Mauritanie)',
                 'Africa/Tunis'=>'Africa/Tunis (UTC+1 — Tunisie)',
                 'Africa/Algiers'=>'Africa/Algiers (UTC+1 — Algérie)',
                 'Africa/Casablanca'=>'Africa/Casablanca (UTC+1 — Maroc)',
                 'Europe/Paris'=>'Europe/Paris (UTC+1/+2 — France)',
                 'Europe/Brussels'=>'Europe/Brussels (UTC+1/+2 — Belgique)',
                 'UTC'=>'UTC (Temps universel)',
                ],
            ],
        ],
    ],
    'email' => [
        'titre' => 'E-mails',
        'icone' => 'bi-envelope-paper',
        'largeur' => 'col-lg-12',
        'champs' => [
            ['cle'=>'emails_mode', 'label'=>'Mode d\'envoi', 'type'=>'select', 'defaut'=>'file', 'options'=>['file'=>'File d\'attente (worker asynchrone)', 'direct'=>'Envoi direct (bloquant)']],
            ['cle'=>'smtp_from', 'label'=>'Adresse expéditrice', 'type'=>'email', 'defaut'=>'noreply@estock.local'],
            ['cle'=>'base_url_ext', 'label'=>'URL publique du site (liens dans les e-mails)', 'type'=>'text', 'defaut'=>''],
            ['cle'=>'emails_conservation_jours', 'label'=>'Conservation des e-mails traités avant purge (jours)', 'type'=>'number', 'defaut'=>'30', 'min'=>1, 'step'=>'1'],
            ['cle'=>'emails_tentatives_max', 'label'=>'Tentatives d\'envoi maximum', 'type'=>'number', 'defaut'=>'5', 'min'=>1, 'max'=>20, 'step'=>'1'],
            ['cle'=>'emails_desinscription_obligatoire', 'label'=>'Mention désinscription dans les e-mails de prospection', 'type'=>'checkbox', 'defaut'=>'1'],
        ],
        'aide' => '<strong>Comment configurer l\'envoi d\'e-mails :</strong>
            <ol class="mb-1 ps-3 mt-1">
                <li><strong>Mode d\'envoi</strong> — « File d\'attente » (recommandé) : les e-mails sont mis en file puis expédiés par le worker
                    <code>bin/emails_worker.php</code>, à planifier en tâche cron toutes les minutes
                    (ex. <code>* * * * * php C:/wamp64/www/eStock/bin/emails_worker.php</code>).
                    « Envoi direct » : envoi immédiat via la fonction <code>mail()</code> de PHP (bloquant).</li>
                <li><strong>Adresse expéditrice</strong> — une adresse réelle du domaine de la boutique (ex. <code>noreply@votre-domaine.com</code>).</li>
                <li><strong>URL publique</strong> — l\'adresse d\'accès du site vu par les clients (indispensable pour les liens de désinscription
                    des e-mails de prospection).</li>
                <li><strong>Environnement local (WampServer)</strong> — sans serveur SMTP actif, <code>mail()</code> échoue : les e-mails sont alors
                    journalisés dans <code>error_log</code> (mode simulation). Pour un vrai envoi, configurez le relais dans
                    <code>php.ini</code> (<code>sendmail_path</code> / SMTP) ou déployez le site chez un hébergeur.</li>
            </ol>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#aideCronEmail">Voir le réglage cron type</button>
            <pre class="collapse border rounded bg-light p-2 mt-2 mb-0 small" id="aideCronEmail"><code>* * * * * php C:/wamp64/www/eStock/bin/emails_worker.php &gt;&gt; /tmp/emails_worker.log 2&gt;&amp;1</code></pre>',
    ],
    'google_oauth' => [
        'titre' => 'Connexion Google',
        'icone' => 'bi-google',
        'champs' => [
            ['cle'=>'google_oauth_actif', 'label'=>'Activer la connexion avec compte Google', 'type'=>'checkbox', 'defaut'=>'0'],
            ['cle'=>'google_client_id', 'label'=>'Client ID Google', 'type'=>'text', 'defaut'=>'', 'required'=>false],
            ['cle'=>'google_client_secret', 'label'=>'Client Secret Google', 'type'=>'secret', 'defaut'=>'', 'required'=>false],
        ],
        'aide' => '<strong>Configurer la connexion Google :</strong>
            <ol class="mb-1 ps-3 mt-1">
                <li>Allez sur <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console → Credentials</a>.</li>
                <li>Créez un <strong>OAuth 2.0 Client ID</strong> (type : Web application).</li>
                <li>Ajoutez l\'<strong>URI de redirection autorisé</strong> : <code>' . h(BASE_URL . 'auth/google_callback.php') . '</code></li>
                <li>Copiez le <strong>Client ID</strong> et le <strong>Client Secret</strong> dans les champs ci-dessus.</li>
                <li>Activez l\'option « Activer la connexion avec compte Google ».</li>
            </ol>
            <div class="alert alert-info small mt-2 mb-0">
                <i class="bi bi-info-circle"></i> Lorsqu\'un utilisateur se connecte avec Google pour la première fois :
                si son email correspond à un compte existant, le compte est lié automatiquement.
                Sinon, un nouveau compte est créé avec le rôle <strong>Vendeur</strong>.
            </div>',
    ],
];

$champs = [];
foreach ($sections as $section) {
    foreach ($section['champs'] as $champ) {
        $champs[$champ['cle']] = $champ;
    }
}

// ============================================================
//  TRAITEMENT POST : CONFIGURATION GENERALE
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
    if (!csrf_validate()) {
        flash_error('Token de sécurité invalide.');
        redirect('parametres.php');
    }

    // --- Étape 1 : calcul et validation de TOUTES les valeurs avant tout enregistrement ---
    // (évite une sauvegarde partielle si un champ obligatoire est vide/invalide en cours de boucle)
    $valeurs_calculees = [];
    $erreurs = [];

    foreach ($champs as $cle => $champ) {
        $type = $champ['type'] ?? 'text';

        if ($type === 'checkbox') {
            $valeur = isset($_POST[$cle]) ? '1' : '0';
        } else {
            $valeur = trim((string)($_POST[$cle] ?? ''));
        }

        // Préserver l'ancienne valeur du client_secret si le champ est laissé vide
        if ($cle === 'google_client_secret' && $valeur === '') {
            $valeur = param('google_client_secret', '');
        }

        if ($type === 'select') {
            $options = $champ['options'] ?? [];
            if (!array_key_exists($valeur, $options)) {
                $valeur = (string)($champ['defaut'] ?? '');
            }
        }

        if ($type === 'number') {
            $valeur = str_replace(',', '.', $valeur);
            $nombre = is_numeric($valeur) ? (float)$valeur : (float)($champ['defaut'] ?? 0);
            if (isset($champ['min'])) $nombre = max((float)$champ['min'], $nombre);
            if (isset($champ['max'])) $nombre = min((float)$champ['max'], $nombre);
            $valeur = (($champ['step'] ?? '') === '1') ? (string)(int)$nombre : number_format($nombre, 2, '.', '');
        }

        if ($cle === 'devise_code') {
            $valeur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $valeur), 0, 3));
            if ($valeur === '') $valeur = (string)($champ['defaut'] ?? 'XOF');
        }

        // Champs obligatoires : non vérifié auparavant côté serveur (le HTML "required"
        // ne protège pas contre un POST envoyé directement, hors formulaire).
        if (!empty($champ['required']) && $valeur === '') {
            $erreurs[] = $champ['label'] . ' est obligatoire.';
        }

        // Format e-mail : rejeter une valeur non vide mais invalide plutôt que de
        // l'enregistrer telle quelle (utilisée ensuite pour l'expédition de mails).
        if ($type === 'email' && $valeur !== '' && !filter_var($valeur, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = $champ['label'] . ' doit être une adresse e-mail valide.';
        }

        // Respect de maxlength côté serveur (défense en profondeur contre un POST direct).
        if (isset($champ['maxlength']) && mb_strlen($valeur) > (int)$champ['maxlength']) {
            $valeur = mb_substr($valeur, 0, (int)$champ['maxlength']);
        }

        // Un séparateur décimal vide casse l'affichage monétaire (ex: "100050" au lieu
        // de "1000,50"). Le séparateur des milliers, lui, peut légitimement être vide.
        if ($cle === 'separateur_decimal' && $valeur === '') {
            $valeur = ',';
        }

        $valeurs_calculees[$cle] = $valeur;
    }

    if (!empty($erreurs)) {
        flash_error(implode(' ', $erreurs));
        redirect('parametres.php');
    }

    // --- Étape 2 : enregistrement, une fois toutes les valeurs validées ---
    foreach ($valeurs_calculees as $cle => $valeur) {
        param_save($cle, $valeur);
        if ($cle === 'langue') {
            $_SESSION['langue'] = $valeur;
        }
    }

    params_flush_cache();
    suivre_activite('MODIFICATION_PARAMETRES', 'Modification des paramètres boutique');
    flash_success('Paramètres enregistrés. Les nouvelles informations sont maintenant utilisées dans l\'application.');
    redirect('parametres.php');
}

// ============================================================
//  TRAITEMENT POST : SAUVEGARDE DES PERMISSIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_permissions') {
    if (!csrf_validate()) {
        flash_error('Token de sécurité invalide.');
        redirect('parametres.php?tab=droits');
    }
    
    $permissions_post = $_POST['permissions'] ?? [];
    $roles = db_permissions_get_roles($pdo);
    
    foreach ($roles as $role) {
        $perm_ids = array_map('intval', $permissions_post[$role] ?? []);
        $perm_ids = array_filter($perm_ids, fn($id) => $id > 0);
        db_permissions_save_for_role($pdo, $role, $perm_ids);
    }
    
    suivre_activite('MODIFICATION_PERMISSIONS', 'Modification des droits d\'accès RBAC');
    flash_success('Droits mis à jour avec succès. Les changements prennent effet immédiatement.');
    redirect('parametres.php?tab=droits');
}

// ============================================================
//  TRAITEMENT POST : SAUVEGARDE D'UNE RÈGLE DE PROMOTION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_promotion') {
    if (!csrf_validate()) {
        flash_error('Token de sécurité invalide.');
        redirect('parametres.php?tab=promotions');
    }

    $promo_data = [
        'nom'                 => input_string($_POST['nom'] ?? ''),
        'condition_type'      => $_POST['condition_type'] ?? 'PEREMPTION_PROCHE',
        'jours_limite'        => ($_POST['jours_limite'] ?? '') !== '' ? (int)($_POST['jours_limite'] ?? 0) : null,
        'seuil_stock'         => ($_POST['seuil_stock'] ?? '') !== '' ? (int)($_POST['seuil_stock'] ?? 0) : null,
        'pourcentage_remise'  => (float)($_POST['pourcentage_remise'] ?? 0),
        'actif'               => isset($_POST['actif']) ? 1 : 0,
    ];

    // Validation complète : le nom, le pourcentage ET le champ spécifique à la
    // condition choisie (sans ça, une règle "Péremption" sans jours limite serait
    // enregistrée mais ne se déclencherait jamais, silencieusement).
    $erreurs_promo = [];
    if ($promo_data['nom'] === '') {
        $erreurs_promo[] = 'Le nom de la règle est obligatoire.';
    }
    if ($promo_data['pourcentage_remise'] <= 0 || $promo_data['pourcentage_remise'] > 100) {
        $erreurs_promo[] = 'Le pourcentage de remise doit être compris entre 0 et 100.';
    }
    if ($promo_data['condition_type'] === 'PEREMPTION_PROCHE') {
        if ($promo_data['jours_limite'] === null || $promo_data['jours_limite'] <= 0) {
            $erreurs_promo[] = 'Le nombre de jours limite est obligatoire pour une règle de péremption.';
        }
    } elseif ($promo_data['condition_type'] === 'SURSTOCK') {
        if ($promo_data['seuil_stock'] === null || $promo_data['seuil_stock'] <= 0) {
            $erreurs_promo[] = 'Le seuil de stock est obligatoire pour une règle de surstock.';
        }
    }

    if (!empty($erreurs_promo)) {
        flash_error(implode(' ', $erreurs_promo));
        redirect('parametres.php?tab=promotions');
    }

    if (isset($_POST['promo_id']) && (int)$_POST['promo_id'] > 0) {
        $promo_data['id'] = (int)$_POST['promo_id'];
    }

    db_promotion_save($pdo, $promo_data);
    suivre_activite('MODIFICATION_PROMOTION', 'Sauvegarde règle promotion : ' . $promo_data['nom']);
    flash_success('Règle de promotion enregistrée.');
    redirect('parametres.php?tab=promotions');
}

// ============================================================
//  TRAITEMENT POST : SUPPRESSION D'UNE RÈGLE DE PROMOTION
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_promotion') {
    if (!csrf_validate()) {
        flash_error('Token de sécurité invalide.');
        redirect('parametres.php?tab=promotions');
    }

    $promo_id = (int)($_POST['promo_id'] ?? 0);
    if ($promo_id > 0) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM regles_promotions WHERE id = ?");
            $stmt->execute([$promo_id]);
            $pdo->commit();
            suivre_activite('SUPPRESSION_PROMOTION', 'Suppression règle promotion #' . $promo_id);
            flash_success('Règle de promotion supprimée avec succès.');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Erreur critique suppression promotion: " . $e->getMessage());
            flash_error("Une erreur système est survenue lors de la suppression.");
        }
    }
    redirect('parametres.php?tab=promotions');
}

function valeur_param_champ(array $champ): string {
    return param($champ['cle'], (string)($champ['defaut'] ?? ''));
}

$titre_page = 'Paramètres';
$sous_titre = param_shop_name();
$promotions_all = db_promotions_get_all($pdo);

// Inclusion globale du header pour TOUS les onglets afin de garder le menu de navigation cohérent
include __DIR__ . '/includes/header.php';
?>

<!-- Onglets de Navigation Supérieurs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab_courant === 'config' ? 'active' : '' ?>" href="?tab=config">
            <i class="bi bi-gear"></i> Configuration
        </a>
    </li>
    <?php if (peut('utilisateurs_gerer')): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab_courant === 'droits' ? 'active' : '' ?>" href="?tab=droits">
            <i class="bi bi-shield-lock"></i> Gestion des Droits
        </a>
    </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab_courant === 'promotions' ? 'active' : '' ?>" href="?tab=promotions">
            <i class="bi bi-lightning"></i> Promotions
        </a>
    </li>
</ul>

<?php 
// ============================================================
//  RENDU ONGLET DROITS (RBAC via Twig injecté dans le layout commun)
// ============================================================
if ($tab_courant === 'droits' && peut('utilisateurs_gerer')): 
    $roles = db_permissions_get_roles($pdo);
    $all_perms = db_permissions_all($pdo);
    
    $grouped = [];
    foreach ($all_perms as $perm) {
        $cat = $perm['categorie'];
        if (!isset($grouped[$cat])) $grouped[$cat] = [];
        $active = [];
        foreach ($roles as $role) {
            $active[$role] = db_role_has_permission($pdo, $role, $perm['cle_permission']);
        }
        $grouped[$cat][] = [
            'id' => $perm['id'],
            'cle_permission' => $perm['cle_permission'],
            'description' => $perm['description'],
            'active' => $active,
        ];
    }
    
    $permissions_data = [
        'roles' => $roles,
        'grouped' => $grouped,
        'total_permissions' => count($all_perms ?? []),
    ];
    
    // On effectue le rendu de la matrice de droits directement à cet emplacement
    echo $twig->render('permissions.html.twig', [
        'permissions_data' => $permissions_data,
        'titre_page' => $titre_page,
        'sous_titre' => $sous_titre,
    ]);
?>

<?php elseif ($tab_courant === 'config'): ?>
    <?php
    $conformite_manquants = [];
    if (trim(param('nif_boutique', '')) === '') $conformite_manquants[] = 'NIF de la boutique';
    if (trim(param('rccm_boutique', '')) === '') $conformite_manquants[] = 'RCCM de la boutique';
    ?>
    <?php if (!empty($conformite_manquants)): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2 shadow-sm">
            <i class="bi bi-shield-exclamation fs-4"></i>
            <div>
                <strong>Facturation à risque :</strong> les factures sans identification fiscale complète
                sont non conformes auprès de l'OTR. Renseignez : <?= h(implode(', ', $conformite_manquants)) ?>.
            </div>
        </div>
    <?php endif; ?>
    <!-- Configuration classique -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card is-primary">
                <div class="stat-label">Boutique</div>
                <div class="stat-value"><?= h(param_shop_name()) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card is-success">
                <div class="stat-label">Devise</div>
                <div class="stat-value"><?= h(param('devise_symbole', 'FCFA')) ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card is-info">
                <div class="stat-label">TVA</div>
                <div class="stat-value"><?= h(number_format(param_tva_taux(), 2, ',', ' ')) ?>%</div>
            </div>
        </div>
    </div>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="row g-3">
            <?php foreach ($sections as $section): ?>
                <div class="<?= h($section['largeur'] ?? 'col-lg-6') ?>">
                    <div class="card shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="bi <?= h($section['icone']) ?>"></i> <?= h($section['titre']) ?></h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <?php foreach ($section['champs'] as $champ):
                                    $cle = $champ['cle'];
                                    $type = $champ['type'] ?? 'text';
                                    $valeur = valeur_param_champ($champ);
                                    $col = $type === 'textarea' ? 'col-12' : 'col-md-6';
                                ?>
                                    <div class="<?= $type === 'checkbox' ? 'col-12' : $col ?>">
                                        <?php if ($type === 'checkbox'): ?>
                                            <div class="form-check form-switch mt-2">
                                                <input class="form-check-input" type="checkbox" role="switch"
                                                       id="<?= h($cle) ?>" name="<?= h($cle) ?>" value="1"
                                                       <?= param_bool($cle, ($champ['defaut'] ?? '0') === '1') ? 'checked' : '' ?>>
                                                <label class="form-check-label fw-semibold" for="<?= h($cle) ?>"><?= h($champ['label']) ?></label>
                                            </div>
                                        <?php elseif ($type === 'textarea'): ?>
                                            <label class="form-label" for="<?= h($cle) ?>"><?= h($champ['label']) ?></label>
                                            <textarea id="<?= h($cle) ?>" name="<?= h($cle) ?>" class="form-control" rows="2"><?= h($valeur) ?></textarea>
                                        <?php elseif ($type === 'select'): ?>
                                            <label class="form-label" for="<?= h($cle) ?>"><?= h($champ['label']) ?></label>
                                            <select id="<?= h($cle) ?>" name="<?= h($cle) ?>" class="form-select">
                                                <?php foreach (($champ['options'] ?? []) as $optionValeur => $optionLabel): ?>
                                                    <option value="<?= h($optionValeur) ?>" <?= $valeur === (string)$optionValeur ? 'selected' : '' ?>>
                                                        <?= h($optionLabel) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <label class="form-label" for="<?= h($cle) ?>"><?= h($champ['label']) ?></label>
                                            <input id="<?= h($cle) ?>" name="<?= h($cle) ?>" class="form-control"
                                                   type="<?= h($type === 'secret' ? 'password' : $type) ?>"
                                                   value="<?= h($valeur) ?>"
                                                   placeholder="<?= $type === 'secret' ? 'Laisser vide pour conserver' : '' ?>"
                                                   <?= !empty($champ['required']) ? 'required' : '' ?>
                                                   <?= isset($champ['maxlength']) ? 'maxlength="' . (int)$champ['maxlength'] . '"' : '' ?>
                                                   <?= isset($champ['min']) ? 'min="' . h($champ['min']) . '"' : '' ?>
                                                   <?= isset($champ['max']) ? 'max="' . h($champ['max']) . '"' : '' ?>
                                                   <?= isset($champ['step']) ? 'step="' . h($champ['step']) . '"' : '' ?>>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($section['aide'])): ?>
                                <div class="bg-info-subtle border-start border-4 border-info rounded-end p-3 mb-0 mt-3 small text-start">
                                    <?= $section['aide'] ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-4 d-flex justify-content-end gap-2">
            <a href="tableau_bord.php" class="btn btn-outline-secondary">Annuler</a>
            <button class="btn btn-primary btn-lg" type="submit"><i class="bi bi-save"></i> Enregistrer</button>
        </div>
    </form>
<?php endif; // fin onglet config ?>

<?php if ($tab_courant === 'promotions'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-lightning text-warning"></i> Ventes Flash — Règles de Remise</h4>
        <p class="text-muted mb-0 small">Configurez les remises automatiques pour les articles proches de la péremption ou en surstock.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalPromotion" id="btnAjouterPromo">
        <i class="bi bi-plus-lg"></i> Nouvelle règle
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card is-warning">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Règles actives</div>
                    <div class="stat-value" id="nbReglesActives"><?= count(array_filter($promotions_all ?? [], fn($r) => (int)$r['actif'] === 1)) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-lightning"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card is-danger">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Anti-gaspillage</div>
                    <div class="stat-value" id="nbPeremption"><?= count(array_filter($promotions_all ?? [], fn($r) => $r['condition_type'] === 'PEREMPTION_PROCHE')) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-clock-history"></i></span>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card is-info">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="stat-label">Surstock</div>
                    <div class="stat-value" id="nbSurstock"><?= count(array_filter($promotions_all ?? [], fn($r) => $r['condition_type'] === 'SURSTOCK')) ?></div>
                </div>
                <span class="stat-icon"><i class="bi bi-box-seam"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="glass-card mb-4">
    <div class="glass-header">
        <i class="bi bi-list-check"></i>
        <span>Règles configurées</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th class="text-center">Type</th>
                        <th class="text-center">Condition</th>
                        <th class="text-center">Remise</th>
                        <th class="text-center">Statut</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promotions_all ?? [] as $promo): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($promo['nom']) ?></td>
                        <td class="text-center">
                            <?php if ($promo['condition_type'] === 'PEREMPTION_PROCHE'): ?>
                                <span class="badge glass-badge-qty is-warning"><i class="bi bi-clock-history"></i> Péremption</span>
                            <?php else: ?>
                                <span class="badge glass-badge-qty is-info"><i class="bi bi-box-seam"></i> Surstock</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($promo['condition_type'] === 'PEREMPTION_PROCHE'): ?>
                                ≤ <?= (int)$promo['jours_limite'] ?> jours
                            <?php else: ?>
                                ≥ <?= (int)$promo['seuil_stock'] ?> unités
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge glass-badge-qty is-danger">−<?= h(number_format((float)$promo['pourcentage_remise'], 0, ',', '')) ?>%</span>
                        </td>
                        <td class="text-center">
                            <?php if ((int)$promo['actif'] === 1): ?>
                                <span class="badge glass-badge-qty is-success">Actif</span>
                            <?php else: ?>
                                <span class="badge glass-badge-qty is-secondary">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-primary me-1 btn-edit-promo"
                                    data-id="<?= $promo['id'] ?>"
                                    data-nom="<?= h($promo['nom']) ?>"
                                    data-type="<?= h($promo['condition_type']) ?>"
                                    data-jours="<?= h($promo['jours_limite'] ?? '') ?>"
                                    data-seuil="<?= h($promo['seuil_stock'] ?? '') ?>"
                                    data-remise="<?= h($promo['pourcentage_remise']) ?>"
                                    data-actif="<?= $promo['actif'] ?>"
                                    title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" class="d-inline" data-confirm="Supprimer cette règle ?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_promotion">
                                <input type="hidden" name="promo_id" value="<?= $promo['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($promotions_all)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-lightning fs-1 d-block mb-2"></i>
                            Aucune règle configurée. Ajoutez-en une pour activer les ventes flash.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPromotion" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content" id="formPromotion">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_promotion">
            <input type="hidden" name="promo_id" id="promoId" value="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-lightning"></i> <span id="promoModalTitle">Nouvelle règle</span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nom de la règle *</label>
                    <input type="text" name="nom" id="promoNom" class="form-control" required
                           placeholder="ex : Anti-gaspillage - 7 jours" maxlength="200">
                </div>
                <div class="mb-3">
                    <label class="form-label">Type de condition *</label>
                    <select name="condition_type" id="promoType" class="form-select" required>
                        <option value="PEREMPTION_PROCHE">Péremption proche (DLC/DDM)</option>
                        <option value="SURSTOCK">Surstock (quantité en stock)</option>
                    </select>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6" id="grpJours">
                        <label class="form-label">Jours limite</label>
                        <input type="number" name="jours_limite" id="promoJours" class="form-control"
                               min="1" max="365" placeholder="ex : 7">
                        <div class="form-text">Appliquer la remise si ≤ X jours avant DLC.</div>
                    </div>
                    <div class="col-6" id="grpSeuil" style="display:none">
                        <label class="form-label">Seuil stock</label>
                        <input type="number" name="seuil_stock" id="promoSeuil" class="form-control"
                               min="1" placeholder="ex : 100">
                        <div class="form-text">Appliquer la remise si stock ≥ X unités.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Pourcentage de remise *</label>
                        <div class="input-group">
                            <input type="number" name="pourcentage_remise" id="promoRemise" class="form-control"
                                   min="1" max="90" step="0.5" required placeholder="ex : 15">
                            <span class="input-group-text">%</span>
                        </div>
                    </div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="actif" id="promoActif" value="1" checked>
                    <label class="form-check-label fw-semibold" for="promoActif">Règle active</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
document.addEventListener('DOMContentLoaded', function() {
    var promoType = document.getElementById('promoType');
    var grpJours = document.getElementById('grpJours');
    var grpSeuil = document.getElementById('grpSeuil');

    function toggleConditionFields() {
        if (!promoType || !grpJours || !grpSeuil) return;
        if (promoType.value === 'PEREMPTION_PROCHE') {
            grpJours.style.display = '';
            grpSeuil.style.display = 'none';
        } else {
            grpJours.style.display = 'none';
            grpSeuil.style.display = '';
        }
    }
    if (promoType) {
        promoType.addEventListener('change', toggleConditionFields);
        toggleConditionFields();
    }

    document.querySelectorAll('.btn-edit-promo').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var el;
            el = document.getElementById('promoModalTitle'); if (el) el.textContent = 'Modifier la règle';
            el = document.getElementById('promoId'); if (el) el.value = btn.dataset.id;
            el = document.getElementById('promoNom'); if (el) el.value = btn.dataset.nom;
            el = document.getElementById('promoType'); if (el) el.value = btn.dataset.type;
            el = document.getElementById('promoJours'); if (el) el.value = btn.dataset.jours;
            el = document.getElementById('promoSeuil'); if (el) el.value = btn.dataset.seuil;
            el = document.getElementById('promoRemise'); if (el) el.value = btn.dataset.remise;
            el = document.getElementById('promoActif'); if (el) el.checked = btn.dataset.actif === '1';
            toggleConditionFields();
            var modal = document.getElementById('modalPromotion');
            if (modal) new bootstrap.Modal(modal).show();
        });
    });

    var btnAjouter = document.getElementById('btnAjouterPromo');
    if (btnAjouter) {
        btnAjouter.addEventListener('click', function() {
            var el;
            el = document.getElementById('promoModalTitle'); if (el) el.textContent = 'Nouvelle règle';
            el = document.getElementById('promoId'); if (el) el.value = '';
            el = document.getElementById('formPromotion'); if (el) el.reset();
            el = document.getElementById('promoActif'); if (el) el.checked = true;
            toggleConditionFields();
        });
    }
});
</script>
<?php endif; // fin onglet promotions ?>

<?php include __DIR__ . '/includes/footer.php'; ?>