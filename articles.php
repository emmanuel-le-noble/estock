<?php
/**
 * articles.php - CRUD des articles (Directeur / Admin / Magasinier).
 * Actions gérées via ?action= :
 *   - liste (défaut)
 *   - nouveau / editer (formulaire)
 *   - enregistrer (POST : insert ou update)
 *   - supprimer (désactive l'article)
 */

if (!function_exists('est_connecte')) {
    require_once __DIR__ . '/config/connexion.php';
}
if (!function_exists('db_article_insert')) {
    require_once __DIR__ . '/includes/db_functions.php';
}
if (!function_exists('csrf_guard')) {
    require_once __DIR__ . '/includes/helpers.php';
}

exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_MAGASINIER);
$action = $_GET['action'] ?? 'liste';

// ---- Traitement POST (enregistrement) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'enregistrer') {
    csrf_guard('articles.php');
    
    // Extraction des données en amont pour éviter l'alerte de variable indéfinie dans le callback
    $data = extract_post_data([
        'id'            => ['type' => 'int'],
        'code_barre'    => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 64, 'redirect' => ''],
        'nom'           => ['type' => 'string', 'trim' => true, 'required' => true, 'max' => 200, 'redirect' => ''],
        'sku'           => ['type' => 'string', 'trim' => true, 'nullable' => true, 'max' => 64, 'redirect' => ''],
        'prix_achat'    => ['type' => 'float', 'min' => 0],
        'prix_vente'    => ['type' => 'float', 'min' => 0],
        'quantite_stock'=> ['type' => 'int', 'min' => 0],
        'seuil_alerte'  => ['type' => 'int', 'min' => 0, 'default' => 5],
        'emplacement'   => ['type' => 'string', 'trim' => true, 'nullable' => true, 'max' => 100, 'redirect' => ''],
        'fournisseur_id'=> ['type' => 'int', 'default' => 0],
        'categorie_id'  => ['type' => 'int', 'default' => 0],
        'taux_tva'      => ['type' => 'float', 'min' => 0, 'default' => 0],
    ], 'articles.php');
    
    $redirect_form = fn() => ($data['id'] ?? 0) ? generate_signed_url('articles.php', $data['id'], ['action' => 'editer']) : page_url('articles', ['action' => 'nouveau']);
    $form_url = $redirect_form();
    
    validate_field_lengths($data, [
        'code_barre'  => ['label' => 'Code-barres', 'max' => 64],
        'nom'         => ['label' => 'Nom', 'max' => 200],
        'sku'         => ['label' => 'SKU', 'max' => 64],
        'emplacement' => ['label' => 'Emplacement', 'max' => 100],
    ], $form_url);
    
    validate_uniqueness(
        fn() => db_article_code_barre_exists($pdo, $data['code_barre'], $data['id']),
        'Ce code-barres existe déjà.',
        $form_url
    );
    
    $id          = $data['id'];
    $quantite    = $data['quantite_stock'];
    $articleData = [
        'code_barre'     => $data['code_barre'],
        'nom'            => $data['nom'],
        'sku'            => $data['sku'],
        'prix_achat'     => $data['prix_achat'],
        'prix_vente'     => $data['prix_vente'],
        'quantite_stock' => $quantite,
        'seuil_alerte'   => $data['seuil_alerte'],
        'emplacement'    => $data['emplacement'],
        'fournisseur_id' => $data['fournisseur_id'] ?: null,
        'categorie_id'   => $data['categorie_id'] ?: null,
        'taux_tva'       => ($data['taux_tva'] > 0) ? round($data['taux_tva'], 4) : null,
    ];
    
    db_transaction(
        function(PDO $pdo) use (&$articleData, &$id, $quantite) {
            if ($id > 0) {
                db_article_update($pdo, $id, $articleData);
            } else {
                $id = db_article_insert($pdo, $articleData);
            }
        },
        'Article enregistré avec succès.',
        'Une erreur est survenue lors de l\'enregistrement.',
        'articles.php'
    );
    
    suivre_activite('MODIFICATION_ARTICLE', ($data['id'] > 0 ? 'Modification' : 'Création') . ' article #' . $id . ' - ' . $data['nom']);
    flash_success('Article enregistré avec succès.');
    redirect('articles.php');
}

