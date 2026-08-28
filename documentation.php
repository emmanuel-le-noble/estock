<?php
/**
 * documentation.php — Guide utilisateur intégré, par rôle.
 *
 * Affiche, pour chaque rôle de l'application (Vendeur, Magasinier, Admin,
 * Directeur), les modules accessibles et la marche à suivre pour chacun.
 */
require_once __DIR__ . '/config/connexion.php';
require_once __DIR__ . '/includes/db_functions.php';
require_once __DIR__ . '/includes/helpers.php';
exiger_connexion();

$u         = user_courant();
$role      = $u['role'] ?? '';
$magasin_id = user_magasin_id();
$nom_magasin = '—';
if ($magasin_id > 0) {
    foreach (db_magasins_list($pdo) as $m) {
        if ((int)$m['id'] === $magasin_id) { $nom_magasin = $m['nom']; break; }
    }
}

$guides = [
    'Vendeur' => [
        'role_nom'   => 'Vendeur',
        'icon'       => 'bi-person-badge',
        'couleur'    => 'primary',
        'intro'      => 'Vous travaillez à la caisse et au contact des clients. Vous encaissez les ventes, consultez le stock et gérez les retours.',
        'modules'    => [
            [
                'icon' => 'bi-cash-coin', 'page' => 'caisse',
                'titre' => 'Caisse (POS)',
                'description' => 'Votre outil principal : scanner les articles, encaisser les clients et imprimer le ticket.',
                'etapes' => [
                    'Ouvrez la Caisse depuis le menu Ventes & Caisse (ou le bouton vert en haut à droite).',
                    'Scannez le code-barres de chaque article avec la douchette (ou saisissez le code manuellement puis Entrée). L\'article est ajouté au panier instantanément.',
                    'Le prix peut être ajusté si besoin ; vérifiez le total affiché.',
                    'Choisissez le mode de paiement (espèces, carte, mobile money). Les boutons de paiement rapide (2 000, 5 000, 10 000 FCFA) accélèrent l\'encaissement.',
                    'Validez la vente (touche F8) : le stock est décrémenté automatiquement et le ticket 80 mm est imprimé.',
                    'Raccourcis : F2 = vider le panier, F8 = valider. En cas de panne réseau, la vente est mise en file d\'attente et synchronisée plus tard.',
                ],
            ],
            [
                'icon' => 'bi-receipt', 'page' => 'factures',
                'titre' => 'Factures',
                'description' => 'Retrouvez toutes les ventes du magasin, consultez-les et réimprimez un ticket.',
                'etapes' => [
                    'Cherchez une facture par numéro, date, statut ou montant (filtres + pagination).',
                    'Cliquez sur « Voir » pour le détail de la facture (articles, paiements, monnaie rendue).',
                    'Bouton « Imprimer » : réimpression du ticket thermique depuis la vue détaillée.',
                    'Une facture annulée peut être restaurée si la caisse n\'est pas encore clôturée.',
                ],
            ],
            [
                'icon' => 'bi-boxes', 'page' => 'stock',
                'titre' => 'Consultation du stock',
                'description' => 'Vérifiez le stock disponible du magasin en temps réel (recherche par nom ou code-barres).',
                'etapes' => [
                    'Recherchez un article par nom ou code-barres.',
                    'Consultez la quantité disponible, le prix de vente et l\'état d\'alerte (stock faible signalé en rouge).',
                    'Les articles périmés sont bloqués en caisse : signalez-les au magasinier.',
                ],
            ],
            [
                'icon' => 'bi-calendar-week', 'page' => 'peremptions',
                'titre' => 'Péremptions (DLC/DDM)',
                'description' => 'Liste des articles dont la date limite approche ou est dépassée, pour les écouler ou les retirer.',
                'etapes' => [
                    'Consultez la liste des lots expirés (rouge) et urgents (orange).',
                    'En caisse, un article urgent affiche un message avant validation ; un article expiré ne peut pas être vendu.',
                    'Informez le magasinier pour qu\'il sorte les articles expirés du rayon.',
                ],
            ],
            [
                'icon' => 'bi-arrow-counterclockwise', 'page' => 'retours',
                'titre' => 'Retours / SAV',
                'description' => 'Traitez les retours clients : remboursement ou avoir, avec réintégration du stock.',
                'etapes' => [
                    'Cliquez sur « Nouveau retour » et sélectionnez la facture d\'origine (ou saisissez son numéro).',
                    'Choisissez les articles à retourner et la quantité (max 100 % de la quantité vendue).',
                    'Sélectionnez le motif (défectueux, erreur de caisse, insatisfaction…) et le type : retour pour remboursement, ou avoir.',
                    'Validez : le stock est réintégré automatiquement et le retour est journalisé.',
                ],
            ],
            [
                'icon' => 'bi-percent', 'page' => 'promotions',
                'titre' => 'Promotions & codes promo',
                'description' => 'Consultez les promotions en cours et appliquez les codes promo en caisse.',
                'etapes' => [
                    'Vérifiez les promotions actives (pourcentage ou montant fixe, par article ou catégorie).',
                    'En caisse, saisissez le code promo (ex. BIENVENUE10) : la remise est appliquée automatiquement si les conditions sont remplies (montant minimum, dates…).',
                ],
            ],
            [
                'icon' => 'bi-lock-fill', 'page' => 'cloture',
                'titre' => 'Clôture de caisse (Z)',
                'description' => 'En fin de journée, vérifiez que le montant en caisse correspond aux ventes du jour.',
                'etapes' => [
                    'Ouvrez « Clôture de Caisse » depuis le menu Ventes & Caisse.',
                    'Saisissez le montant réel compté dans le tiroir-caisse.',
                    'L\'écart avec le montant attendu est calculé : tout écart doit être justifié.',
                    'Validez la clôture : la journée est verrouillée (plus d\'annulation de facture).',
                ],
            ],
            [
                'icon' => 'bi-person-gear', 'page' => 'profil',
                'titre' => 'Mon profil',
                'description' => 'Mettez à jour vos informations personnelles et votre mot de passe.',
                'etapes' => [
                    'Cliquez sur votre nom en bas du menu latéral.',
                    'Modifiez vos coordonnées et/ou changez votre mot de passe (confirmation requise).',
                ],
            ],
        ],
    ],
    'Magasinier' => [
        'role_nom'   => 'Magasinier',
        'icon'       => 'bi-box-seam',
        'couleur'    => 'success',
        'intro'      => 'Vous êtes responsable du stock : réception des marchandises, tenue des rayons, inventaires et commandes fournisseurs.',
        'modules'    => [
            [
                'icon' => 'bi-boxes', 'page' => 'stock',
                'titre' => 'Stock',
                'description' => 'Consultez le stock du magasin : quantités, alertes, prix et DLC par lot.',
                'etapes' => [
                    'Filtrez par recherche (nom, code-barres) ou par statut (stock faible, rupture).',
                    'Cliquez sur un article pour voir le détail : quantités par magasin, lots DLC/DDM, prix.',
                ],
            ],
            [
                'icon' => 'bi-box-seam', 'page' => 'articles',
                'titre' => 'Articles',
                'description' => 'Créez et maintenez le référentiel des produits (articles de votre magasin).',
                'etapes' => [
                    'Cliquez sur « Nouvel article » : renseignez nom, référence, catégorie, prix d\'achat et de vente, TVA, seuil d\'alerte.',
                    'Générez le code-barres EAN-13 automatiquement (bouton « Générer ») ou saisissez un code existant.',
                    'Pour les produits périssables : définissez la DLC/DDM (gestion par lots FEFO à l\'entrée en stock).',
                    'Enregistrez : le stock initial peut être saisi immédiatement (mouvement d\'entrée).',
                ],
            ],
            [
                'icon' => 'bi-tags', 'page' => 'categories',
                'titre' => 'Catégories',
                'description' => 'Organisez les articles par famille (Épicerie, Boissons, Frais, Hygiène…) pour faciliter recherche et remises.',
                'etapes' => [
                    'Créez une catégorie avec un nom et une couleur.',
                    'Désactivez une catégorie pour la masquer (les articles existants restent conservés).',
                ],
            ],
            [
                'icon' => 'bi-truck', 'page' => 'fournisseurs',
                'titre' => 'Fournisseurs',
                'description' => 'Gérez vos fournisseurs : coordonnées, informations de facturation.',
                'etapes' => [
                    'Ajoutez un fournisseur (nom, contact, téléphone, e-mail, adresse).',
                    'Utilisez la même fiche pour toutes les commandes d\'achat.',
                ],
            ],
            [
                'icon' => 'bi-cart-plus', 'page' => 'commandes_fournisseur',
                'titre' => 'Commandes d\'achat',
                'description' => 'Cycle complet : créez la commande, soumettez-la, puis réceptionnez la marchandise à l\'arrivée.',
                'etapes' => [
                    '« Nouvelle commande » : choisissez le fournisseur, ajoutez les articles et les quantités (ou pré-remplissez depuis les Suggestions d\'achat).',
                    'Enregistrez en brouillon, puis « Soumettre » : la commande passe en attente de validation par le chef équipe.',
                    'Vous pouvez aussi l\'envoyer directement (« Envoyer ») selon les règles de l\'entreprise.',
                    'À la livraison : « Réceptionner » la commande, corrigez éventuellement les quantités reçues (réception partielle possible), renseignez les DLC des lots pour les périssables.',
                    'La validation de réception réintègre le stock automatiquement (mouvement Entrée).',
                ],
            ],
            [
                'icon' => 'bi-bag-check', 'page' => 'suggestions_achat',
                'titre' => 'Suggestions d\'achat',
                'description' => 'Liste automatique des articles sous le seuil d\'alerte, regroupés par fournisseur.',
                'etapes' => [
                    'Consultez les articles à réapprovisionner (quantité consommable, hors DLC bloquées).',
                    'Cliquez « Créer la commande » : la commande fournisseur est pré-remplie avec les quantités suggérées.',
                ],
            ],
            [
                'icon' => 'bi-clipboard-check', 'page' => 'inventaire',
                'titre' => 'Inventaire physique',
                'description' => 'Comptage des articles en rayon pour vérifier et corriger le stock théorique.',
                'etapes' => [
                    '« Nouvel inventaire » : la session démarre sur votre magasin avec une référence unique.',
                    'Saisissez le comptage de chaque article (écart = compté − théorique).',
                    '« Valider » : les écarts sont appliqués au stock automatiquement (mouvement d\'ajustement) et journalisés.',
                    'Consultez l\'historique des sessions validées et annulées, exportables en CSV.',
                ],
            ],
            [
                'icon' => 'bi-arrow-left-right', 'page' => 'mouvements',
                'titre' => 'Mouvements de stock',
                'description' => 'Historique complet et saisie manuelle d\'entrées (achats) et sorties (pertes, casses, usage interne).',
                'etapes' => [
                    'Saisissez une sortie (motif, quantité) pour les pertes ou casses : le stock est décrémenté.',
                    'Saisissez une entrée pour un retour de marchandise ou un don éventuel.',
                    'Consultez l\'historique filtré par type, période et magasin.',
                ],
            ],
            [
                'icon' => 'bi-calendar-week', 'page' => 'peremptions',
                'titre' => 'Péremptions (DLC/DDM)',
                'description' => 'Surveillez les lots qui expirent pour les écouler en priorité (méthode FEFO : premier expiré, premier sorti).',
                'etapes' => [
                    'Liste des lots expirés et urgents de votre magasin.',
                    'Sortez les articles expirés du rayon et traitez-les (pertes via une sortie de stock).',
                    'Les suggestions d\'achat excluent automatiquement les lots bloqués (expirés).',
                ],
            ],
            [
                'icon' => 'bi-tag', 'page' => 'etiquettes',
                'titre' => 'Étiquettes rayon',
                'description' => 'Imprimez les étiquettes de gondole (code-barres EAN-13 + prix) sur papier A4.',
                'etapes' => [
                    'Sélectionnez les articles à étiqueter (ou la catégorie complète).',
                    'Choisissez le nombre d\'étiquettes par article.',
                    'Imprimez la page d\'étiquettes (format A4, prête à découper).',
                ],
            ],
            [
                'icon' => 'bi-arrow-repeat', 'page' => 'transferts',
                'titre' => 'Transferts inter-magasins',
                'description' => 'Déplacez du stock entre votre magasin et un autre (mutualisation ou rééquilibrage).',
                'etapes' => [
                    '« Nouveau transfert » : magasin destinataire, articles et quantités.',
                    '« Expédier » : le stock sort de votre magasin (mouvement Transfert).',
                    'Le magasin destinataire réceptionne : le stock y entre lorsque le transfert est « Reçu ».',
                    'Suivez l\'état des transferts expédiés/reçus dans l\'historique.',
                ],
            ],
            [
                'icon' => 'bi-wallet2', 'page' => 'depenses',
                'titre' => 'Dépenses',
                'description' => 'Enregistrez les dépenses d\'exploitation de votre magasin (transport, fournitures, entretien…).',
                'etapes' => [
                    '« Nouvelle dépense » : titre, catégorie, montant, date, description.',
                    'La dépense est rattachée à votre magasin actif.',
                    'Les totaux alimentent le compte de résultat des statistiques.',
                ],
            ],
        ],
    ],
    'Admin' => [
        'role_nom'   => 'Admin',
        'icon'       => 'bi-shield-lock',
        'couleur'    => 'warning',
        'intro'      => 'Vous pilotez l\'application : utilisateurs, magasins, paramètres, dépenses et supervision de toutes les activités (sauf le journal d\'audit réservé au chef équipe).',
        'modules'    => [
            [
                'icon' => 'bi-people', 'page' => 'utilisateurs',
                'titre' => 'Utilisateurs',
                'description' => 'Créez et gérez les comptes des employés : rôle (Vendeur, Magasinier, Admin, chef équipe) et magasin d\'affectation.',
                'etapes' => [
                    '« Nouvel utilisateur » : nom, prénom, identifiant, mot de passe, rôle et magasin d\'affectation (obligatoire).',
                    'Un utilisateur ne voit et ne gère que son magasin (règle multi-magasins).',
                    'Désactivez un compte pour bloquer l\'accès sans le supprimer.',
                    'Le mot de passe est toujours haché (BCRYPT) : aucun accès aux mots de passe en clair.',
                ],
            ],
            [
                'icon' => 'bi-shop', 'page' => 'magasins',
                'titre' => 'Magasins',
                'description' => 'Gérez la liste des magasins (points de vente) et leur activation.',
                'etapes' => [
                    'Ajoutez un magasin (nom, adresse). Le stock y est suivi séparément.',
                    'Désactivez un magasin : ses utilisateurs sont verrouillés jusqu\'à réactivation.',
                ],
            ],
            [
                'icon' => 'bi-gear', 'page' => 'parametres',
                'titre' => 'Paramètres',
                'description' => 'Configurez la boutique : identité, devise, TVA, ticket, paiements rapides, couleurs.',
                'etapes' => [
                    'Onglet Boutique : nom, slogan, coordonnées, devise (FCFA), pays.',
                    'Onglet Taxes : activation de la TVA et taux par défaut (chaque article peut avoir son propre taux).',
                    'Onglet Ticket : format 80 mm, en-tête, remarques, boutons de paiement rapide.',
                    'Onglet Thème : couleur de l\'interface.',
                ],
            ],
            [
                'icon' => 'bi-wallet2', 'page' => 'depenses',
                'titre' => 'Dépenses',
                'description' => 'Consultez et saisissez toutes les dépenses, filtrables par période et catégorie.',
                'etapes' => [
                    'Filtrez par période (défaut : mois en cours) et consultez la répartition par catégorie.',
                    'Saisissez les nouvelles charges : la dépense est rattachée au magasin actif.',
                ],
            ],
            [
                'icon' => 'bi-graph-up', 'page' => 'statistiques',
                'titre' => 'Statistiques & compte de résultat',
                'description' => 'Analysez le chiffre d\'affaires, les marges, les top ventes et le résultat sur la période.',
                'etapes' => [
                    'Choisissez la période et le magasin.',
                    'Consultez CA, nombre de ventes, panier moyen, marge brute et TVA collectée.',
                    'Compte de résultat : CA − achats de la période − dépenses = résultat net.',
                    'Exportez les données en CSV si nécessaire.',
                ],
            ],
            [
                'icon' => 'bi-box-seam', 'page' => 'articles',
                'titre' => 'Articles & référentiel',
                'description' => 'Même gestion que le Magasinier : création, modification, code-barres, TVA, DLC.',
                'etapes' => [
                    'Créez / corrigez les fiches articles (prix, TVA, seuil d\'alerte, DLC).',
                    'Supprimez un article (jamais s\'il a un historique de ventes).',
                ],
            ],
            [
                'icon' => 'bi-cart-plus', 'page' => 'commandes_fournisseur',
                'titre' => 'Commandes d\'achat',
                'description' => 'Créez, soumettez et réceptionnez les commandes fournisseurs comme le Magasinier.',
                'etapes' => [
                    'Suivez le cycle Brouillon → En attente → Envoyée → Reçue.',
                    'La validation finale reste du ressort du chef équipe (permission achats_valider).',
                ],
            ],
            [
                'icon' => 'bi-person-gear', 'page' => 'profil',
                'titre' => 'Mon profil',
                'description' => 'Modifiez vos informations et votre mot de passe.',
            ],
        ],
    ],
    'chef équipe' => [
        'role_nom'   => 'chef équipe',
        'icon'       => 'bi-award',
        'couleur'    => 'danger',
        'intro'      => 'Vous avez accès à l\'ensemble de l\'application, y compris le journal d\'audit et la validation des commandes d\'achat. Vous pouvez basculer votre magasin de suivi via le sélecteur en haut à droite.',
        'modules'    => [
            [
                'icon' => 'bi-speedometer2', 'page' => 'tableau_bord',
                'titre' => 'Tableau de bord',
                'description' => 'Vue d\'ensemble : CA du jour et du mois, ventes, alertes stock et péremptions.',
                'etapes' => [
                    'Consultez les indicateurs du jour et du mois (CA, ventes, paniers).',
                    'Surveillez les alertes de stock faible et de DLC imminente.',
                    'Changez de magasin de suivi via le sélecteur de la topbar pour comparer les points de vente.',
                ],
            ],
            [
                'icon' => 'bi-graph-up', 'page' => 'statistiques',
                'titre' => 'Statistiques & compte de résultat',
                'description' => 'Analyse complète de la performance : CA, marges, top ventes, résultat net.',
                'etapes' => [
                    'Période au choix (jour, mois, période personnalisée), magasin ou global.',
                    'Compte de résultat : CA − achats − dépenses = résultat net.',
                    'Graphiques Chart.js (évolution des ventes, répartition des paiements).',
                ],
            ],
            [
                'icon' => 'bi-cart-plus', 'page' => 'commandes_fournisseur',
                'titre' => 'Validation des commandes d\'achat',
                'description' => 'Approbation finale du cycle d\'achat : validez ou rejetez les commandes soumises par les magasiniers.',
                'etapes' => [
                    'Ouvrez « Commandes d\'achat » et filtrez sur le statut « En attente ».',
                    'Consultez le détail (fournisseur, articles, quantités, montants).',
                    '« Valider » : la commande passe à Envoyée (produite au fournisseur).',
                    '« Rejeter » : retour au brouillon avec commentaires pour le magasinier.',
                    'Suivez les réceptions pour vérifier que le stock arrive bien.',
                ],
            ],
            [
                'icon' => 'bi-clock-history', 'page' => 'audit',
                'titre' => 'Journal d\'activité (audit)',
                'description' => 'Traçabilité intégrale des actions de tous les utilisateurs : connexions, ventes, modifications, clôtures.',
                'etapes' => [
                    'Filtrez par période, utilisateur, magasin ou type d\'action.',
                    'Consultez les détails (auteur, magasin, IP, date, description).',
                    'Aucune donnée sensible (mot de passe, jeton) n\'est journalisée.',
                ],
            ],
            [
                'icon' => 'bi-people', 'page' => 'utilisateurs',
                'titre' => 'Utilisateurs & rôles',
                'description' => 'Gérez les comptes et leurs droits (via Paramètres → Permissions).',
                'etapes' => [
                    'Créez / modifiez les comptes avec rôle et magasin d\'affectation.',
                    'Affinez la matrice des permissions par rôle dans Paramètres (RBAC dynamique, sans code).',
                ],
            ],
            [
                'icon' => 'bi-shop', 'page' => 'magasins',
                'titre' => 'Magasins',
                'description' => 'Créez et activez/désactivez les magasins, suivez le stock de chaque point de vente.',
            ],
            [
                'icon' => 'bi-gear', 'page' => 'parametres',
                'titre' => 'Paramètres boutique',
                'description' => 'Identité, devise, TVA, ticket, paiements rapides et matrice des permissions.',
                'etapes' => [
                    'Onglet Boutique : nom, slogan, coordonnées, devise, TVA.',
                    'Onglet Ticket : format, en-tête, remarques, montants rapides.',
                    'Onglet Permissions : cochez/décochez les droits de chaque rôle (appliqué immédiatement).',
                ],
            ],
            [
                'icon' => 'bi-receipt', 'page' => 'factures',
                'titre' => 'Factures',
                'description' => 'Consultez toutes les factures et annulez/restaurez si nécessaire (avant clôture).',
                'etapes' => [
                    'Filtrez et consultez les factures de tous les magasins en basculant le magasin de suivi.',
                    'Annulez une facture en erreur (le stock est réintégré) tant que la clôture du jour n\'est pas faite.',
                ],
            ],
            [
                'icon' => 'bi-clipboard-check', 'page' => 'inventaire',
                'titre' => 'Inventaires & transferts',
                'description' => 'Supervisez les comptages et les mouvements inter-magasins.',
                'etapes' => [
                    'Consultez les sessions d\'inventaire validées/annulées de vos magasins.',
                    'Validez les transferts reçus et suivez le stock par magasin.',
                ],
            ],
            [
                'icon' => 'bi-wallet2', 'page' => 'depenses',
                'titre' => 'Dépenses & charges',
                'description' => 'Contrôlez les dépenses de chaque magasin ; elles alimentent le compte de résultat.',
            ],
        ],
    ],
];

// Visibilité de la documentation selon le rôle courant :
// - Directeur  : tous les rôles
// - Admin      : Admin, Vendeur, Magasinier (pas le Directeur)
// - Magasinier : Magasinier uniquement
// - Vendeur    : Vendeur uniquement
$visibilite_doc = [
    'chef équipe'  => ['Vendeur', 'Magasinier', 'Admin', 'chef équipe'],
    'Admin'      => ['Vendeur', 'Magasinier', 'Admin'],
    'Magasinier' => ['Magasinier'],
    'Vendeur'    => ['Vendeur'],
];
$liste_roles = $visibilite_doc[$role] ?? [$role];

$titre_page = 'Documentation';
echo $twig->render('documentation.html.twig', [
    'titre_page'   => $titre_page,
    'liste_roles'  => $liste_roles,
    'guides'       => $guides,
    'role_courant' => $role,
    'nom_magasin'  => $nom_magasin,
]);