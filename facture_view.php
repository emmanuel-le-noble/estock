<?php
/**
 * facture_view.php - Consultation + impression d'une facture (ticket de caisse).
 */
// Guard: compatible avec le routeur front controller (index.php)
if (!function_exists('est_connecte')) { require_once __DIR__ . '/config/connexion.php'; }
if (!function_exists('db_article_insert')) { require_once __DIR__ . '/includes/db_functions.php'; }
if (!function_exists('csrf_guard')) { require_once __DIR__ . '/includes/helpers.php'; }
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_role(ROLE_DIRECTEUR, ROLE_ADMIN, ROLE_VENDEUR);

$id = (int)($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';
$print_param = $_GET['print'] ?? '';
$verify_extra = $print_param !== '' ? ['print' => $print_param] : [];
if ($id <= 0 || !verify_url_signature($id, $token, $verify_extra)) {
    flash_error('Lien invalide ou expiré.');
    redirect('factures.php');
}

// La facture
$f = db_facture_get_by_id($pdo, $id);
if (!$f) { flash_error('Facture introuvable.'); redirect('factures.php'); }

if (user_role() === ROLE_VENDEUR && (int)($f['utilisateur_id'] ?? 0) !== (int)(user_courant()['id'] ?? 0)) {
    flash_error('Vous n\'avez pas accès à cette facture.');
    redirect('factures.php');
}

// Les lignes
$lignes = db_facture_get_lignes($pdo, $id);

// Paiements multi-modes
$paiements = [];
if (function_exists('db_paiements_by_facture')) {
    $paiements = db_paiements_by_facture($pdo, $id);
}

// Calculer le total des remises pour cette facture
$totalRemises = 0.0;
foreach ($lignes as $l) {
    if (!empty($l['prix_original']) && !empty($l['remise_pct'])) {
        $prixOriginal = (float)$l['prix_original'];
        $prixRemise = (float)$l['prix_unitaire'];
        $qte = (int)$l['quantite'];
        $totalRemises += ($prixOriginal - $prixRemise) * $qte;
    }
}
$totalRemises = round($totalRemises, 2);

// Calcul TVA multi-taux à partir des lignes
$tauxTvaResume = [];
foreach ($lignes as $l) {
    $htLigne = calc_line_subtotal((float)$l['prix_unitaire'], (int)$l['quantite']);
    $tauxLigne = ($l['taux_tva'] !== null && $l['taux_tva'] !== '') ? (float)$l['taux_tva'] : (float)$f['tva_taux'];
    $tvaLigne  = $htLigne * ($tauxLigne / 100);
    $tauxKey   = number_format($tauxLigne, 2);
    if (!isset($tauxTvaResume[$tauxKey])) {
        $tauxTvaResume[$tauxKey] = ['taux' => $tauxLigne, 'base_ht' => 0.0, 'montant_tva' => 0.0];
    }
    $tauxTvaResume[$tauxKey]['base_ht']      += $htLigne;
    $tauxTvaResume[$tauxKey]['montant_tva']  += $tvaLigne;
}
$multiTaux = count($tauxTvaResume) > 1;

$impression = isset($_GET['print']);

$nom_boutique = param_shop_name();
$adresse_boutique = trim(param('adresse_boutique', ''));
$code_postal = trim(param('code_postal', ''));
$pays = trim(param('pays', ''));
$telephone_boutique = trim(param('telephone_boutique', ''));
$email_boutique = trim(param('email_boutique', ''));
$site_boutique = trim(param('site_boutique', ''));
$ticket_entete = trim(param('ticket_entete', 'Merci de votre visite !'));
$ticket_remarque = trim(param('ticket_remarque', ''));
$ticket_format = param('ticket_format', '80mm') === '58mm' ? '58mm' : '80mm';
$localisation = trim(implode(' ', array_filter([$code_postal, $pays])));

$titre_page = 'Facture ' . $f['numero_facture'];
include __DIR__ . '/includes/header.php';
?>

<div class="no-print mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <a href="<?= h('factures.php') ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> Retour à la liste
    </a>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Imprimer / Réimprimer
        </button>
        <a href="<?= h('ticket_print.php?facture_id=' . $id) ?>" class="btn btn-outline-dark" target="_blank">
            <i class="bi bi-receipt"></i> Ticket thermique
        </a>
        <?php if (peut_facturer() && $f['statut'] === 'Payee'): ?>
            <a href="<?= h('caisse.php') ?>" class="btn btn-success"><i class="bi bi-cart-plus"></i> Nouvelle vente</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($f['statut'] === 'Annulee'): ?>
<div class="no-print alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> Cette facture a été annulée.
</div>
<?php endif; ?>

<!-- ============ TICKET DE CAISSE ============ -->
<div class="ticket ticket-<?= h($ticket_format) ?>" id="ticket">
    <div class="text-center">
        <h2><?= h($nom_boutique) ?></h2>
        <?php if ($adresse_boutique): ?><div><?= h($adresse_boutique) ?></div><?php endif; ?>
        <?php if ($localisation): ?><div><?= h($localisation) ?></div><?php endif; ?>
        <?php if ($telephone_boutique): ?><div>Tél : <?= h($telephone_boutique) ?></div><?php endif; ?>
        <?php if ($email_boutique): ?><div><?= h($email_boutique) ?></div><?php endif; ?>
        <div class="hr-dashed"></div>
    </div>

    <div>
        <div><strong>Ticket N° :</strong> <?= h($f['numero_facture']) ?></div>
        <div><strong>Date :</strong> <?= h(date_fr($f['date_facture'])) ?></div>
        <div><strong>Vendeur :</strong> <?= h($f['vendeur_nom'] ?: '—') ?></div>
    </div>

    <div class="hr-dashed"></div>

    <table>
        <thead>
            <tr>
                <th align="left">Article</th>
                <th align="center">Qté</th>
                <?php if ($totalRemises > 0): ?>
                <th align="right"><s>P.U.</s></th>
                <th align="center">Rem.</th>
                <?php endif; ?>
                <th align="right">P.U.</th>
                <th align="right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lignes as $l):
                $st = calc_line_subtotal((float)$l['prix_unitaire'], (int)$l['quantite']);
                $hasRemise = !empty($l['prix_original']) && !empty($l['remise_pct']) && (float)$l['remise_pct'] > 0;
            ?>
            <tr>
                <td><?= h($l['article_nom']) ?></td>
                <td align="center"><?= (int)$l['quantite'] ?></td>
                <?php if ($totalRemises > 0): ?>
                <td align="right"><?= $hasRemise ? '<s>' . h(money((float)$l['prix_original'])) . '</s>' : '' ?></td>
                <td align="center"><?= $hasRemise ? '-' . h(number_format((float)$l['remise_pct'], 0, ',', '')) . '%' : '—' ?></td>
                <?php endif; ?>
                <td align="right"><?= h(money((float)$l['prix_unitaire'])) ?></td>
                <td align="right"><?= h(money((float)$st)) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="hr-dashed"></div>

    <table>
        <tr>
            <td>Total HT</td>
            <td align="right"><?= h(money((float)$f['total_ht'])) ?></td>
        </tr>
        <?php if ($multiTaux): ?>
        <!-- Ventilation TVA multi-taux -->
        <?php foreach ($tauxTvaResume as $tv): ?>
        <tr>
            <td>TVA <?= h(number_format($tv['taux'], 2, ',', '')) ?>% (base <?= h(money($tv['base_ht'])) ?>)</td>
            <td align="right"><?= h(money($tv['montant_tva'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php elseif ((float)$f['tva_taux'] > 0 && !empty($tauxTvaResume)): ?>
        <tr>
            <td>TVA (<?= h(number_format((float)$f['tva_taux'], 2, ',', ' ')) ?>%)</td>
            <td align="right"><?= h(money((float)$f['total_ttc'] - (float)$f['total_ht'])) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($totalRemises > 0): ?>
        <tr>
            <td style="color:#059669">Total Remises</td>
            <td align="right" style="color:#059669">−<?= h(number_format($totalRemises, 0, ',', ' ')) ?> <?= h(param('devise_symbole', 'FCFA')) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td><strong>TOTAL TTC</strong></td>
            <td align="right"><strong><?= h(money((float)$f['total_ttc'])) ?></strong></td>
        </tr>
    </table>

    <div class="hr-dashed"></div>

    <table>
    <?php if (!empty($paiements)): ?>
        <tr><td colspan="2"><strong>PAIEMENTS</strong></td></tr>
        <?php foreach ($paiements as $p): ?>
        <tr>
            <td><?= h(ucfirst(str_replace('_', ' ', $p['mode_paiement'] ?? ''))) ?></td>
            <td align="right"><?= h(money((float)$p['montant'])) ?></td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
        <tr>
            <td>Reçu</td>
            <td align="right"><?= h(money((float)$f['montant_paye'])) ?></td>
        </tr>
        <tr>
            <td>Rendu</td>
            <td align="right"><?= h(money((float)$f['monnaie_rendue'])) ?></td>
        </tr>
    </table>

    <div class="hr-dashed"></div>

    <div class="text-center">
        <?php if ($ticket_entete): ?><p style="margin:4px 0;"><?= h($ticket_entete) ?></p><?php endif; ?>
        <?php if ($ticket_remarque): ?><p style="margin:4px 0;"><?= h($ticket_remarque) ?></p><?php endif; ?>
        <?php if ($site_boutique): ?><p style="margin:0;"><?= h($site_boutique) ?></p><?php endif; ?>
    </div>
</div>

<?php if ($impression): ?>
<script nonce="<?= h(csp_nonce()) ?>">
    // Lancement automatique de l'impression si ?print=1
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 300);
    });
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
