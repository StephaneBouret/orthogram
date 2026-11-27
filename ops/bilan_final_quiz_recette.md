# Bilan de fin de formation — recette des deux quiz

Sur la branche locale `preprod`, la recette V2 a été réalisée sur une base jetable. Le plan V3 relu par Stéphane a ensuite été appliqué sur la base locale de travail `orthogram`.

## V2 — Recette sur base jetable

Les quatre contrôles ont réussi le 27 novembre 2026, à la révision `733f0c1`. Stéphane a validé la V2 telle quelle.

### Fichiers installés pour la recette V2

Seules les trois entrées de `resources/quiz/bilan_final/` ont été extraites de l’archive. Le SHA-256 a été calculé sur les octets de chaque entrée du ZIP, puis sur le fichier installé. Les trois paires concordent. Pour le manifeste, l’empreinte correspond également à celle fournie par Stéphane.

| Fichier | SHA-256 du ZIP et du fichier installé |
| --- | --- |
| `.gitattributes` | `9e8f041ed812cb5288282cc6b187de804e96b688d56647fdae181c49fec71863` |
| `bilan_final_quiz_manifest.json` | `0b8faf62a3ecdccb0b6f82d3e34c837b7c290855d4644e2d78f77450449262d7` |
| `guide_bilan_final.md` | `dd2e8722aefeb09b63a71701de16e3950a69ab367212653b2ce62a34ae8efe0a` |

Les deux Word sont restés dans le ZIP, hors dépôt.

### Destination et témoin V2

Le serveur MySQL local a renvoyé `NULL` pour `DATABASE()` sur la connexion de création. `DictationTestDatabase` a créé une base dédiée avec le profil `editorial`. Avant d’y installer le schéma, la connexion Doctrine du noyau Symfony `test` a renvoyé `orthogram_editorial_bb14f5b55c251e8f_test` pour `DATABASE()`, `STEF-LEGION` pour `@@hostname`, `3306` pour `@@port` et `8.4.7` pour `@@version`. L’hôte configuré était `127.0.0.1`. Chaque appel à `app:quiz:manifest` a reçu les cinq options `--expect-*` et renvoyé cette destination.

Avant la première prévisualisation du bilan, la fixture `tests/Fixtures/Quiz/essai.json` a créé le quiz témoin `grand_essai_final` (ID 1), sa question et ses deux propositions. La capture de référence les inclut. `QuizReleaseSnapshot` a relu les lignes, les colonnes et la définition des 29 tables dans une transaction en lecture seule.

### Résultats des quatre contrôles V2

1. **Prévisualisation.** Le plan annonçait 2 quiz, 222 questions et 444 propositions à ajouter, avec 0 copie préexistante. Les listes des différences de contenu, des différences de configuration, des éléments de schéma manquants et des erreurs étaient vides. Les captures avant et après sont identiques. Leur SHA-256, calculé sur la capture PHP sérialisée, est `8f092369ff2bda116153d279b46feea11889540cacd15370e54129dcf2c15fa6`.
2. **Premier import.** Après relecture des cibles, des volumes et de l’empreinte, la commande a reçu `--apply` et `--expect-plan=2abf042450bc45bd004fe37464f968d09745b7067d8996a7a90d4045ef60f4c7`. Elle a confirmé cette empreinte. La différence SQL est de +2 quiz, +222 questions, +444 propositions, +2 identités de manifeste et +222 traces de copie. Chaque ligne préexistante a été retrouvée à l’identique, y compris le témoin complet. L’empreinte de la capture après import est `f90a3224faf562ab42f93445c6134796df855feeb42c7adbaff859248d48ee6f`.
3. **Deuxième import.** La nouvelle prévisualisation a reconnu 222 copies et prévu 0 ajout, sans écart ni erreur. L’application de son empreinte relue, `a24eb0d98b60cf7a61aa148e16888137b22d983f0b01bb410015ca5c3db4f245`, a renvoyé `noop: true`, 0 question ajoutée, 0 proposition ajoutée et 0 quiz modifié. Les captures avant la prévisualisation, après celle-ci et après l’application sont identiques sur les 29 tables.
4. **Renouvellement.** La commande `enable` en prévisualisation seule a produit le plan `8e496f7906f388c98e8dc5d1e63cecd9bd41955e72f07c5b135a74ef1f457f5d`. Ses deux changements proposés portent sur `renewal_enabled`, de 0 à 1, pour `bilan_final_partie1` (ID 2) et `bilan_final_partie2` (ID 3). Le témoin est absent des cibles. Après la commande, une nouvelle lecture de `quiz` a renvoyé 0 pour les trois quiz. La capture complète est identique à celle du premier import.

### Relecture SQL du premier import

| Quiz | Thèmes | Initiales (positions) | Réserves (positions) | Propositions | Clés « 0 erreur » | Clés « 1 erreur » |
| --- | ---: | --- | --- | ---: | ---: | ---: |
| Partie 1 · ID 2 | 58 | 58 (0 à 57) | 58 (58 à 115) | 232 | 69 | 47 |
| Partie 2 · ID 3 | 53 | 53 (0 à 52) | 53 (53 à 105) | 212 | 54 | 52 |
| **Total** | **111** | **111** | **111** | **444** | **123** | **99** |

Le regroupement de `quiz_question` par quiz et par thème donne exactement une initiale et une réserve par groupe, avec deux identifiants et deux énoncés distincts. Les numéros sources des deux rôles correspondent à chaque paire de la matrice du manifeste.