// ---- Suppression (désactivation) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'supprimer') {
    csrf_guard('articles.php');
    $supp_id = (int)($_POST['id'] ?? 0);
    db_article_deactivate($pdo, $supp_id);
    suivre_activite('SUPPRESSION_ARTICLE', 'Désactivation article #' . $supp_id);
    flash_success('Article désactivé.');
    redirect('articles.php');
}

// ---- Formulaire nouveau / édition ----
if ($action === 'nouveau' || $action === 'editer') {
    $article = [
        'id'=>'','code_barre'=>'','nom'=>'','sku'=>'',
        'prix_achat'=>'','prix_vente'=>'','quantite_stock'=>'',
        'seuil_alerte'=>5,'emplacement'=>'','fournisseur_id'=>'',
        'categorie_id'=>'', 'taux_tva'=>''
    ];
    if ($action === 'editer') {
        $get_id = (int)($_GET['id'] ?? 0);
        if (!isset($_GET['token']) || !verify_url_signature($get_id, $_GET['token'], ['action' => 'editer'])) {
            flash_error('Lien invalide ou expiré.');
            redirect('articles.php');
        }
        $article = db_article_get_by_id($pdo, $get_id) ?: $article;
    }
    $fournisseurs = db_fournisseurs_list($pdo);
    $categories   = function_exists('db_categories_all') ? db_categories_all($pdo) : [];
    $taux_tva_global = param_tva_taux();
    $devise_symbole = param('devise_symbole', 'FCFA');
    $titre_page = ($action === 'nouveau' ? 'Nouvel article' : 'Modifier l\'article');
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header bg-white">
                    <h5 class="mb-0"><i class="bi bi-pencil-square"></i> <?= h($titre_page) ?></h5>
                </div>
                <div class="card-body">
                    <form method="post" action="<?= h(page_url('articles', ['action' => 'enregistrer'])) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)($article['id'] ?? 0) ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Code-barres *</label>
                                <div class="input-group">
                                    <input type="text" name="code_barre" id="codeBarreInput" class="form-control" required
                                           value="<?= h($article['code_barre']) ?>" maxlength="64">
                                    <button type="button" class="btn btn-outline-secondary" id="btnGenererCode"
                                            title="Gérer un code-barres EAN-13">
                                        <i class="bi bi-upc-scan"></i> Générer
                                    </button>
                                </div>
                                <div class="mt-2 text-center" id="barcodePreview" style="display:<?= $article['code_barre'] ? 'block' : 'none' ?>">
                                    <svg id="barcodeSvg"></svg>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">SKU</label>
                                <input type="text" name="sku" class="form-control"
                                       value="<?= h($article['sku']) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nom de l'article *</label>
                                <input type="text" name="nom" class="form-control" required
                                       value="<?= h($article['nom']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Prix d'achat (<?= h($devise_symbole) ?>)</label>
                                <input type="number" step="0.01" min="0" name="prix_achat" class="form-control"
                                       value="<?= h($article['prix_achat']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Prix de vente (<?= h($devise_symbole) ?>)</label>
                                <input type="number" step="0.01" min="0" name="prix_vente" class="form-control"
                                       value="<?= h($article['prix_vente']) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Quantité en stock</label>
                                <input type="number" min="0" name="quantite_stock" class="form-control"
                                       value="<?= (int)($article['quantite_stock'] ?? 0) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Seuil d'alerte</label>
                                <input type="number" min="0" name="seuil_alerte" class="form-control"
                                       value="<?= (int)($article['seuil_alerte'] ?? 5) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Emplacement (rayon)</label>
                                <input type="text" name="emplacement" class="form-control"
                                       value="<?= h($article['emplacement']) ?>" placeholder="ex : A1-01">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Fournisseur</label>
                                <?= render_dropdown('fournisseur_id', $fournisseurs, $article['fournisseur_id'] ?? '', ' ') ?>
                            </div>
                            <?php if (!empty($categories)): ?>
                            <div class="col-md-4">
                                <label class="form-label">Catégorie</label>
                                <select name="categorie_id" class="form-select">
                                    <option value="">— Aucune —</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= (int)($article['categorie_id'] ?? 0) === (int)$cat['id'] ? 'selected' : '' ?>>
                                        <?= h($cat['nom']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-4">
                                <label class="form-label">Taux TVA spécifique (%)
                                    <small class="text-muted">(laisser vide = taux global <?= h(number_format($taux_tva_global,1)) ?>%)</small>
                                </label>
                                <input type="number" step="0.01" min="0" max="100" name="taux_tva" class="form-control"
                                       value="<?= ($article['taux_tva'] !== '' && $article['taux_tva'] !== null) ? h(number_format((float)$article['taux_tva'], 2, '.', '')) : '' ?>"
                                       placeholder="ex : 18">
                            </div>
                        </div>
                        <div class="mt-4 d-flex gap-2">
                            <button class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer</button>
                            <a href="<?= h('articles.php') ?>" class="btn btn-outline-secondary">Annuler</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    ?>
    <script nonce="<?= h(csp_nonce()) ?>">
    (function() {
        var input = document.getElementById('codeBarreInput');
        var preview = document.getElementById('barcodePreview');
        var svg = document.getElementById('barcodeSvg');
        var btn = document.getElementById('btnGenererCode');
        var BASE = window.BASE_URL || '/eSotck/';
        
        function renderBarcode(code) {
            if (!code || code.length < 12 || typeof JsBarcode === 'undefined') {
                preview.style.display = 'none';
                return;
            }
            preview.style.display = 'block';
            try {
                JsBarcode(svg, code, {
                    format: 'EAN13',
                    width: 1.5,
                    height: 50,
                    displayValue: true,
                    fontSize: 14,
                    margin: 5
                });
            } catch(e) {
                preview.style.display = 'none';
            }
        }
        
        if (input && input.value.trim()) {
            renderBarcode(input.value.trim());
        }
        
        if (input) {
            input.addEventListener('input', function() {
                var v = this.value.trim();
                if (v.length >= 12) {
                    renderBarcode(v);
                } else {
                    preview.style.display = 'none';
                }
            });
        }
        
        if (btn) {
            btn.addEventListener('click', function() {
                fetch(BASE + 'api/generer_code_barre', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.code_barre) {
                        input.value = data.code_barre;
                        renderBarcode(data.code_barre);
                    }
                })
                .catch(function() {
                    alert('Erreur lors de la génération du code-barres.');
                });
            });
        }
    })();
    </script>
    <?php
    exit;
}

