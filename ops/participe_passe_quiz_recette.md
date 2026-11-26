# Participe passé — recette locale des trois quiz

Recette du 26 novembre 2026 sur `preprod`, à la révision `90e4017`. Les quatre contrôles de l’importeur `app:quiz:manifest` ont réussi.

## Lot et relecture éditoriale

Les trois fichiers installés dans `resources/quiz/participe_passe/` sont identiques, octet par octet, aux entrées du ZIP. Le SHA-256 attendu et obtenu pour le manifeste est `6089f68be61d95305b8ce86198bf32a038018065bde957250ee44349a6151096`.

Le Word privé a été lu en mémoire depuis le ZIP. Son SHA-256 correspond à celui du manifeste. Pour chacune des 72 questions, les nœuds de texte de `word/document.xml` ont été réunis par paragraphe. La comparaison exacte a porté sur le thème, l’énoncé, les deux réponses avec leurs marques « vrai » ou « faux », et l’explication. Espaces, accents et ponctuation concordent. Chacun des 12 thèmes compte six originaux.

Les 72 empreintes `original_sha256` et les 65 empreintes `payload_sha256` ont été recalculées avec la convention vérifiée sur le manifeste du module 6. Le calcul utilise le SHA-256 du JSON UTF-8 compact, avec tri récursif des clés d’objet, ordre des listes conservé, caractères Unicode et barres obliques non échappés. Pour les copies, il porte sur les champs importés, dont le rôle de réserve et la position.

Les 65 entrées du catalogue du guide concordent avec les copies pour l’énoncé présenté, le thème, le rôle, la réponse et l’explication. L’ordre du premier passage et des réserves a été comparé au catalogue et aux tableaux par thème. Chaque numéro et chaque énoncé apparaissent une seule fois. Les 65 clés du Word sont conservées, avec 37 réponses « 0 erreur » et 28 réponses « 1 erreur ». Les cinq énoncés retouchés sont exactement ceux proposés pour Q18, Q51, Q52, Q55 et Q65. Les 60 autres sont identiques aux originaux. Pour les 28 phrases fautives, la correction figure dans l’explication et l’énoncé conserve la faute à identifier.

Q20, Q28, Q29, Q35, Q44, Q46 et Q72 ont le statut `on_hold`, une copie apprenant nulle et une liste d’affectations vide. Leurs numéros sont absents des initiales et des réserves.

### Décision validée pour Q29

