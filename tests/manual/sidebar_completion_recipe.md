# Repli des sections terminées — recette navigateur

À exécuter avec des comptes et contenus de test, sur ordinateur puis en viewport mobile.
Les tests PHP vérifient le rendu serveur ; ils ne valident pas Bootstrap, Stimulus ou Turbo.

1. Préparer une section de plusieurs cours, une section vide et deux utilisateurs. Valider tous les cours sauf le premier : la section reste ouverte. Valider le premier : après la redirection réussie, elle est repliée sur ordinateur et dans le panneau mobile. Le titre de section reste surligné. La section vide reste ouverte.
2. Cliquer sur « Terminé » : après l'enregistrement et la redirection, la section est ouverte. Sur une section incomplète, tenter une validation avec un jeton CSRF invalide (outils réseau) : réponse 403, aucun cours enregistré comme terminé. Revenir au cours : section toujours incomplète. Tester également une panne réseau, sans anticiper le repli au clic.
3. Consulter une section terminée depuis le sommaire, une page de section, un lien direct et la recherche : elle reste repliée, même avec un cours actif. Vérifier le second utilisateur : sa progression est indépendante.
4. Déplier manuellement une section terminée : elle reste ouverte pendant la page courante, y compris après fermeture/réouverture du panneau mobile. Naviguer vers un autre cours puis revenir, et recharger : le repli par défaut revient. Une section incomplète est ouverte par défaut.
5. Avec Turbo actif, visiter successivement sommaire, section et cours avant de terminer la section. Après validation, utiliser Retour et Avance : les pages doivent demander un nouveau rendu au serveur et afficher la progression actuelle. Refaire après annulation. Vérifier dans Réseau que les retours ne restaurent pas un ancien instantané. Tester également après une ouverture manuelle et après plusieurs allers-retours rapides.
6. Vérifier la balise `turbo-cache-control=no-cache` uniquement sur les trois pages de formation. Naviguer vers une page ordinaire : la balise ne doit pas y rester. Turbo doit toujours intercepter les navigations habituelles. Aucun localStorage/sessionStorage de sidebar ne doit apparaître.
7. Sur les deux formats, tester les boutons de section au clic, avec Tab, Entrée et Espace : une seule bascule, flèche droite si fermé / bas si ouvert, `aria-expanded` cohérent et identifiants desktop/mobile distincts. Répéter après plusieurs navigations. Vérifier la fermeture du panneau mobile par Échap, clic extérieur et navigation.
8. Ajouter un nouveau cours non validé à une section terminée : au chargement suivant, elle est ouverte. Aucun filtre par type ou droits ne doit changer les cours comptés.
9. Terminer un quiz constituant le dernier cours restant, même à score nul : après le rechargement existant, section repliée. Recommencer le quiz ne supprime pas la validation. Corriger un exercice sans valider son cours : section toujours incomplète ; le bouton générique de validation conserve son rôle.

Statut initial : parcours navigateur à vérifier, cette recette ne constitue pas une preuve d'exécution.