// ---- LISTE des articles (vue par défaut) ----
$search = trim($_GET['q'] ?? '');
$search_sql = db_articles_search_sql($search);
$result = paginate($search_sql['sql'], $search_sql['params'], 25);
$articles = $result['items'];
$titre_page = 'Articles';
include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-end align-items-center mb-3 gap-2">
    <a href="<?= h(page_url('articles', ['action' => 'nouveau'])) ?>" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> Nouvel article
    </a>
</div>
<div class="card shadow-sm">
    <div class="card-header bg-white">
        <form id="searchForm" class="d-flex gap-2" role="search">
            <input type="search" name="q" id="searchInput" class="form-control" placeholder="Rechercher (nom, code-barres, SKU)..."
                   value="<?= h($search) ?>">
            <button type="submit" class="btn btn-outline-primary"><i class="bi bi-search"></i></button>
            <a href="<?= h('articles.php') ?>" class="btn btn-outline-secondary" id="resetBtn" style="<?= $search ? '' : 'display:none' ?>">Réinit.</a>
        </form>
    </div>
    <div class="card-body p-0">
        <div id="apiStatus" class="text-center text-muted py-1 d-none" style="font-size:.8rem">
            <i class="bi bi-cloud-arrow-up"></i> Mode API - données chargées en temps réel
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nom</th><th>Code-barres</th><th>SKU</th>
                        <th class="text-end">Prix vente</th><th class="text-center">Stock</th>
                        <th>Rayon</th><th>Fournisseur</th><th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody id="articlesTbody">
                <?php if (!$articles): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucun article.</td></tr>
                <?php else: foreach ($articles as $a):
                    $en_alerte = is_stock_low((int)$a['quantite_stock'], (int)$a['seuil_alerte']);
                ?>
                    <tr class="<?= $en_alerte ? 'alert-stock-low' : '' ?>">
                        <td class="fw-semibold"><?= h($a['nom']) ?></td>
                        <td><code><?= h($a['code_barre']) ?></code></td>
                        <td><?= h($a['sku'] ?: ' ') ?></td>
                        <td class="text-end"><?= money($a['prix_vente']) ?></td>
                        <td class="text-center"><?= stock_badge((int)$a['quantite_stock'], (int)$a['seuil_alerte']) ?></td>
                        <td><?= h($a['emplacement'] ?: ' ') ?></td>
                        <td><?= h($a['fournisseur_nom'] ?: ' ') ?></td>
                        <td class="text-center text-nowrap">
                            <a href="<?= h(generate_signed_url('articles.php', (int)$a['id'], ['action' => 'editer'])) ?>"
                               class="btn btn-sm btn-outline-primary" title="Modifier">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="post" action="<?= h(page_url('articles', ['action' => 'supprimer'])) ?>" style="display:inline"
                                  onsubmit="return confirm('Désactiver cet article ?')">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Désactiver">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<div id="paginationZone">