« Veuillez trouver ci-annexé les certificats et attestations demandés. » vaut **0 erreur**. Devant « les certificats et attestations », en cours de phrase, « ci-annexé » peut rester invariable. L’accord « ci-annexés » est également admis. La clé historique « 1 erreur » est conservée uniquement dans l’original archivé. Q29 reste hors des trois quiz. [Académie française, « Ci-annexé, ci-inclus, ci-joint », point 3](https://www.dictionnaire-academie.fr/article/QDL022).

## Effectifs du lot et emplacements dans le sommaire

Les effectifs relus en SQL correspondent au manifeste. Stéphane a retrouvé dans le sommaire local les trois emplacements prévus par le guide.

| Quiz | Initiales | Réserves | Thèmes | Propositions | Emplacement vérifié par Stéphane |
| --- | ---: | ---: | ---: | ---: | --- |
| `participe_passe_test1` | 5 | 7 | 5 | 24 | Après la leçon sur « avoir », avant « Le participe passé suivi d’un infinitif (1) » |
| `participe_passe_test2` | 5 | 7 | 5 | 24 | Après la leçon sur « en », avant l’introduction aux verbes pronominaux |
| `participe_passe_general` | 16 | 25 | 12 | 82 | Après la leçon sur les verbes accidentellement pronominaux, en fin de section |
| **Total** | **26** | **39** | **12 distincts** | **130** | **65 questions** |

## Base jetable et contrôles SQL

La recette a utilisé `DictationTestDatabase` avec le profil `editorial`, dans l’environnement Symfony `test`. La connexion de création a renvoyé `NULL` pour `DATABASE()` sur le serveur MySQL local `STEF-LEGION`, version `8.4.7`, à l’adresse `127.0.0.1:3306`. Avant l’installation du schéma et chaque appel à l’importeur, la lecture de `DATABASE()`, `@@hostname` et `@@port` a confirmé la base créée pour cette intervention, `orthogram_editorial_206f8732441fccbc_test`. Les cinq options `--expect-*` de destination ont aussi été fournies à l’importeur.

Le témoin `grand_essai_final`, issu de `tests/Fixtures/Quiz/essai.json`, figurait déjà en base avec sa question et ses deux propositions lors de la première capture. Les comparaisons avant et après chaque opération ont utilisé `QuizReleaseSnapshot`, qui relit les lignes et la structure des 29 tables dans une transaction en lecture seule.

1. **Prévisualisation de l’import.** Le plan annonçait les trois quiz, 65 questions et 130 propositions à ajouter, avec zéro copie préexistante du lot. Les captures avant et après sont identiques sur les 29 tables.
2. **Premier import.** L’import a reçu `--apply` et l’empreinte exacte du plan prévisualisé, `df785c3989738722a45968fb761b0b5179bd29abd9b0516e85da707637efa288`. La comparaison des lignes a trouvé 3 nouveaux quiz, 65 questions, 130 propositions, 3 identités de manifeste et 65 traces de copie. Toutes les lignes préexistantes, dont le témoin complet, ont été retrouvées à l’identique. Pour chaque quiz, la relecture SQL a confirmé `renewal_enabled = 0`, un quota `NULL` et zéro cours rattaché. Pour chaque question, le rapprochement par clé source et position a confirmé le rôle, le thème, l’énoncé, l’explication et les deux propositions ordonnées avec leur clé de correction. Le regroupement SQL par quiz et thème concorde avec chaque ligne des matrices du guide.
3. **Deuxième import.** Le nouveau plan a reconnu les 65 copies et prévu zéro ajout. Son application avec `--expect-plan` a renvoyé `noop: true`. Les captures des 29 tables sont identiques avant la prévisualisation, après celle-ci et après l’application.
4. **Prévisualisation du renouvellement.** L’action `enable`, lancée sans `--apply`, ciblait exclusivement `participe_passe_test1`, `participe_passe_test2` et `participe_passe_general` (identifiants jetables 2, 3 et 4). Une nouvelle lecture de `quiz.renewal_enabled` a renvoyé 0 pour chacun et pour le témoin. La capture complète est identique à celle du premier import.

Seule la base jetable créée pour cette recette a été supprimée. La requête sur son nom exact dans `information_schema.schemata` a ensuite renvoyé zéro ligne correspondante.

## Import sur la base de travail locale

Sur `orthogram` en environnement `dev`, le plan `7747e69dac7d80912795b1bb9f8400b4c7f70717cfc502f541115803cca95ec1` a ajouté 65 questions et 130 propositions. La prévisualisation suivante a reconnu les 65 copies, avec zéro ajout et aucun écart. Immédiatement après l’import, la relecture SQL a identifié les quiz 24, 25 et 26, tous en mode fixe, avec un quota `NULL` et zéro cours rattaché.

## Recette de Stéphane en navigateur local

Stéphane a vérifié les trois emplacements du sommaire indiqués plus haut et les compteurs côté apprenant. Il a ensuite activé le renouvellement en administration sur les trois quiz. Cette activation est un réglage de la base locale. Le manifeste conserve le mode fixe à l’import.

| ID local | Clé | Questions par passage affichées | Réserves affichées | Contrainte d’équilibre |
| ---: | --- | ---: | ---: | --- |
| 24 | `participe_passe_test1` | 5 | 7 | Aucune |
| 25 | `participe_passe_test2` | 5 | 7 | Aucune |
| 26 | `participe_passe_general` | 16 | 25 | Aucune |

Stéphane confirme la recette locale du parcours apprenant du quiz **24**. Pour les quiz **25** et **26**, sa confirmation porte sur le placement, les compteurs et la configuration.

## Qu’est-ce qui reste incertain ?

- **Parcours et second passage des quiz 25 et 26.** Avec un compte apprenant local, terminer chaque quiz, vérifier la correction et le score, puis lancer un second passage. Comparer les questions et leurs thèmes entre les passages et vérifier les longueurs de 5 et 16 questions respectivement.
- **Reprise des quiz 25 et 26.** Pour chacun, répondre à une question, quitter la tentative, puis rouvrir le quiz avec le même compte. Vérifier que la réponse et la progression sont conservées et que la tentative peut être terminée.