La jointure de `quiz_reserve_import`, `quiz_question` et `quiz_answer`, ordonnée par position de question puis de proposition, a été comparée au manifeste pour les 222 copies. Elle confirme les clés sources, les empreintes, les positions, les rôles, les titres, les thèmes, les énoncés, les explications et l’indicateur de faute. Pour chaque question, les deux libellés, leurs positions 0 et 1 et la réponse marquée correcte correspondent à la clé attendue.

La jointure de `quiz_manifest_identity` et `quiz`, avec comptage des références dans `courses.quiz_id`, confirme pour chacun des deux quiz `renewal_enabled = 0`, `error_question_quota IS NULL` et 0 cours rattaché. Les identifiants du tableau sont propres à cette base jetable.

Après les contrôles, la base dédiée a été supprimée. La recherche de son nom exact dans `information_schema.schemata` a renvoyé 0. La recette V2 s’est déroulée exclusivement sur cette base.

## V3 — Import sur la base locale de travail

Le 27 novembre 2026, avant l’écriture, le noyau Symfony a confirmé l’environnement `dev`. La configuration Doctrine indiquait `127.0.0.1:3306`, et la requête `SELECT DATABASE(), @@hostname, @@port` a renvoyé `orthogram`, `STEF-LEGION`, `3306`. Le SHA-256 du manifeste installé était `fb9e724089e01b10c0ceb00f1b4f3e57f908ac93a3937325be39bb935e5839cd`.

L’importeur a reçu les cinq options de destination, `--allow-work-database --apply` et `--expect-plan=f17aa7f0629cae4c4f26dba26557c56be0695a20a96c4334b43f21ebd8542dbf`. La commande a confirmé cette empreinte, renvoyé le code 0 et ajouté 222 questions et 444 propositions (`applied: true`, `noop: false`).

La prévisualisation suivante, lancée sans `--apply` ni `--expect-plan`, a renvoyé le code 0 et reconnu **222 copies**. Elle prévoit **0 quiz, 0 question et 0 proposition** à ajouter. Les listes de schéma manquant, de différences, d’écarts de configuration et d’erreurs sont vides. L’empreinte de cette prévisualisation après import est `cd737b505f1d0916d748ff8285d5a0b14192bf42a907a3ed155fbd9484d28038`.

### Vérification SQL immédiatement après l’import V3

| Quiz | ID local | Thèmes | Initiales | Réserves | Propositions | Renouvellement | Quota | Cours rattachés |
| --- | ---: | ---: | ---: | ---: | ---: | --- | --- | ---: |
| `bilan_final_partie1` | **27** | 58 | 58 | 58 | 232 | 0 (fixe) | `NULL` | 0 |
| `bilan_final_partie2` | **28** | 53 | 53 | 53 | 212 | 0 (fixe) | `NULL` | 0 |

Les identifiants ont été relus via `quiz_manifest_identity` et `quiz`, les propositions comptées dans `quiz_answer` et les rattachements dans `courses.quiz_id`. Pour chacun des 111 thèmes, le regroupement des questions et de leurs traces d’import confirme une initiale et une réserve, avec deux identifiants et deux énoncés distincts. Les clés sources des deux rôles correspondent à la matrice V3.

En partie 1, la source `bilan_final_docx_091` désigne la question locale **936** du quiz **27**. L’énoncé en base est « À voir les parapluies ouverts devant la gare, je me doute qu’il pleut. » La valeur de `contains_error` est 0. La proposition « 0 erreur » est marquée correcte et « 1 erreur » est marquée fausse.

Pour les questions préexistantes, la comparaison porte sur le SHA-256 de la ligne complète de `quiz_question` et de toutes ses lignes `quiz_answer` triées par ID, sérialisées ensemble en PHP. Les empreintes relevées avant et après l’écriture sont identiques à celles de la prévisualisation V3 initiale.

| Question | Quiz local | Propositions | SHA-256 avant et après import |
| --- | ---: | ---: | --- |
| 419 | 12 | 2 | `4ed66e3e6f9139429137444a9b4b187553748ad1ec4b62ffa5a61ed6c1587bf4` |
| 462 | 14 | 2 | `ea7bb2b4b8e3eca75dcc9cc872d0fb802b814a366ff86a9d2e76e4c280c9ef6e` |

### Contrôle navigateur de Stéphane après l’import V3

Côté apprenant, les captures de Stéphane montrent **58 questions** pour la partie 1 (quiz local **27**) et **53 questions** pour la partie 2 (quiz local **28**). Stéphane a ensuite activé le renouvellement pour les deux quiz dans l’administration locale.

Le mode fixe du tableau SQL correspond au moment de l’import. L’activation ultérieure est un réglage de la base locale `orthogram`. Le SHA-256 du manifeste V3 a été revérifié après le contrôle navigateur et correspond à celui du fichier importé, `fb9e724089e01b10c0ceb00f1b4f3e57f908ac93a3937325be39bb935e5839cd`.

## Qu’est-ce qui reste incertain ?

- **Placement dans le sommaire.** Depuis le sommaire apprenant, vérifier que la section « Bilan de fin de formation » suit « Participe passé » et que ses liens ouvrent la partie 1 puis la partie 2.
- **Reprise, corrections et score.** Pour chaque quiz, répondre à une question, quitter puis rouvrir la tentative avec le même compte. Vérifier que la réponse est conservée, terminer avec des réponses connues et comparer les corrections et le score affichés au résultat attendu.
- **Tirage avec renouvellement.** Effectuer deux passages par quiz et relever, pour chaque thème, l’identifiant de la question présentée. Comparer les deux listes à la matrice des initiales et des réserves.
