<?php
/**
 * utilisateurs.php - Gestion des utilisateurs (Directeur / Admin uniquement).
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN);

$action = $_GET['action'] ?? 'liste';

// ---- Enregistrement ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'enregistrer') {
    csrf_guard('utilisateurs.php');
    $data = extract_post_data([
        'id'            => ['type' => 'int'],
        'nom'           => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 150, 'redirect' => ''],
        'login'         => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 60, 'redirect' => ''],
        'role'          => ['type' => 'string', 'default' => ROLE_VENDEUR, 'whitelist' => [ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER, ROLE_VENDEUR]],
        'magasin_id'    => ['type' => 'int', 'min' => 0],
        'mot_de_passe'  => ['type' => 'string'],
        'actif'         => ['type' => 'bool'],
    ], 'utilisateurs.php');

    $form_url = $data['id'] ? page_url('utilisateurs', ['id' => $data['id'], 'action' => 'editer']) : page_url('utilisateurs', ['action' => 'nouveau']);
    
    validate_field_lengths($data, [
        'nom'   => ['label' => 'Nom', 'max' => 150],
        'login' => ['label' => 'Identifiant', 'max' => 60],
    ], $form_url);

    validate_uniqueness(
        fn() => db_user_login_exists($pdo, $data['login'], $data['id']),
        'Cet identifiant existe déjà.',
        $form_url
    );

    $mdp = $data['mot_de_passe'];

    // Vérifier la hiérarchie pour modification
    if ($data['id'] > 0 && !user_can_edit_user($pdo, $data['id'])) {
        flash_error('Vous ne pouvez pas modifier un utilisateur de niveau égal ou supérieur.');
        redirect('utilisateurs.php');
    }

    // Hiérarchie des rôles à la création
    $role_hierarchy = [
        ROLE_VENDEUR    => 1,
        ROLE_MAGASINIER => 2,
        ROLE_ADMIN      => 3,
        ROLE_DIRECTEUR  => 4,
    ];
    $current = user_courant();
    $current_role_level = $role_hierarchy[$current['role']] ?? 0;
    $target_role_level = $role_hierarchy[$data['role']] ?? 0;

    if ($data['id'] === 0 && $target_role_level >= $current_role_level) {
        flash_error('Vous ne pouvez pas créer un utilisateur avec un rang égal ou supérieur au vôtre.');
        redirect('utilisateurs.php');
    }

    // Magasin d'appartenance : obligatoire pour tous les rôles sauf DIRECTEUR (global)
    $magasin_utilisateur = (int)($data['magasin_id'] ?? 0);
    if ($data['role'] !== ROLE_DIRECTEUR) {
        if ($magasin_utilisateur <= 0) {
            flash_error('Le magasin d\'appartenance est obligatoire pour ce rôle.');
            redirect($form_url);
        }
        $stmt_m = $pdo->prepare("SELECT id FROM magasins WHERE id = ? AND actif = 1");
        $stmt_m->execute([$magasin_utilisateur]);
        if (!$stmt_m->fetch()) {
            flash_error('Magasin sélectionné invalide ou inactif.');
            redirect($form_url);
        }
    }

    db_transaction(
        function(PDO $pdo) use ($data, $mdp, $magasin_utilisateur) {
            if ($data['id'] > 0) {
                // Modification sans toucher au mot de passe
                db_user_update_without_password($pdo, $data['id'], $data['nom'], $data['login'], $data['role'], $data['actif'] ? 1 : 0, $magasin_utilisateur);
            } else {
                // Création : Mot de passe requis
                if (trim($mdp) === '') { 
                    throw new RuntimeException('Mot de passe obligatoire à la création.'); 
                }
                if (mb_strlen($mdp) < 8) { 
                    throw new RuntimeException('Le mot de passe doit contenir au moins 8 caractères.'); 
                }
                $mdp_hash = password_hash($mdp, PASSWORD_BCRYPT);
                db_user_insert($pdo, $data['nom'], $data['login'], $mdp_hash, $data['role'], $data['actif'] ? 1 : 0, $magasin_utilisateur);
            }
        },
        'Utilisateur enregistré avec succès.',
        'Une erreur est survenue lors de l\'enregistrement.',
        'utilisateurs.php'
    );

    // Mise à jour de la session si l'utilisateur s'est modifié lui-même (changement de magasin)
    if ($data['id'] > 0 && $data['id'] === user_id()) {
        $_SESSION['user']['magasin_id'] = $magasin_utilisateur;
    }

    suivre_activite('MODIFICATION_UTILISATEUR', ($data['id'] > 0 ? 'Modification' : 'Création') . ' utilisateur "' . $data['login'] . '" (' . $data['role'] . ')');
    redirect('utilisateurs.php');
}

// ---- Activation / Désactivation ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle') {
    csrf_guard('utilisateurs.php');
    $target_id = (int)($_POST['id'] ?? 0);

    if ($target_id === (int)(user_courant()['id'] ?? 0)) {
        flash_error('Vous ne pouvez pas désactiver votre propre compte.');
        redirect('utilisateurs.php');
    }

    if (!user_can_edit_user($pdo, $target_id)) {
        flash_error('Vous ne pouvez pas modifier un utilisateur de niveau égal ou supérieur.');
        redirect('utilisateurs.php');
    }

    db_user_toggle_active($pdo, $target_id);
    suivre_activite('MODIFICATION_UTILISATEUR', 'Activation/Désactivation utilisateur #' . $target_id);
    redirect('utilisateurs.php');
}

// ---- Formulaire ----
if ($action === 'nouveau' || $action === 'editer') {
    $u = ['id' => '', 'nom' => '', 'login' => '', 'role' => ROLE_VENDEUR, 'magasin_id' => user_magasin_id(), 'actif' => 1];
    if ($action === 'editer') {
        $get_id = (int)($_GET['id'] ?? 0);
        if (!verify_url_signature($get_id, input_string($_GET['token'] ?? ''), ['action' => 'editer'])) {
            flash_error('Lien invalide ou expiré.');
            redirect('utilisateurs.php');
        }
        $u = db_user_get_by_id($pdo, $get_id) ?: $u;
    }

    $titre_page = ($action === 'nouveau' ? 'Nouvel utilisateur' : 'Modifier l\'utilisateur');
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-person-plus"></i> <?= h($titre_page) ?></h5>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= h(page_url('utilisateurs', ['action' => 'enregistrer'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Nom complet *</label>
                            <input type="text" name="nom" class="form-control" required value="<?= h($u['nom']) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Identifiant (login) *</label>
                            <input type="text" name="login" class="form-control" required value="<?= h($u['login']) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Rôle *</label>
                            <select name="role" class="form-select">
                                <?php foreach ([ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER, ROLE_VENDEUR] as $r): ?>
                                    <option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php $magasins_form = db_magasins_list($pdo); ?>
                        <div class="mb-3">
                            <label class="form-label">Magasin *</label>
                            <select name="magasin_id" class="form-select">
                                <option value="0">— Global (tous les magasins) —</option>
                                <?php foreach ($magasins_form as $m): ?>
                                    <option value="<?= (int)$m['id'] ?>" <?= (int)($u['magasin_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Le stock, la caisse et les ventes de cet utilisateur seront rattachés à ce magasin.</div>
                        </div>
                        <?php if ($action === 'nouveau'): ?>
                        <div class="mb-3">
                            <label class="form-label">Mot de passe *</label>
                            <input type="password" name="mot_de_passe" class="form-control"
                                   required minlength="8" autocomplete="new-password">
                            <div class="form-text">Minimum 8 caractères.</div>
                        </div>
                        <?php endif; ?>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="actif" class="form-check-input" id="actif" <?= !empty($u['actif']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="actif">Compte actif</label>
                        </div>
                        <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
                        <a href="<?= h('utilisateurs.php') ?>" class="btn btn-outline-secondary">Annuler</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

// ---- Liste ----
$utilisateurs = db_users_list($pdo);
$titre_page = 'Utilisateurs';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-end align-items-center mb-3 gap-2">
    <a href="<?= h(page_url('utilisateurs', ['action' => 'nouveau'])) ?>" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Nouvel utilisateur</a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nom</th><th>Login</th><th>Rôle</th><th>Magasin</th>
                        <th class="text-center">Statut</th><th>Créé le</th><th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($utilisateurs as $u): 
                    $couleur = role_badge_color($u['role']);
                ?>
                    <tr class="<?= !$u['actif'] ? 'table-secondary' : '' ?>">
                        <td class="fw-semibold"><?= h($u['nom']) ?></td>
                        <td><code><?= h($u['login']) ?></code></td>
                        <td><span class="badge text-bg-<?= $couleur ?>"><?= h($u['role']) ?></span></td>
                        <td><?= !empty($u['magasin_nom']) ? h($u['magasin_nom']) : '<span class="text-muted">Global</span>' ?></td>
                        <td class="text-center">
                            <?php if ($u['actif']): ?>
                                <span class="badge text-bg-success">Actif</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Inactif</span>
                            <?php endif; ?>
                        </td>
                        <td><?= h(date_fr($u['date_creation'], false)) ?></td>
                        <td class="text-center text-nowrap">
                            <a href="<?= h(generate_signed_url('utilisateurs.php', (int)$u['id'], ['action' => 'editer'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <?php if ((int)$u['id'] !== (int)(user_courant()['id'] ?? 0)): ?>
                            <form method="post" action="<?= h(page_url('utilisateurs', ['action' => 'toggle'])) ?>" style="display:inline"
                                  data-confirm="<?= $u['actif'] ? 'Désactiver' : 'Activer' ?> cet utilisateur ?">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Activer/Désactiver">
                                    <i class="bi bi-power"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