<?php 
$pagination_params = $search !== '' ? ['q' => $search] : [];
$base_url = page_url('articles', $pagination_params);
pagination_links($result['page'], $result['total_pages'], $base_url); 
?>
</div>

<script nonce="<?= h(csp_nonce()) ?>">
(function() {
    const searchForm   = document.getElementById('searchForm');
    const searchInput  = document.getElementById('searchInput');
    const tbody        = document.getElementById('articlesTbody');
    const apiStatus    = document.getElementById('apiStatus');
    const resetBtn     = document.getElementById('resetBtn');
    const paginationEl = document.getElementById('paginationZone');
    const BASE         = window.BASE_URL || '/eSotck/';

    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const q = searchInput.value.trim();
            loadArticles(q);
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            searchInput.value = '';
            loadArticles('');
        });
    }

    function loadArticles(q) {
        tbody.innerHTML = '<tr><td colspan="8" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Chargement...</td></tr>';
        if (paginationEl) paginationEl.innerHTML = '';
        if (apiStatus) apiStatus.classList.remove('d-none');
        
        const url = BASE + 'api/articles' + (q ? '?q=' + encodeURIComponent(q) : '');
        fetch(url)
            .then(function(r) {
                if (r.status === 401) {
                    window.location.href = BASE + 'auth/login.php';
                    throw new Error('Non autorisé');
                }
                if (!r.ok) throw new Error('Erreur HTTP ' + r.status);
                return r.json();
            })
            .then(function(data) {
                const articles = data.data || [];
                if (articles.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">Aucun article trouvé.</td></tr>';
                    return;
                }
                tbody.innerHTML = articles.map(function(a) {
                    var stock = parseInt(a.quantite_stock, 10) || 0;
                    var seuil = parseInt(a.seuil_alerte, 10) || 0;
                    var alerteClass = stock <= seuil ? ' alert-stock-low' : '';
                    var badgeClass = stock <= seuil ? 'danger' : 'secondary';
                    var editUrl = a.edit_url || (BASE + 'articles.php?action=editer&id=' + a.id);
                    
                    return '<tr class="' + alerteClass + '">'
                        + '<td class="fw-semibold">' + esc(a.nom) + '</td>'
                        + '<td><code>' + esc(a.code_barre) + '</code></td>'
                        + '<td>' + esc(a.sku || ' ') + '</td>'
                        + '<td class="text-end">' + esc(a.prix_vente) + '</td>'
                        + '<td class="text-center"><span class="badge text-bg-' + badgeClass + '">' + stock + '</span></td>'
                        + '<td>' + esc(a.emplacement || ' ') + '</td>'
                        + '<td>' + esc(a.fournisseur_nom || ' ') + '</td>'
                        + '<td class="text-center text-nowrap">'
                        + '<a href="' + editUrl + '" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil"></i></a>'
                        + '</td></tr>';
                }).join('');
            })
            .catch(function(err) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4">Erreur : ' + esc(err.message) + '</td></tr>';
            });
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>