<?php
/**
 * letterhead.php — Fonctions réutilisables pour l'en-tête et pied de page des documents imprimables.
 *
 * Palette: rouge, orange, vert, blanc, noir
 * Layout: Logo à gauche, nom/description à droite → chevron vert → titre doc → contenu → footer → chevron rouge
 */

/**
 * Retourne les variables d'en-tête communes à tous les documents.
 */
function letterhead_vars(): array {
    $nom_boutique    = param_shop_name();
    $slogan          = trim(param('slogan_boutique', ''));
    $adresse         = trim(param('adresse_boutique', ''));
    $code_postal     = trim(param('code_postal', ''));
    $pays            = trim(param('pays', 'Togo'));
    $telephone       = trim(param('telephone_boutique', ''));
    $email           = trim(param('email_boutique', ''));
    $site            = trim(param('site_boutique', ''));
    $nif             = trim(param('nif_boutique', ''));
    $rccm            = trim(param('rccm_boutique', ''));
    $regime_tpu      = param_regime_tpu();
    $localisation    = trim(implode(' ', array_filter([$code_postal, $pays])));
    $description     = 'Production et fourniture d\'eau minérale et de jus de fruits, consommables informatiques, fournitures de bureau, Produits d\'entretien et prestation de services.';

    return compact(
        'nom_boutique', 'slogan', 'adresse', 'code_postal', 'pays',
        'telephone', 'email', 'site', 'nif', 'rccm', 'regime_tpu',
        'localisation', 'description'
    );
}

/**
 * Génère le HTML de l'en-tête: logo à gauche, nom/description à droite du logo.
 */
function letterhead_header(): string {
    $v = letterhead_vars();
    $logo_path = 'assets/images/logo-eStock-2.png';
    $desc = $v['slogan'] ?: $v['description'];

    return <<<HTML
<div class="doc-header">
    <div class="doc-brand">
        <img src="{$logo_path}" alt="Logo" class="doc-logo">
        <div class="doc-brand-info">
            <div class="doc-brand-name">{$v['nom_boutique']}</div>
            <div class="doc-brand-desc">{$desc}</div>
        </div>
    </div>
</div>
HTML;
}

/**
 * Génère le HTML du titre du document (Facture N° xxx, etc.) — à placer SOUS le chevron vert.
 *
 * @param string $title   Titre du document
 * @param string $num     Numéro ou référence
 * @param string $date    Date du document
 * @param string $badge   Classe CSS du badge
 * @param string $badge_text Texte du badge
 */
function letterhead_doc_title(string $title, string $num, string $date, string $badge = '', string $badge_text = ''): string {
    $badge_html = '';
    if ($badge && $badge_text) {
        $badge_html = '<span class="doc-badge ' . h($badge) . '">' . h($badge_text) . '</span>';
    }

    return <<<HTML
<div class="doc-title-bar">
    <div class="doc-title-label">{$title}</div>
    <div class="doc-title-info">
        <span class="doc-num">N° {$num}</span>
        <span class="doc-date">{$date}</span>
        {$badge_html}
    </div>
</div>
HTML;
}

/**
 * Génère le HTML du bandeau chevron supérieur (vert, sous l'en-tête).
 */
function letterhead_chevron_top(): string {
    return '<div class="doc-chevron-top"></div>';
}

/**
 * Génère le HTML du bandeau chevron inférieur (rouge, en bas de page).
 */
function letterhead_chevron_bottom(): string {
    return '<div class="doc-chevron-bottom"></div>';
}

/**
 * Génère le HTML du pied de page: texte contact/légal à gauche.
 */
function letterhead_footer(): string {
    $v = letterhead_vars();

    $address_line = $v['adresse'];
    if ($v['localisation']) {
        $address_line .= ($address_line ? ', ' : '') . $v['localisation'];
    }

    $contact_parts = [];
    if ($address_line)     $contact_parts[] = h($address_line);
    if ($v['telephone'])   $contact_parts[] = 'contact : ' . h($v['telephone']);
    if ($v['email'])       $contact_parts[] = 'email : <a href="mailto:' . h($v['email']) . '">' . h($v['email']) . '</a>';

    $contact_line = implode(' ; ', $contact_parts);

    $legal_parts = [];
    if ($v['nif'])  $legal_parts[] = 'NIF : ' . h($v['nif']);
    if ($v['rccm']) $legal_parts[] = 'N° RCCM : ' . h($v['rccm']);

    $regime = 'Réel avec TVA';
    $legal_line = 'Régime Fiscal : ' . $regime;
    if (!empty($legal_parts)) {
        $legal_line .= ' / ' . implode(' / ', $legal_parts);
    }

    return <<<HTML
<div class="doc-footer">
    <div class="doc-footer-text">{$contact_line}</div>
    <div class="doc-legal">{$legal_line}</div>
</div>
HTML;
}
