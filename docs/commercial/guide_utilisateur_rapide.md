# eStock — guide utilisateur rapide

## Avant de commencer

Le Directeur vérifie les paramètres de la boutique, crée les utilisateurs et
attribue un magasin à chaque compte. Chaque utilisateur doit utiliser son
identifiant personnel ; les comptes ne doivent pas être partagés.

## Vendeur : encaisser une vente

1. Ouvrir **Caisse** et vérifier le magasin affiché.
2. Scanner ou rechercher l'article, puis contrôler quantité et prix.
3. Identifier le client uniquement s'il souhaite utiliser la fidélité.
4. Saisir le ou les moyens de paiement ; le montant total doit être couvert.
5. Valider, remettre le ticket et ne pas modifier une vente après validation.
6. En fin de journée, effectuer la **Clôture de caisse** avec le montant réel.

## Magasinier : gérer le stock

1. Enregistrer les entrées fournisseur dans **Mouvements** ou réceptionner une
   commande fournisseur.
2. Saisir un lot et une date de péremption pour les produits concernés.
3. Consulter les alertes de stock faible et de péremption chaque jour.
4. Réaliser les inventaires physiques et faire valider les écarts.
5. Utiliser les **Transferts** pour déplacer un stock entre magasins ; ne pas
   effectuer deux sorties/entrées manuelles pour une même opération.

## Directeur : contrôle quotidien

1. Vérifier les ventes, les dépenses, les stocks faibles et les péremptions.
2. Contrôler les clôtures de caisse et les écarts signalés.
3. Vérifier les sauvegardes et traiter les incidents dans le journal d'audit.
4. Créer/désactiver les comptes utilisateurs et vérifier les permissions.

## Règles importantes

- Ne jamais partager un mot de passe ni modifier directement la base MySQL.
- Ne jamais supprimer une sauvegarde avant d'avoir testé sa restauration.
- En cas d'écart de stock ou de caisse, conserver les pièces justificatives et
  consigner l'action dans eStock.
