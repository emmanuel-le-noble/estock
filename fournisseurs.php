<?php
/**
 * fournisseurs.php - CRUD des fournisseurs (Directeur / Admin / Magasinier).
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);

$action = $_GET['action'] ?? 'liste';

// ---- Enregistrement ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'enregistrer') {
    csrf_guard('fournisseurs.php');

    $data = extract_post_data([
        'id'        => ['type' => 'int'],
        'nom'       => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 150, 'redirect' => ''],
        'contact'   => ['type' => 'string', 'trim' => true, 'nullable' => true, 'max' => 150, 'redirect' => ''],
        'telephone' => ['type' => 'string', 'trim' => true, 'nullable' => true, 'max' => 30, 'redirect' => ''],
    ], 'fournisseurs.php');

    $form_url = $data['id'] ? generate_signed_url('fournisseurs.php', $data['id'], ['action' => 'editer']) : page_url('fournisseurs', ['action' => 'nouveau']);
    validate_field_lengths($data, [
        'nom'       => ['label' => 'Nom', 'max' => 150],
        'contact'   => ['label' => 'Contact', 'max' => 150],
        'telephone' => ['label' => 'Téléphone', 'max' => 30],
    ], $form_url);

    db_transaction(
        function(PDO $pdo) use ($data) {
            if ($data['id'] > 0) {
                db_fournisseur_update($pdo, $data['id'], $data['nom'], $data['contact'], $data['telephone']);
            } else {
                db_fournisseur_insert($pdo, $data['nom'], $data['contact'], $data['telephone']);
            }
        },
        'Fournisseur enregistré.',
        'Une erreur est survenue lors de l\'enregistrement.',
        'fournisseurs.php'
    );
    suivre_activite('MODIFICATION_FOURNISSEUR', ($data['id'] > 0 ? 'Modification' : 'Création') . ' fournisseur "' . $data['nom'] . '"');
}

// ---- Suppression ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'supprimer') {
    csrf_guard('fournisseurs.php');
    try {
        $supp_id = (int)($_POST['id'] ?? 0);
        db_fournisseur_delete($pdo, $supp_id);
        suivre_activite('SUPPRESSION_FOURNISSEUR', 'Suppression fournisseur #' . $supp_id);
        flash_success('Fournisseur supprimé.');
    } catch (Throwable $e) {
        flash_error('Impossible de supprimer : des articles y sont liés.');
    }
    redirect('fournisseurs.php');
}

// ---- Formulaire ----
if ($action === 'nouveau' || $action === 'editer') {
    $f = ['id'=>'','nom'=>'','contact'=>'','telephone'=>''];
    if ($action === 'editer') {
        $get_id = (int)($_GET['id'] ?? 0);
        if (!verify_url_signature($get_id, $_GET['token'] ?? '', ['action' => 'editer'])) {
            flash_error('Lien invalide ou expiré.');
            redirect('fournisseurs.php');
        }
        $f = db_fournisseur_get_by_id($pdo, $get_id) ?: $f;
    }
    $titre_page = ($action === 'nouveau' ? 'Nouveau fournisseur' : 'Modifier le fournisseur');
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-truck"></i> <?= h($titre_page) ?></h5>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= h(page_url('fournisseurs', ['action' => 'enregistrer'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Nom *</label>
                            <input type="text" name="nom" class="form-control" required value="<?= h($f['nom']) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact (responsable)</label>
                            <input type="text" name="contact" class="form-control" value="<?= h($f['contact']) ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Téléphone</label>
                            <input type="text" name="telephone" class="form-control" value="<?= h($f['telephone']) ?>">
                        </div>
                        <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
                        <a href="<?= h('fournisseurs.php') ?>" class="btn btn-outline-secondary">Annuler</a>
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
$fournisseurs = db_fournisseurs_list_with_count($pdo);

$titre_page = 'Fournisseurs';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-end align-items-center mb-3 gap-2">
    <a href="<?= h(page_url('fournisseurs', ['action' => 'nouveau'])) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> Nouveau fournisseur
    </a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Nom</th><th>Contact</th><th>Téléphone</th><th class="text-center">Articles liés</th><th class="text-center">Actions</th></tr>
                </thead>
                <tbody>
                <?php if (!$fournisseurs): ?>
                    <?php table_empty_row('Aucun fournisseur.', 5); ?>
                <?php else: foreach ($fournisseurs as $f): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($f['nom']) ?></td>
                        <td><?= h($f['contact'] ?: '—') ?></td>
                        <td><?= h($f['telephone'] ?: '—') ?></td>
                        <td class="text-center"><span class="badge text-bg-secondary"><?= (int)$f['nb_articles'] ?></span></td>
                        <td class="text-center text-nowrap">
                            <a href="<?= h(generate_signed_url('fournisseurs.php', (int)$f['id'], ['action' => 'editer'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= h(page_url('fournisseurs', ['action' => 'supprimer'])) ?>" style="display:inline"
                                  data-confirm="Supprimer ce fournisseur ?">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
