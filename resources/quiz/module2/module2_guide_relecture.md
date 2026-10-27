# Orthogram — Module 2 : guide de relecture des quiz

Version éditoriale 1 — 26 octobre 2026.

Découpage et affectations validés par Stéphane : deux tests de 12 questions, puis un général de 24. Les cinq retouches de phrases et les six mises en attente de la proposition sont conservées. Cette validation autorise la préparation du dossier, pas un import ou une activation sur une base réelle.

Le manifeste JSON est le document structuré de référence. Les titres des nouveaux quiz sont proposés puis validés ; ils ne prouvent aucune identité existante en base. Les identifiants et slugs de cours restent non renseignés. Les clés `module2_docx_NNN` sont éditoriales, distinctes des identifiants SQL.

## Lecture du dossier

- `module2_quiz_manifest.json` : sources originales, textes proposés, corrigés, catégories, quiz, copies et positions.
- `module2_prompt_codex.md` : adaptation de l’import, inspection du dépôt et recette à réaliser sur base jetable.
- `valider_module2.py` : contrôles autonomes sans accès à une base.
- `module2_validation.json` : résultats des contrôles et simulation documentaire du renouvellement.
- L’annexe finale répertorie **les 116 questions dans leur ordre d’origine**, y compris celles en attente.

## Bilan validé

| Élément | Nombre |
| --- | ---: |
| Questions sources du Word | 116 |
| Thèmes | 20 |
| Sources importables | 110 |
| Sources en attente | 6 |
| Sources copiées dans un intermédiaire et dans le général | 67 |
| Sources utilisées uniquement dans le général | 43 |
| Copies de questions à importer | 177 |
| Copies initiales | 48 |
| Copies de réserve | 129 |
| Propositions à importer, deux par copie | 354 |
| Nouvelles questions inventées | 0 |

Aucun doublon textuel exact n’a été détecté dans le Word. Les couples Q1/Q41, Q20/Q98, Q21/Q99, Q30/Q70 et Q47/Q88 sont des variantes proches : ils réemploient un mécanisme, pas le même texte.

Les jeux initiaux des trois quiz emploient 48 sources différentes. Des reprises pourront faire rencontrer une même source dans un intermédiaire et dans le général : les copies sont volontaires et le moteur ne garantit pas un évitement entre quiz.

## Emplacement et progression

| Quiz | Emplacement pédagogique | Taille d’une tentative |
| --- | --- | ---: |
| Test : écrire les nombres et les expressions courantes | Après l’exercice « plutôt / plus tôt », avant « on / on n’ » | 12 |
| Test : distinguer les formes et les terminaisons | Après l’exercice sur les adverbes en « -amment / -emment », avant « d’en / dans » | 12 |
| Test général : réviser le module 2 | Après l’exercice sur tout adverbe, avant la dictée finale | 24 |

Le premier bloc porte sur les conventions d’écriture, le deuxième sur les formes grammaticales et terminaisons. Le général réactive ces douze thèmes et évalue les huit derniers. Il accorde deux places à près/prêt, deux à quasi et trois à tout. La dictée reste l’étape finale conformément à l’organisation déjà retenue.

Les positions ci-dessous sont celles des questions, pas celles des cours. Les cours seront rattachés manuellement dans EasyAdmin après un import ultérieurement autorisé. Aucun cours existant n’est créé, déplacé ou modifié par ce dossier.

## Jeux initiaux, réserves et quotas par thème

Chaque libellé de thème est repris à l’identique dans ses questions initiales et de réserve. Le nombre présenté reste le quota initial du thème. La dernière colonne donne le minimum mathématique de questions nécessairement reprises depuis la tentative précédente, pour la banque proposée et sans quota global d’erreurs.

### Test : écrire les nombres et les expressions courantes

Clé éditoriale : `module2_test1`. Après l’exercice sur « plutôt » / « plus tôt ». Avant le cours « on » / « on n’ ».

**12 initiales + 19 réserves = 31 copies. Toujours 12 questions par tentative.**

| Thème / cours | Initiales (nombre) | Réserves (nombre) | Présentées | Réutilisation minimale |
| --- | --- | --- | ---: | ---: |
| Les jours de la semaine | Q1, Q81 (2) | Q41, Q42, Q82 (3) | 2 | 0 |
| « lorsque » / « lorsqu’ » | Q3, Q84 (2) | Q4, Q43, Q83 (3) | 2 | 0 |
| Les nombres | Q6, Q85 (2) | Q5, Q46, Q86 (3) | 2 | 0 |
| « demi » / « à demi » / « et demi » | Q7, Q8, Q47 (3) | Q48, Q87, Q88 (3) | 3 | 0 |
| « certes » | Q10 (1) | Q9, Q49, Q50 (3) | 1 | 0 |
| « plutôt » / « plus tôt » | Q11, Q90 (2) | Q12, Q51, Q52, Q89 (4) | 2 | 0 |

Ordre initial exact : Q1, Q81, Q3, Q84, Q6, Q85, Q7, Q8, Q47, Q10, Q11, Q90.

Composition documentaire du premier passage : 7 phrases erronées et 5 correctes. Ce décompte n’est pas une contrainte aux reprises.

### Test : distinguer les formes et les terminaisons

Clé éditoriale : `module2_test2`. Après l’exercice sur les adverbes en « -amment » / « -emment ». Avant le cours « d’en » / « dans ».

**12 initiales + 24 réserves = 36 copies. Toujours 12 questions par tentative.**

| Thème / cours | Initiales (nombre) | Réserves (nombre) | Présentées | Réutilisation minimale |
| --- | --- | --- | ---: | ---: |
| « on » / « on n’ » | Q14, Q53 (2) | Q13, Q54, Q91, Q92 (4) | 2 | 0 |
| « exigence » / « exigeant » | Q15, Q16 (2) | Q55, Q56, Q93, Q94 (4) | 2 | 0 |
| « employé » / « employer » | Q17, Q18 (2) | Q57, Q58, Q95, Q96 (4) | 2 | 0 |
| « faites » / « faîte » | Q20, Q97 (2) | Q19, Q59, Q60, Q98 (4) | 2 | 0 |
| « presque » / « presqu’ » | Q21, Q62 (2) | Q22, Q61, Q99, Q100 (4) | 2 | 0 |
| Adverbes en « -amment » / « -emment » | Q24, Q64 (2) | Q23, Q63, Q101, Q102 (4) | 2 | 0 |

Ordre initial exact : Q14, Q53, Q15, Q16, Q17, Q18, Q20, Q97, Q21, Q62, Q24, Q64.

Composition documentaire du premier passage : 7 phrases erronées et 5 correctes. Ce décompte n’est pas une contrainte aux reprises.

### Test général : réviser le module 2

Clé éditoriale : `module2_general`. Après l’exercice sur tout adverbe, avant la dictée finale.

**24 initiales + 86 réserves = 110 copies. Toujours 24 questions par tentative.**

| Thème / cours | Initiales (nombre) | Réserves (nombre) | Présentées | Réutilisation minimale |
| --- | --- | --- | ---: | ---: |
| Les jours de la semaine | Q41 (1) | Q1, Q42, Q81, Q82 (4) | 1 | 0 |
| « lorsque » / « lorsqu’ » | Q4 (1) | Q3, Q43, Q83, Q84 (4) | 1 | 0 |
| Les nombres | Q5 (1) | Q6, Q46, Q85, Q86 (4) | 1 | 0 |
| « demi » / « à demi » / « et demi » | Q48 (1) | Q7, Q8, Q47, Q87, Q88 (5) | 1 | 0 |
| « certes » | Q49 (1) | Q9, Q10, Q50 (3) | 1 | 0 |
| « plutôt » / « plus tôt » | Q12 (1) | Q11, Q51, Q52, Q89, Q90 (5) | 1 | 0 |
| « on » / « on n’ » | Q54 (1) | Q13, Q14, Q53, Q91, Q92 (5) | 1 | 0 |
| « exigence » / « exigeant » | Q93 (1) | Q15, Q16, Q55, Q56, Q94 (5) | 1 | 0 |
| « employé » / « employer » | Q58 (1) | Q17, Q18, Q57, Q95, Q96 (5) | 1 | 0 |
| « faites » / « faîte » | Q59 (1) | Q19, Q20, Q60, Q97, Q98 (5) | 1 | 0 |
| « presque » / « presqu’ » | Q100 (1) | Q21, Q22, Q61, Q62, Q99 (5) | 1 | 0 |
| Adverbes en « -amment » / « -emment » | Q101 (1) | Q23, Q24, Q63, Q64, Q102 (5) | 1 | 0 |
| « d’en » / « dans » | Q65 (1) | Q25, Q26, Q66, Q103, Q104 (5) | 1 | 0 |
| « pause » / « pose » | Q105 (1) | Q27, Q28, Q67, Q68, Q106 (5) | 1 | 0 |
| « voilà » | Q69 (1) | Q29, Q30, Q70 (3) | 1 | 0 |
| « près de » / « prêt à » | Q31, Q108 (2) | Q32, Q71, Q72, Q107 (4) | 2 | 0 |
| « tache » / « tâche » | Q74 (1) | Q33, Q34, Q73, Q109, Q110 (5) | 1 | 0 |
| « à l’attention » / « à l’intention » | Q112 (1) | Q35, Q36, Q76 (3) | 1 | 0 |
| « quasi » | Q38, Q78 (2) | Q37, Q77, Q113, Q114 (4) | 2 | 0 |
| « tout » (2) | Q39, Q40, Q115 (3) | Q79, Q116 (2) | 3 | 1 |

Ordre initial exact : Q41, Q4, Q5, Q48, Q49, Q12, Q54, Q93, Q58, Q59, Q100, Q101, Q65, Q105, Q69, Q31, Q108, Q74, Q112, Q38, Q78, Q39, Q40, Q115.

Composition documentaire du premier passage : 13 phrases erronées et 11 correctes. Ce décompte n’est pas une contrainte aux reprises.

## Fonctionnement et limites du renouvellement

Les nouveaux quiz seront créés en mode **fixe** (`renewal_enabled=false`) et sans quota global d’erreurs (`error_question_quota=null`). Les réserves seront présentes mais exclues des tentatives fixes. Leur utilisation demande une activation distincte, non autorisée ici.

Après activation, un premier passage sans tentative précédente utilise les initiales dans l’ordre prévu. Une reprise d’une tentative en cours conserve son snapshot. Une nouvelle tentative sélectionne parmi initiales et réserves, en conservant les quotas et emplacements de thèmes du jeu initial. La sélection est déterministe ; elle minimise la réutilisation des identifiants du snapshot précédent, puis départage par position et identifiant. Les corrigés historiques restent figés.

Tous les thèmes ont des réserves. Pour tout, cinq sources sont disponibles pour trois places dans le général : au moins une question doit être réutilisée entre deux tentatives. Pour les autres thèmes, la taille de la banque permet mathématiquement un remplacement complet ; cette capacité ne constitue pas une promesse générale de renouvellement.

### Ce que révèle l’ordre déterministe actuel

Une simulation en mémoire reproduit le cas `error_question_quota=null` du sélecteur inspecté. Les positions du manifeste sont uniques ; le départage par identifiant n’influence donc pas cette simulation. La banque, les positions et les catégories sont supposées inchangées ; le renouvellement n’a été activé dans aucune application.

| Quiz | Banque | Sources observées avant cycle | Sources non sélectionnées dans cette configuration |
| --- | ---: | ---: | ---: |
| module2_test1 | 31 | 24 | 7 |
| module2_test2 | 36 | 24 | 12 |
| module2_general | 110 | 47 | 63 |

À paramètres constants, le moteur peut ainsi alterner entre deux ensembles. Une réserve plus grande ne signifie pas qu’elle sera parcourue intégralement. Les numéros concernés et les deux premières sélections simulées figurent dans `module2_validation.json`. Ce résultat est une simulation documentaire du code inspecté, pas une recette Symfony/MySQL. Les réserves restantes sont conservées pour permettre de futures décisions éditoriales ; aucun changement du moteur n’est demandé dans cette livraison.

### Couverture des règles

- **Pause/pose** : les six sources sont correctes. La banque n’offre aucune phrase fautive pour ce thème.
- **Employé/employer** : cinq sources correctes ; Q96 évalue une incohérence de genre. Pas de véritable confusion fautive entre le nom et l’infinitif dans le stock.
- **Faites/faîte** : seule Q97 teste le nom faîte. Une reprise peut ne proposer que les formes verbales.
- **Nombres** : les usages de cent/vingt et de mille sont présents. Les milliers/millions/milliards et le cas cardinal quatre-vingts sans nombre suivant sont enseignés dans le cours mais ne sont pas directement couverts par une source retenue spécifique. Q45 sur le mille marin reste en attente dans cette version.
- **Tout** : l’accord devant un h aspiré est présent, ainsi que l’invariabilité devant un h muet, une voyelle ou au masculin. Le stock ne contient pas de question spécifique sur l’accord devant une consonne ordinaire au féminin.
- **Sous-règles** : le quota porte sur le thème complet, pas ses sous-cas. Trois questions sur demi ne garantissent pas trois constructions différentes à chaque reprise ; deux questions sur quasi ne garantissent pas une question avec nom et une avec adjectif.

Ces limites pourront motiver des compléments proposés séparément. Aucune nouvelle question n’a été ajoutée.

## Registre des décisions éditoriales

### Sources en attente : aucune copie à importer

| Source | Motif et état |
| --- | --- |
| Q2 | Variante « dimanches matins » admise : ne pas noter cette graphie comme une faute sans harmonisation pédagogique. |
| Q44 | Élision « lorsqu’à » admise dans l’usage contemporain ; corrigé trop restrictif. |
| Q45 | Mise en attente validée conservée. Vérification ultérieure : le cours les-nombres.docx enseigne bien le mille marin. Rattachement confirmé ; source réintégrable dans une prochaine révision explicite, aucune affectation dans cette version. |
| Q75 | Le contexte professionnel ne suffit pas à rendre « à l’intention » incorrect ; préciser la destination et le sens. |
| Q80 | Distinguer « entièrement habillées » et « toutes les personnes habillées » ; la lecture attendue doit être explicitée. |
| Q111 | « À l’intention » peut exprimer la destination ou le bénéfice ; le contexte du courrier doit être précisé. |

Les catégories éventuelles figurant dans `review_suggestion` sont documentaires : elles ne rendent pas une source en attente importable. Les cas dont la lecture reste ambiguë portent explicitement une catégorie `null` et un motif. Les 110 candidates importables ont toutes une catégorie booléenne explicite.

**Vérifications supplémentaires des cours.** Les cours `les-nombres.docx`, `faites-faite.docx`, `a-l-attention-a-l-intention.docx` et `tout-2.docx` ont été lus intégralement pendant la préparation finale. Refaire et satisfaire sont explicitement enseignés ; Q19 et Q59 sont donc bien rattachées. Le mille marin l’est aussi : le doute de rattachement de Q45 est levé, mais sa mise en attente validée est conservée pour ne pas modifier silencieusement les effectifs. Les autres correspondances reposent sur les intitulés et l’ordre du sommaire ; leur contenu intégral n’a pas été relu ici.

### Retouches de phrases validées, distinctes des erreurs à trouver

Les phrases originales ci-dessous restent intactes dans le manifeste. Les modifications concernent seulement le texte proposé aux apprenants. Les corrections de l’erreur pédagogique sont documentées séparément dans `correction_edits` et `corrected_text`.

**Q4 — Suppression d’une seconde erreur étrangère à l’élision testée.**

Original :

> Toute la famille se baladait au bord la mer lorsque une averse soudaine la surprit.

Proposé :

> Toute la famille se baladait au bord de la mer lorsque une averse soudaine la surprit.

Catégorie proposée : `contains_error=true`.

**Q37 — Graphie de référence ; éviter une difficulté étrangère à quasi.**

Original :

> Mon compte-rendu est quasi terminé.

Proposé :

> Mon compte rendu est quasi terminé.

Catégorie proposée : `contains_error=false`.

**Q50 — Retrait de tout adverbe, étudié après le premier test.**

Original :

> Les feuilles, certe, n’étaient pas agrafées et elles sont maintenant tout éparpillées.

Proposé :

> Les feuilles, certe, n’étaient pas agrafées et elles sont maintenant éparpillées.

Catégorie proposée : `contains_error=true`.

**Q56 — Graphie de référence ; éviter une difficulté étrangère à exigeant.**

Original :

> Exigeant un compte-rendu de la réunion, notre responsable nous a fait partir plus tard du bureau.

Proposé :

> Exigeant un compte rendu de la réunion, notre responsable nous a fait partir plus tard du bureau.

Catégorie proposée : `contains_error=false`.

**Q71 — Ponctuation clarifiant la construction détachée ; prêt reste correct.**

Original :

> Pratique ton déjeuner : il est prêt à cuire.

Proposé :

> Pratique, ton déjeuner : il est prêt à cuire.

Catégorie proposée : `contains_error=false`.

### Explications et questions portant sur plusieurs règles

Les 110 explications importables sont reformulées pour être autonomes et directement utilisables dans le lecteur. Le préambule « C’est exact ! » est supprimé, car l’explication peut être affichée après une mauvaise réponse. Pour les phrases erronées, la phrase corrigée complète est incluse dans l’explication : le lecteur pourra donc la montrer sans dépendre d’une colonne `corrected_text` inexistante dans le schéma inspecté.

Corrections de fond : Q9 explicite la concession ; Q42/Q82 admettent les graphies d’après-midi ; Q53 distingue interrogation et négation ; Q56 identifie le participe présent ; Q96 mentionne deux réparations possibles de l’unique incohérence de genre ; Q104 distingue délai et durée ; Q36/Q76/Q112 distinguent le sens des locutions, pas le contexte privé/professionnel ; Q115 définit le comportement du h muet. La source et le texte final de chaque explication sont conservés côte à côte dans les données.

Q4, Q37 et Q56 contenaient une difficulté étrangère au thème : elle est neutralisée dans le texte proposé. Q50 combinait certes et tout adverbe : tout est retiré. Q65 mobilise aussi huit cents ; Q74 contient prêts ; Q96 associe employé et toutes ; Q85 combine cent, vingt et les traits d’union des numéraux. Ces croisements restent documentés sans créer plusieurs quotas pour une même question.

Q96 possède une seule réponse correcte dans le QCM (« 1 erreur »), même si l’incohérence peut être réparée de deux manières selon le référent. Cela ne justifie pas un mode à réponses multiples.

## Schéma réel et préparation de l’import

Inspection en lecture seule du dépôt `StephaneBouret/orthogram`, commit `b44724cd25be830a40d79036e4d9247ba5b23bf1`. Aucune base locale ou distante n’a été inspectée ni modifiée.

| Élément réel | Conséquence pour ce dossier |
| --- | --- |
| `Quiz.renewalEnabled`, `Quiz.errorQuestionQuota` | Nouveaux quiz fixes ; quota NULL. |
| `QuizQuestion.reserve`, `QuizQuestion.containsError` | Rôle porté par chaque copie ; catégorie portée par son contenu. |
| `QuizQuestion.title`, `text`, `explanation`, `multiple`, `position`, `theme` | Charge utile explicite du manifeste ; `multiple=false`. |
| `QuizAnswer.content`, `correct`, `position` | Deux propositions, une seule correcte. |
| `QuizReserveImport`, table `quiz_reserve_import` | Provenance existante par `(quiz_id, source_key)`, lien unique à question, empreintes. Réutilisation pour des initiales à justifier et tester. |
| `Courses.quiz` | Rattachement distinct, à réaliser dans EasyAdmin après import autorisé. |
| Absence de propriété `corrected_text` dans `QuizQuestion` | Champ uniquement documentaire ; phrase corrigée aussi incluse dans `explanation`. |

Le chargeur et l’importeur actuels imposent les clés, quantités et cibles du module 1. `app:quiz:module1-reserves` et `app:quiz:module1-renewal` ne peuvent pas consommer ce manifeste. Il faut adapter l’outillage, notamment pour créer trois quiz et retrouver durablement leurs clés éditoriales lors d’un second import. Le prompt Codex détaille cette adaptation sans supposer une table ou une colonne nouvelle déjà existante.

Les migrations inspectées `Version20261025120000` et `Version20261025150000` définissent les colonnes de renouvellement et la traçabilité actuelle. Leur présence dans le code ne prouve pas leur application sur une base cible. Aucune commande SQL d’import n’est fournie à ce stade.

### Références du dépôt inspectées

- [src/Services/ModuleOneReserveImporter.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Services/ModuleOneReserveImporter.php) — blob `5608d773834951a195db55af63dfe7639f83d000`.
- [src/Services/ModuleOneReserveManifest.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Services/ModuleOneReserveManifest.php) — blob `3454c8e8df3dad96c6ab718ce00c4e4f61c3b0d6`.
- [src/Entity/QuizReserveImport.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Entity/QuizReserveImport.php) — blob `437c3ce4dbfa4e3a859e3260a6d0147d0bc4b270`.
- [src/Services/QuizConfigurationValidator.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Services/QuizConfigurationValidator.php) — blob `cc3cb7c99ed84c5ea50c11d2e2b0ac5c35bb502a`.
- [src/Services/QuizEditorialService.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Services/QuizEditorialService.php) — blob `9799a50609303b72bfcdc23e9f84dd2d892cfae6`.
- [src/Command/QuizReserveCommand.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Command/QuizReserveCommand.php) — blob `5cb3feac6621be5f4703d0981f68d5e9b72235f4`.
- [src/Command/QuizRenewalCommand.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Command/QuizRenewalCommand.php) — blob `b51e9f6e4692ca1196dcf7853c7989d1d6de8c60`.
- [migrations/Version20261025150000.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/migrations/Version20261025150000.php) — blob `8b3d5f8fa663f9b6d097ed10f2f5971af4e7922b`.
- [src/Services/QuizQuestionSelector.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Services/QuizQuestionSelector.php) — blob `e2efc26751264d13e59c9c7fe3a693f911765c86`.
- [migrations/Version20261025120000.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/migrations/Version20261025120000.php) — blob `639aeb0ebdef16bd48b632ac8c78843baf1904bd`.
- [src/Services/QuizCommandDestination.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Services/QuizCommandDestination.php) — blob `599c3a5db912f766521329d813789a2cd8e230b0`.
- [src/Entity/Quiz.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Entity/Quiz.php) — blob `2eacddbd863ae1ae6133a237c9eb7822ab17552a`.
- [src/Entity/QuizQuestion.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Entity/QuizQuestion.php) — blob `9e27fa070fd7b146879db4b11282fc37d106258a`.
- [src/Entity/QuizAnswer.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Entity/QuizAnswer.php) — blob `acb380216f29f84a9e55649a343a1993be85493a`.
- [src/Entity/Courses.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Entity/Courses.php) — blob `bde6d6efb43da648710c7711e717a6bf181968ed`.
- [src/Services/QuizAttemptService.php](https://github.com/StephaneBouret/orthogram/blob/b44724cd25be830a40d79036e4d9247ba5b23bf1/src/Services/QuizAttemptService.php) — blob `27ae592c54e0c528658063c8bd8905f3e2ca2f25`.

## Contrôles réalisés et recette restante

Contrôles réellement exécutés : 116 numéros distincts ; correspondance des textes, réponses et explications avec le Word ; 110 candidates et six mises en attente ; cinq retouches de texte tracées ; 177 copies distinctes ; 354 propositions ; une bonne réponse par question ; catégories explicites ; corrections documentées correspondant aux phrases corrigées ; absence de doublons textuels dans chaque quiz ; quotas par thème ; ordre et positions ; empreintes des contenus ; simulation en mémoire du sélecteur à quota NULL.

La vérification qu’une correction remplace un seul fragment n’est pas une preuve linguistique automatisée de l’absence de toute autre erreur. La relecture éditoriale et le registre des ambiguïtés restent nécessaires.

Contrôles à réaliser par Codex sur une base jetable : import complet, second import sans effet, refus des divergences, reprise après échec sans données partielles, concurrence de deux imports, protection des contenus et historiques existants, rendu apprenant, activation séparée, sélection par le vrai service PHP et conservation des snapshots. Aucun de ces tests applicatifs n’est présenté comme exécuté dans cette livraison.

Pour rejouer les contrôles de fichiers, sans connexion à une base :

```text
python valider_module2.py module2_quiz_manifest.json
python valider_module2.py module2_quiz_manifest.json --source-docx CHEMIN_VERS_Module_2_quiz.docx
```

L’argument du Word est facultatif. Le validateur utilise seulement la bibliothèque standard Python. L’empreinte SHA-256 du fichier JSON livré figure dans `module2_validation.json`.

## Références linguistiques consultées

- [OQLF — jours et divisions de la journée](https://vitrinelinguistique.oqlf.gouv.qc.ca/index.php?id=22685) : Q2, Q42, Q81, Q82.
- [OQLF — élision de lorsque](https://vitrinelinguistique.oqlf.gouv.qc.ca/23623/lorthographe/elision-et-apostrophe/elision-de-lorsque-puisque-et-quoique) : Q44 et principes d’élision.
- [Académie française — compte rendu](https://www.dictionnaire-academie.fr/article/A9C3322) : retouches Q37/Q56.
- [OQLF — à l’attention / à l’intention](https://vitrinelinguistique.oqlf.gouv.qc.ca/23304/le-vocabulaire/paronymes/sens-et-emploi-de-a-lattention-de-et-de-a-lintention-de) : Q35/Q36/Q75/Q76/Q111/Q112.
- [OQLF — tout pronom](https://vitrinelinguistique.oqlf.gouv.qc.ca/22911/la-grammaire/les-pronoms/pronoms-indefinis/tout-employe-comme-pronom-indefini) et [tout adverbe](https://vitrinelinguistique.oqlf.gouv.qc.ca/22907/la-grammaire/ladverbe/tout-employe-comme-adverbe) : Q80 et thème tout.
- [Académie française — cuillérée / cuillerée](https://www.dictionnaire-academie.fr/article/A9C5201) : Q48 ; conserver la graphie admise.
- [Académie française — quasi](https://www.dictionnaire-academie.fr/article/A9Q0119) : distinction nom/adjectif.

## Annexe finale — les 116 sources, leur texte et leur affectation

Les blocs « Source » et « Corrigé source » reproduisent le texte extrait du Word ; leurs éventuelles erreurs ne sont pas endossées. Les paragraphes proposés sont distincts. Pour chaque source importable, la catégorie porte sur la phrase proposée, après les retouches éditoriales validées.

### Q1 — Les jours de la semaine

Clé : `module2_docx_001`. Cours : **Les jours de la semaine** (`jours-de-la-semaine.docx`).

**Texte source**

> Impossible de se voir pour déjeuner ce mois-ci : tous mes mercredi midi sont pris.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Impossible de se voir pour déjeuner ce mois-ci : tous mes mercredis midi sont pris. » Il y a plusieurs mercredis dans le mois. En revanche, « midi » reste invariable.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Impossible de se voir pour déjeuner ce mois-ci : tous mes mercredis midi sont pris.

**Explication proposée**

> Correction : « Impossible de se voir pour déjeuner ce mois-ci : tous mes mercredis midi sont pris. »
> 
> Plusieurs mercredis sont concernés : le nom du jour prend un s. Dans cet emploi, midi reste invariable.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 0 (index à partir de 0), copie `module2_test1::module2_docx_001`.
- **Test général : réviser le module 2** : réserve, position 24 (index à partir de 0), copie `module2_general::module2_docx_001`.

**Variante proche** : Q41 (texte différent).


### Q2 — Les jours de la semaine

Clé : `module2_docx_002`. Cours : **Les jours de la semaine** (`jours-de-la-semaine.docx`).

**Texte source**

> Nos dimanches matins sont surtout consacrés à dormir.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Nos dimanches matin sont surtout consacrés à dormir. » « Matin » ne prend pas de « s » : il n’y a qu’une matinée dans la journée.

**En attente — aucune affectation**

Variante « dimanches matins » admise : ne pas noter cette graphie comme une faute sans harmonisation pédagogique.

**Catégorie documentaire suggérée** : `false` ; non importable.

**Texte de référence documentaire**

> Nos dimanches matins sont surtout consacrés à dormir.


### Q3 — « lorsque » / « lorsqu’ »

Clé : `module2_docx_003`. Cours : **« lorsque » / « lorsqu’ »** (`lorsque.docx`).

**Texte source**

> Il prenait tranquillement un verre lorsqu’une femme souriante l’a abordé.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Lorsque » s’élide  devant « un, une » (il perd sa voyelle finale qui est remplacée par une apostrophe).

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Il prenait tranquillement un verre lorsqu’une femme souriante l’a abordé.

**Explication proposée**

> La phrase est correcte.
> 
> Devant une, lorsque s’élide : le e final est remplacé par une apostrophe. La forme lorsqu’une est correcte.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 2 (index à partir de 0), copie `module2_test1::module2_docx_003`.
- **Test général : réviser le module 2** : réserve, position 28 (index à partir de 0), copie `module2_general::module2_docx_003`.

### Q4 — « lorsque » / « lorsqu’ »

Clé : `module2_docx_004`. Cours : **« lorsque » / « lorsqu’ »** (`lorsque.docx`).

**Texte source**

> Toute la famille se baladait au bord la mer lorsque une averse soudaine la surprit.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Toute la famille se baladait au bord la mer lorsqu’une averse soudaine la surprit. » « Lorsque » précède « une » : élision.

**Texte proposé après retouche validée**

> Toute la famille se baladait au bord de la mer lorsque une averse soudaine la surprit.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Toute la famille se baladait au bord de la mer lorsqu’une averse soudaine la surprit.

**Explication proposée**

> Correction : « Toute la famille se baladait au bord de la mer lorsqu’une averse soudaine la surprit. »
> 
> Devant une, on écrit lorsqu’une. L’ajout éditorial de de dans au bord de la mer permet de ne laisser qu’une erreur à repérer.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 15 (index à partir de 0), copie `module2_test1::module2_docx_004`.
- **Test général : réviser le module 2** : initiale, position 1 (index à partir de 0), copie `module2_general::module2_docx_004`.

**Autres règles présentes** : Construction au bord de.


### Q5 — Les nombres

Clé : `module2_docx_005`. Cours : **Les nombres** (`les-nombres.docx`).

**Texte source**

> Ils habitent désormais au numéro six-cents de la rue François-Mauriac.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Ils habitent désormais au numéro six-cent de la rue François-Mauriac. » « Cent » est un déterminant numéral ordinal : il reste au singulier.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ils habitent désormais au numéro six-cent de la rue François-Mauriac.

**Explication proposée**

> Correction : « Ils habitent désormais au numéro six-cent de la rue François-Mauriac. »
> 
> Le nombre désigne ici un numéro de rue, donc un rang. Dans cet emploi ordinal, cent reste invariable : numéro six-cent. La graphie traditionnelle six cent est également admise.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 18 (index à partir de 0), copie `module2_test1::module2_docx_005`.
- **Test général : réviser le module 2** : initiale, position 2 (index à partir de 0), copie `module2_general::module2_docx_005`.

### Q6 — Les nombres

Clé : `module2_docx_006`. Cours : **Les nombres** (`les-nombres.docx`).

**Texte source**

> Neuf-cent euros ! Quelle folie de payer un téléphone portable ce prix-là !

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !  « Neuf-cents euros ! Quelle folie de payer un téléphone portable ce prix-là ! » « Cent » est multiplié par « neuf » et n’est pas suivi d’un autre nombre : il s’accorde.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Neuf-cents euros ! Quelle folie de payer un téléphone portable ce prix-là !

**Explication proposée**

> Correction : « Neuf-cents euros ! Quelle folie de payer un téléphone portable ce prix-là ! »
> 
> Cent est multiplié par neuf et n’est suivi d’aucun autre nombre : il prend un s. Euros est un nom, pas un nombre. On écrit neuf-cents euros, ou neuf cents euros dans la graphie traditionnelle.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 4 (index à partir de 0), copie `module2_test1::module2_docx_006`.
- **Test général : réviser le module 2** : réserve, position 32 (index à partir de 0), copie `module2_general::module2_docx_006`.

### Q7 — « demi » / « à demi » / « et demi »

Clé : `module2_docx_007`. Cours : **« demi » / « à demi » / « et demi »** (`demi-a-demi.docx`).

**Texte source**

> Au vu des demi-mesures proposées, les résultats attendus ne sont pas garantis.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Demi » placé devant un nom est invariable ; le trait d’union est de rigueur.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Au vu des demi-mesures proposées, les résultats attendus ne sont pas garantis.

**Explication proposée**

> La phrase est correcte.
> 
> Placé avant le nom, demi est invariable et relié à ce nom par un trait d’union : des demi-mesures.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 6 (index à partir de 0), copie `module2_test1::module2_docx_007`.
- **Test général : réviser le module 2** : réserve, position 36 (index à partir de 0), copie `module2_general::module2_docx_007`.

### Q8 — « demi » / « à demi » / « et demi »

Clé : `module2_docx_008`. Cours : **« demi » / « à demi » / « et demi »** (`demi-a-demi.docx`).

**Texte source**

> Le vol Paris-Montréal a duré sept heures et demi.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !  « Le vol Paris-Montréal a duré sept heures et demie. » On accorde « et demi » en genre seulement avec le nom « heure », donc au féminin.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Le vol Paris-Montréal a duré sept heures et demie.

**Explication proposée**

> Correction : « Le vol Paris-Montréal a duré sept heures et demie. »
> 
> Après un nom, et demi s’accorde en genre seulement. Heure est féminin : sept heures et demie, sans s à demie.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 7 (index à partir de 0), copie `module2_test1::module2_docx_008`.
- **Test général : réviser le module 2** : réserve, position 37 (index à partir de 0), copie `module2_general::module2_docx_008`.

### Q9 — « certes »

Clé : `module2_docx_009`. Cours : **« certes »** (`certes.docx`).

**Texte source**

> Ces classeurs sont rangés, certes, mais pas au bon endroit.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact !  Phrase correcte. « Certes » marque ici une opposition.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ces classeurs sont rangés, certes, mais pas au bon endroit.

**Explication proposée**

> La phrase est correcte.
> 
> Certes prend toujours un s. Il marque ici une concession : on admet que les classeurs sont rangés avant d’introduire une réserve avec mais.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 24 (index à partir de 0), copie `module2_test1::module2_docx_009`.
- **Test général : réviser le module 2** : réserve, position 41 (index à partir de 0), copie `module2_general::module2_docx_009`.

### Q10 — « certes »

Clé : `module2_docx_010`. Cours : **« certes »** (`certes.docx`).

**Texte source**

> Le chat est plus indépendant que le chien, certe.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !  « Le chat est plus indépendant que le chien, certes. » « Certes » renforce l’affirmation dans cette phrase.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Le chat est plus indépendant que le chien, certes.

**Explication proposée**

> Correction : « Le chat est plus indépendant que le chien, certes. »
> 
> Certes est un adverbe invariable qui s’écrit toujours avec un s final, même lorsqu’il termine la phrase.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 9 (index à partir de 0), copie `module2_test1::module2_docx_010`.
- **Test général : réviser le module 2** : réserve, position 42 (index à partir de 0), copie `module2_general::module2_docx_010`.

### Q11 — « plutôt » / « plus tôt »

Clé : `module2_docx_011`. Cours : **« plutôt » / « plus tôt »** (`plutot.docx`).

**Texte source**

> Plus tôt que de me regarder faire, viens donc m’aider à trier ces papiers.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !« Plutôt que de me regarder faire, viens donc m’aider à trier ces papiers. » On ne pourrait dire « plus tard que de me regarder faire … ». Il s’agit donc de l’adverbe « plutôt » qui impose l’idée d’un autre choix.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Plutôt que de me regarder faire, viens donc m’aider à trier ces papiers.

**Explication proposée**

> Correction : « Plutôt que de me regarder faire, viens donc m’aider à trier ces papiers. »
> 
> Plutôt que exprime ici un autre choix : aider au lieu de regarder. Il ne s’agit pas d’une comparaison dans le temps, contrairement à plus tôt.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 10 (index à partir de 0), copie `module2_test1::module2_docx_011`.
- **Test général : réviser le module 2** : réserve, position 44 (index à partir de 0), copie `module2_general::module2_docx_011`.

### Q12 — « plutôt » / « plus tôt »

Clé : `module2_docx_012`. Cours : **« plutôt » / « plus tôt »** (`plutot.docx`).

**Texte source**

> Es-tu plutôt « sucré » ou « salé » ?

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact !  Phrase correcte. C’est la préférence qui est exprimée.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Es-tu plutôt « sucré » ou « salé » ?

**Explication proposée**

> La phrase est correcte.
> 
> Plutôt exprime une préférence entre deux possibilités : sucré ou salé. Il s’écrit en un seul mot.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 27 (index à partir de 0), copie `module2_test1::module2_docx_012`.
- **Test général : réviser le module 2** : initiale, position 5 (index à partir de 0), copie `module2_general::module2_docx_012`.

### Q13 — « on » / « on n’ »

Clé : `module2_docx_013`. Cours : **« on » / « on n’ »** (`on.docx`).

**Texte source**

> On n’en a rien à faire de ses histoires !

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact !  Phrase correcte. « Rien » marque le deuxième terme de la négation.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> On n’en a rien à faire de ses histoires !

**Explication proposée**

> La phrase est correcte.
> 
> La négation se construit avec ne… rien. Devant en, ne devient n’ : on n’en a rien à faire.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 12 (index à partir de 0), copie `module2_test2::module2_docx_013`.
- **Test général : réviser le module 2** : réserve, position 49 (index à partir de 0), copie `module2_general::module2_docx_013`.

### Q14 — « on » / « on n’ »

Clé : `module2_docx_014`. Cours : **« on » / « on n’ »** (`on.docx`).

**Texte source**

> Ces cours de gestion sont inintéressants : on y progresse que très peu finalement.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !  « Ces cours de gestion sont inintéressants : on n’y progresse que très peu finalement. » Si l’oral ne permet aucune distinction, « que » indique l’idée de restriction et oblige à l’usage de l’adverbe « n’ » précédemment.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ces cours de gestion sont inintéressants : on n’y progresse que très peu finalement.

**Explication proposée**

> Correction : « Ces cours de gestion sont inintéressants : on n’y progresse que très peu finalement. »
> 
> La restriction s’exprime par ne… que. Devant y, ne s’élide : on n’y progresse que très peu. La liaison de on y ne remplace pas le n’ écrit.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 0 (index à partir de 0), copie `module2_test2::module2_docx_014`.
- **Test général : réviser le module 2** : réserve, position 50 (index à partir de 0), copie `module2_general::module2_docx_014`.

### Q15 — « exigence » / « exigeant »

Clé : `module2_docx_015`. Cours : **« exigence » / « exigeant »** (`exigeant-exigence.docx`).

**Texte source**

> Exigente, elle a demandé un devis détaillé pour chacun des travaux de sa maison.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !  « Exigeante, elle a demandé un devis détaillé pour chacun des travaux de sa maison. » L’adjectif « exigeante » peut être remplacé par l’adjectif « sévère », par exemple.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Exigeante, elle a demandé un devis détaillé pour chacun des travaux de sa maison.

**Explication proposée**

> Correction : « Exigeante, elle a demandé un devis détaillé pour chacun des travaux de sa maison. »
> 
> Exigeante est l’adjectif féminin d’exigeant : il s’écrit avec eant. On peut le remplacer ici par sévère, contrairement au nom exigence.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 2 (index à partir de 0), copie `module2_test2::module2_docx_015`.
- **Test général : réviser le module 2** : réserve, position 54 (index à partir de 0), copie `module2_general::module2_docx_015`.

### Q16 — « exigence » / « exigeant »

Clé : `module2_docx_016`. Cours : **« exigence » / « exigeant »** (`exigeant-exigence.docx`).

**Texte source**

> S’ils ne répondent pas à toutes ses exigences, ils vont avoir affaire à lui.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Le nom « exigences » équivaut au nom « demandes » et s’écrit donc avec un « e » seul.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> S’ils ne répondent pas à toutes ses exigences, ils vont avoir affaire à lui.

**Explication proposée**

> La phrase est correcte.
> 
> Exigences est un nom que l’on pourrait remplacer par demandes. Il s’écrit avec ence et prend le pluriel après toutes ses.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 3 (index à partir de 0), copie `module2_test2::module2_docx_016`.
- **Test général : réviser le module 2** : réserve, position 55 (index à partir de 0), copie `module2_general::module2_docx_016`.

### Q17 — « employé » / « employer »

Clé : `module2_docx_017`. Cours : **« employé » / « employer »** (`employe-employer.docx`).

**Texte source**

> Avec le travail qui leur est demandé, les employés ont des journées à rallonge.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Il s’agit du nom « employés », précédé du déterminant « les ». Il serait possible de remplacer le nom « employés » par le nom « collaborateurs ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Avec le travail qui leur est demandé, les employés ont des journées à rallonge.

**Explication proposée**

> La phrase est correcte.
> 
> Employés désigne les personnes qui travaillent : c’est un nom au pluriel, précédé de les. On peut le remplacer par collaborateurs.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 4 (index à partir de 0), copie `module2_test2::module2_docx_017`.
- **Test général : réviser le module 2** : réserve, position 59 (index à partir de 0), copie `module2_general::module2_docx_017`.

### Q18 — « employé » / « employer »

Clé : `module2_docx_018`. Cours : **« employé » / « employer »** (`employe-employer.docx`).

**Texte source**

> Ils ont l’habitude d’employer du personnel pour la haute saison.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Un verbe à l’infinitif comme « prendre » pourrait convenir. Il s’agit donc de l’infinitif « employer ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ils ont l’habitude d’employer du personnel pour la haute saison.

**Explication proposée**

> La phrase est correcte.
> 
> Après avoir l’habitude de, on emploie ici un infinitif : employer. Le remplacement par recruter confirme la terminaison er.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 5 (index à partir de 0), copie `module2_test2::module2_docx_018`.
- **Test général : réviser le module 2** : réserve, position 60 (index à partir de 0), copie `module2_general::module2_docx_018`.

### Q19 — « faites » / « faîte »

Clé : `module2_docx_019`. Cours : **« faites » / « faîte »** (`faites-faite.docx`).

**Texte source**

> Vous ne trouvez pas le même résultat que moi quand vous refaites les calculs.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Le verbe « refaire » est conjugué au présent de l’indicatif, à la 2e personne du pluriel.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Vous ne trouvez pas le même résultat que moi quand vous refaites les calculs.

**Explication proposée**

> La phrase est correcte.
> 
> Refaire suit la conjugaison de faire : vous faites, vous refaites. Le cours inclut explicitement les verbes construits avec faire.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 24 (index à partir de 0), copie `module2_test2::module2_docx_019`.
- **Test général : réviser le module 2** : réserve, position 64 (index à partir de 0), copie `module2_general::module2_docx_019`.

### Q20 — « faites » / « faîte »

Clé : `module2_docx_020`. Cours : **« faites » / « faîte »** (`faites-faite.docx`).

**Texte source**

> Vous nous faisez sûrement une bonne blague en nous disant cela !

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Vous nous faites sûrement une bonne blague en nous disant cela ! » « Faisez » est interdit !...

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Vous nous faites sûrement une bonne blague en nous disant cela !

**Explication proposée**

> Correction : « Vous nous faites sûrement une bonne blague en nous disant cela ! »
> 
> Au présent, à la deuxième personne du pluriel, faire se conjugue vous faites. La forme faisez est incorrecte.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 6 (index à partir de 0), copie `module2_test2::module2_docx_020`.
- **Test général : réviser le module 2** : réserve, position 65 (index à partir de 0), copie `module2_general::module2_docx_020`.

**Variante proche** : Q98 (texte différent).


### Q21 — « presque » / « presqu’ »

Clé : `module2_docx_021`. Cours : **« presque » / « presqu’ »** (`presque.docx`).

**Texte source**

> Son accouchement était prévu en mai : elle a accouché presqu’à terme.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !« Son accouchement était prévu en mai : elle a accouché presque à terme. » Attention : même s’il ne s’entend pas à l’oral, « presque » ne s’élide pas devant une voyelle, sauf dans le nom « presqu'île ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Son accouchement était prévu en mai : elle a accouché presque à terme.

**Explication proposée**

> Correction : « Son accouchement était prévu en mai : elle a accouché presque à terme. »
> 
> Presque conserve son e devant une voyelle : presque à terme. L’élision se rencontre dans le nom presqu’île, pas ici.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 8 (index à partir de 0), copie `module2_test2::module2_docx_021`.
- **Test général : réviser le module 2** : réserve, position 69 (index à partir de 0), copie `module2_general::module2_docx_021`.

**Variante proche** : Q99 (texte différent).


### Q22 — « presque » / « presqu’ »

Clé : `module2_docx_022`. Cours : **« presque » / « presqu’ »** (`presque.docx`).

**Texte source**

> Il faudrait presque lui apporter ses affaires afin qu’il puisse se changer sur place.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Il faudrait presque lui apporter ses affaires afin qu’il puisse se changer sur place.

**Explication proposée**

> La phrase est correcte.
> 
> Presque est correctement écrit en entier devant lui. Il signifie ici quasiment.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 28 (index à partir de 0), copie `module2_test2::module2_docx_022`.
- **Test général : réviser le module 2** : réserve, position 70 (index à partir de 0), copie `module2_general::module2_docx_022`.

### Q23 — Adverbes en « -amment » / « -emment »

Clé : `module2_docx_023`. Cours : **Adverbes en « -amment » / « -emment »** (`adverbes-en-amment-emment.docx`).

**Texte source**

> La sortie de ce film a été savamment orchestrée.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. L’adverbe « savamment » est construit sur l’adjectif « savant », orthographié avec un « a ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> La sortie de ce film a été savamment orchestrée.

**Explication proposée**

> La phrase est correcte.
> 
> Savant se termine par ant : l’adverbe correspondant est savamment, avec a et deux m.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 32 (index à partir de 0), copie `module2_test2::module2_docx_023`.
- **Test général : réviser le module 2** : réserve, position 74 (index à partir de 0), copie `module2_general::module2_docx_023`.

### Q24 — Adverbes en « -amment » / « -emment »

Clé : `module2_docx_024`. Cours : **Adverbes en « -amment » / « -emment »** (`adverbes-en-amment-emment.docx`).

**Texte source**

> Les hockeyeurs canadiens se sont brillament illustrés lors du dernier championnat.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !« Les hockeyeurs canadiens se sont brillamment illustrés lors du dernier championnat. » Attention ! N’oubliez pas les deux « m » pour former l’adverbe « brillamment » à partir de l’adjectif « brillant ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les hockeyeurs canadiens se sont brillamment illustrés lors du dernier championnat.

**Explication proposée**

> Correction : « Les hockeyeurs canadiens se sont brillamment illustrés lors du dernier championnat. »
> 
> L’adjectif brillant se termine par ant. L’adverbe se termine donc par amment : brillamment, avec deux m.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 10 (index à partir de 0), copie `module2_test2::module2_docx_024`.
- **Test général : réviser le module 2** : réserve, position 75 (index à partir de 0), copie `module2_general::module2_docx_024`.

### Q25 — « d’en » / « dans »

Clé : `module2_docx_025`. Cours : **« d’en » / « dans »** (`d-en-dans.docx`).

**Texte source**

> Ce serait mieux d’en parler à quelqu’un de compétent.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. On pourrait dire « Ce serait mieux de parler (de ça) à quelqu’un de compétent ». Autre repère : « parler » est un verbe à l’infinitif.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ce serait mieux d’en parler à quelqu’un de compétent.

**Explication proposée**

> La phrase est correcte.
> 
> D’en réunit la préposition de et le pronom en. En reprend ce dont on parle : de parler de cela.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 79 (index à partir de 0), copie `module2_general::module2_docx_025`.

### Q26 — « d’en » / « dans »

Clé : `module2_docx_026`. Cours : **« d’en » / « dans »** (`d-en-dans.docx`).

**Texte source**

> Ce sont des fleurs fragiles ; pendant mon absence, je te demanderai dans prendre soin.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Ce sont des fleurs fragiles ; pendant mon absence, je te demanderai d’en prendre soin. » Ou « de prendre soin des fleurs ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ce sont des fleurs fragiles ; pendant mon absence, je te demanderai d’en prendre soin.

**Explication proposée**

> Correction : « Ce sont des fleurs fragiles ; pendant mon absence, je te demanderai d’en prendre soin. »
> 
> Prendre soin se construit avec de. En reprend les fleurs : demander de prendre soin des fleurs devient demander d’en prendre soin.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 80 (index à partir de 0), copie `module2_general::module2_docx_026`.

### Q27 — « pause » / « pose »

Clé : `module2_docx_027`. Cours : **« pause » / « pose »** (`pause-pose.docx`).

**Texte source**

> Afin de le calmer, il a posé sa main sur la sienne.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Le verbe « poser » a le sens de « mettre ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Afin de le calmer, il a posé sa main sur la sienne.

**Explication proposée**

> La phrase est correcte.
> 
> Poser signifie ici mettre ou déposer la main. Ce verbe s’écrit avec o ; pause désigne une interruption.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 84 (index à partir de 0), copie `module2_general::module2_docx_027`.

### Q28 — « pause » / « pose »

Clé : `module2_docx_028`. Cours : **« pause » / « pose »** (`pause-pose.docx`).

**Texte source**

> Deux heures de cours magistral sans pause, c’est franchement long !

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Il s’agit ici d’un temps de coupure, d’un arrêt momentané.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Deux heures de cours magistral sans pause, c’est franchement long !

**Explication proposée**

> La phrase est correcte.
> 
> Une pause est un arrêt momentané de l’activité. Dans deux heures de cours sans pause, il s’agit bien de cette interruption.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 85 (index à partir de 0), copie `module2_general::module2_docx_028`.

### Q29 — « voilà »

Clé : `module2_docx_029`. Cours : **« voilà »** (`voila.docx`).

**Texte source**

> Les voilà qui arrivent !

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact !  Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les voilà qui arrivent !

**Explication proposée**

> La phrase est correcte.
> 
> Voilà est invariable et prend un accent grave sur le a, y compris dans les voilà.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 89 (index à partir de 0), copie `module2_general::module2_docx_029`.

### Q30 — « voilà »

Clé : `module2_docx_030`. Cours : **« voilà »** (`voila.docx`).

**Texte source**

> J’ai assisté à l’entretien et voila ce qui s’y est dit…

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « J’ai assisté à l’entretien et voilà ce qui s’y est dit… » . « Voilà » prend toujours un accent sur le « a ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> J’ai assisté à l’entretien et voilà ce qui s’y est dit…

**Explication proposée**

> Correction : « J’ai assisté à l’entretien et voilà ce qui s’y est dit… »
> 
> Voilà prend toujours un accent grave sur le a. Il faut donc remplacer voila par voilà.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 90 (index à partir de 0), copie `module2_general::module2_docx_030`.

**Variante proche** : Q70 (texte différent).


### Q31 — « près de » / « prêt à »

Clé : `module2_docx_031`. Cours : **« près de » / « prêt à »** (`pret-de-pret-a.docx`).

**Texte source**

> Quand les enfants seront prêt à partir, mon père les emmènera en voiture à la gare.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !« Quand les enfants seront prêts à partir, mon père les emmènera en voiture à la gare. « Prêts » peut être remplacé par « disposés » : c’est un adjectif qui s’accorde en genre et en nombre, avec le nom « enfants », au masculin pluriel.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Quand les enfants seront prêts à partir, mon père les emmènera en voiture à la gare.

**Explication proposée**

> Correction : « Quand les enfants seront prêts à partir, mon père les emmènera en voiture à la gare. »
> 
> Prêt est un adjectif signifiant disposé. Il s’accorde avec les enfants, masculin pluriel : prêts à partir.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 15 (index à partir de 0), copie `module2_general::module2_docx_031`.

### Q32 — « près de » / « prêt à »

Clé : `module2_docx_032`. Cours : **« près de » / « prêt à »** (`pret-de-pret-a.docx`).

**Texte source**

> Nous étions prêts d’arriver à Bordeaux lorsque nous sommes tombés dans un embouteillage.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Nous étions près d’arriver à Bordeaux lorsque nous sommes tombés dans un embouteillage. » Il serait possible de dire « Nous étions sur le point d’arriver… » ; « près » est dans ce cas toujours suivi de la préposition « de ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Nous étions près d’arriver à Bordeaux lorsque nous sommes tombés dans un embouteillage.

**Explication proposée**

> Correction : « Nous étions près d’arriver à Bordeaux lorsque nous sommes tombés dans un embouteillage. »
> 
> Être près de signifie ici être sur le point de. On écrit près d’arriver, sans accord de près. Prêt à signifierait disposé à faire quelque chose.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 92 (index à partir de 0), copie `module2_general::module2_docx_032`.

### Q33 — « tache » / « tâche »

Clé : `module2_docx_033`. Cours : **« tache » / « tâche »** (`tache.docx`).

**Texte source**

> Les couvreurs tacheront de terminer le toit d’ici vendredi soir.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Les couvreurs tâcheront de terminer le toit d’ici vendredi soir. » Ils feront l’effort de le terminer...

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les couvreurs tâcheront de terminer le toit d’ici vendredi soir.

**Explication proposée**

> Correction : « Les couvreurs tâcheront de terminer le toit d’ici vendredi soir. »
> 
> Tâcher de signifie essayer de ou faire l’effort de. Il faut l’accent circonflexe : les couvreurs tâcheront. Tacher signifie salir.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 96 (index à partir de 0), copie `module2_general::module2_docx_033`.

### Q34 — « tache » / « tâche »

Clé : `module2_docx_034`. Cours : **« tache » / « tâche »** (`tache.docx`).

**Texte source**

> Depuis leur embauche, on ne leur confie que des taches ingrates.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Depuis leur embauche, on ne leur confie que des tâches ingrates. » Il s’agit de « besognes ingrates », et non de « traces ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Depuis leur embauche, on ne leur confie que des tâches ingrates.

**Explication proposée**

> Correction : « Depuis leur embauche, on ne leur confie que des tâches ingrates. »
> 
> Une tâche est un travail ou une besogne à accomplir. Il faut donc écrire des tâches ingrates, avec un accent circonflexe.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 97 (index à partir de 0), copie `module2_general::module2_docx_034`.

### Q35 — « à l’attention » / « à l’intention »

Clé : `module2_docx_035`. Cours : **« à l’attention » / « à l’intention »** (`a-l-attention-a-l-intention.docx`).

**Texte source**

> Pauline a prévu une surprise à l’intention de son fiancé.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Pauline a prévu une surprise à l’intention de son fiancé.

**Explication proposée**

> La phrase est correcte.
> 
> La surprise est préparée spécialement pour le fiancé : à l’intention de signifie ici pour lui, pour lui faire plaisir.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 101 (index à partir de 0), copie `module2_general::module2_docx_035`.

### Q36 — « à l’attention » / « à l’intention »

Clé : `module2_docx_036`. Cours : **« à l’attention » / « à l’intention »** (`a-l-attention-a-l-intention.docx`).

**Texte source**

> C’est à son attention que j’ai acheté ces perles de culture.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « C’est à son intention que j’ai acheté ces perles de culture. » Il s’agit d’un cadeau personnel fait à une personne chère.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> C’est à son intention que j’ai acheté ces perles de culture.

**Explication proposée**

> Correction : « C’est à son intention que j’ai acheté ces perles de culture. »
> 
> Les perles ont été achetées pour cette personne : à son intention. À l’attention de sert notamment à désigner la personne à qui un courrier doit être remis ou soumis.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 102 (index à partir de 0), copie `module2_general::module2_docx_036`.

### Q37 — « quasi »

Clé : `module2_docx_037`. Cours : **« quasi »** (`quasi.docx`).

**Texte source**

> Mon compte-rendu est quasi terminé.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Il n’y a pas de trait d’union entre « quasi » et le participe passé adjectival « terminé ».

**Texte proposé après retouche validée**

> Mon compte rendu est quasi terminé.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Mon compte rendu est quasi terminé.

**Explication proposée**

> La phrase est correcte.
> 
> Quasi n’est pas lié par un trait d’union à un adjectif ou à un participe : quasi terminé. Compte rendu est écrit sans trait d’union dans le texte proposé.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 104 (index à partir de 0), copie `module2_general::module2_docx_037`.

**Autres règles présentes** : Graphie de compte rendu.


### Q38 — « quasi »

Clé : `module2_docx_038`. Cours : **« quasi »** (`quasi.docx`).

**Texte source**

> C’est une quasi-certitude : nous aurons beaucoup de visiteurs pour cette expo.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Certitude » étant un nom, un trait d’union est nécessaire entre « quasi » et ce nom.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> C’est une quasi-certitude : nous aurons beaucoup de visiteurs pour cette expo.

**Explication proposée**

> La phrase est correcte.
> 
> Devant un nom, quasi se rattache par un trait d’union : une quasi-certitude. Certitude est un nom.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 19 (index à partir de 0), copie `module2_general::module2_docx_038`.

### Q39 — « tout » (2)

Clé : `module2_docx_039`. Cours : **« tout » (2)** (`tout-2.docx`).

**Texte source**

> Tout fiers de leur invention, ces ingénieurs ont décidé de la breveter.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Tout » a le sens de « tout à fait » ou « totalement », c’est ici un adverbe : il est invariable. L’adjectif « fiers » s’accorde avec le groupe nominal « ces ingénieurs », masculin pluriel.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Tout fiers de leur invention, ces ingénieurs ont décidé de la breveter.

**Explication proposée**

> La phrase est correcte.
> 
> Tout signifie ici très ou tout à fait : c’est un adverbe. Devant l’adjectif masculin fiers, il reste invariable, même si ingénieurs est au pluriel.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 21 (index à partir de 0), copie `module2_general::module2_docx_039`.

### Q40 — « tout » (2)

Clé : `module2_docx_040`. Cours : **« tout » (2)** (`tout-2.docx`).

**Texte source**

> Face à ces révélations, elles sont devenues tout honteuses.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Face à ces révélations, elles sont devenues toutes honteuses. » L’adjectif « honteuses » s’écrit avec un « h » aspiré : « toutes » s’accorde en genre et en nombre avec le pronom « elles », féminin pluriel.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Face à ces révélations, elles sont devenues toutes honteuses.

**Explication proposée**

> Correction : « Face à ces révélations, elles sont devenues toutes honteuses. »
> 
> Tout adverbe s’accorde devant un adjectif féminin commençant par une consonne ou un h aspiré. Honteuses commence par un h aspiré : toutes honteuses.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 22 (index à partir de 0), copie `module2_general::module2_docx_040`.

### Q41 — Les jours de la semaine

Clé : `module2_docx_041`. Cours : **Les jours de la semaine** (`jours-de-la-semaine.docx`).

**Texte source**

> Tu ne vas tout de même pas m’appeler tous les samedi soir pour savoir ce que je fais !

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Tu ne vas tout de même pas m’appeler tous les samedis soir pour savoir ce que je fais ! »

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Tu ne vas tout de même pas m’appeler tous les samedis soir pour savoir ce que je fais !

**Explication proposée**

> Correction : « Tu ne vas tout de même pas m’appeler tous les samedis soir pour savoir ce que je fais ! »
> 
> L’expression tous les évoque plusieurs samedis : samedi prend un s. Soir est laissé invariable dans la construction traditionnelle.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 12 (index à partir de 0), copie `module2_test1::module2_docx_041`.
- **Test général : réviser le module 2** : initiale, position 0 (index à partir de 0), copie `module2_general::module2_docx_041`.

**Variante proche** : Q1 (texte différent).


### Q42 — Les jours de la semaine

Clé : `module2_docx_042`. Cours : **Les jours de la semaine** (`jours-de-la-semaine.docx`).

**Texte source**

> Les vendredis après-midi, les footballeurs ont un entraînement intensif de deux heures.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Après-midi » est un mot invariable.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les vendredis après-midi, les footballeurs ont un entraînement intensif de deux heures.

**Explication proposée**

> La phrase est correcte.
> 
> Vendredis prend un s puisqu’il s’agit de plusieurs vendredis. La graphie traditionnelle après-midi sans s est correcte ; après-midis est également admis selon les rectifications de 1990.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 13 (index à partir de 0), copie `module2_test1::module2_docx_042`.
- **Test général : réviser le module 2** : réserve, position 25 (index à partir de 0), copie `module2_general::module2_docx_042`.

### Q43 — « lorsque » / « lorsqu’ »

Clé : `module2_docx_043`. Cours : **« lorsque » / « lorsqu’ »** (`lorsque.docx`).

**Texte source**

> Tu pourras prendre ton dessert seulement lorsque tu auras fini ton poisson.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Tu pourras prendre ton dessert seulement lorsque tu auras fini ton poisson.

**Explication proposée**

> La phrase est correcte.
> 
> Lorsque ne s’élide pas devant tu, qui commence par une consonne. La forme lorsque tu est correcte.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 16 (index à partir de 0), copie `module2_test1::module2_docx_043`.
- **Test général : réviser le module 2** : réserve, position 29 (index à partir de 0), copie `module2_general::module2_docx_043`.

### Q44 — « lorsque » / « lorsqu’ »

Clé : `module2_docx_044`. Cours : **« lorsque » / « lorsqu’ »** (`lorsque.docx`).

**Texte source**

> Lorsqu’à 20 heures le journal télé débute, mon grand-père ne veut plus un bruit à table.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Lorsque à 20 h le journal télé débute, mon grand-père ne veut plus un bruit à table. » Attention ! Devant la préposition « à », « lorsque » ne s’élide pas.

**En attente — aucune affectation**

Élision « lorsqu’à » admise dans l’usage contemporain ; corrigé trop restrictif.

**Catégorie documentaire suggérée** : `false` ; non importable.

**Texte de référence documentaire**

> Lorsqu’à 20 heures le journal télé débute, mon grand-père ne veut plus un bruit à table.


### Q45 — Les nombres

Clé : `module2_docx_045`. Cours : **Les nombres** (`les-nombres.docx`).

**Texte source**

> À quelle distance correspondent dix mille marins ?

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « À quelle distance correspondent dix milles marins ? » Il s’agit ici du vocabulaire maritime : « mille » est un nom et il s’accorde avec « dix ». Il ne peut y avoir de trait d’union entre les deux puisqu’il ne s’agit pas ici du nombre « dix-mille ». Attention au sens !

**En attente — aucune affectation**

Mise en attente validée conservée. Vérification ultérieure : le cours les-nombres.docx enseigne bien le mille marin. Rattachement confirmé ; source réintégrable dans une prochaine révision explicite, aucune affectation dans cette version.

**Catégorie documentaire suggérée** : `true` ; non importable.

**Texte de référence documentaire**

> À quelle distance correspondent dix milles marins ?


### Q46 — Les nombres

Clé : `module2_docx_046`. Cours : **Les nombres** (`les-nombres.docx`).

**Texte source**

> Les coupes de cheveux des années quatre-vingt sont mémorables !

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Quatre-vingt » est un adjectif numeral ordinal exprimant une date. Il ne s’accorde pas.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les coupes de cheveux des années quatre-vingt sont mémorables !

**Explication proposée**

> La phrase est correcte.
> 
> Quatre-vingt désigne les années d’une décennie, donc une référence chronologique. Vingt reste sans s dans cet emploi, comme dans un numéro de page.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 19 (index à partir de 0), copie `module2_test1::module2_docx_046`.
- **Test général : réviser le module 2** : réserve, position 33 (index à partir de 0), copie `module2_general::module2_docx_046`.

### Q47 — « demi » / « à demi » / « et demi »

Clé : `module2_docx_047`. Cours : **« demi » / « à demi » / « et demi »** (`demi-a-demi.docx`).

**Texte source**

> Après ce choc contre le poteau, la pauvre grand-mère était à demi-inconsciente.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Après ce choc contre le poteau, la pauvre grand-mère était à demi inconsciente. » « À demi » est placé devant l’adjectif « inconsciente » : il n’y a pas de trait d’union.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Après ce choc contre le poteau, la pauvre grand-mère était à demi inconsciente.

**Explication proposée**

> Correction : « Après ce choc contre le poteau, la pauvre grand-mère était à demi inconsciente. »
> 
> Dans la locution à demi placée devant un adjectif, on ne met pas de trait d’union : à demi inconsciente. Ne pas la confondre avec demi placé avant un nom.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 8 (index à partir de 0), copie `module2_test1::module2_docx_047`.
- **Test général : réviser le module 2** : réserve, position 38 (index à partir de 0), copie `module2_general::module2_docx_047`.

**Variante proche** : Q88 (texte différent).


### Q48 — « demi » / « à demi » / « et demi »

Clé : `module2_docx_048`. Cours : **« demi » / « à demi » / « et demi »** (`demi-a-demi.docx`).

**Texte source**

> Mets deux cuillérées et demies supplémentaires d’huile afin que la pâte ne colle pas au moule.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Mets deux cuillérées et demie supplémentaires d’huile afin que la pâte ne colle pas au moule. » Dans la construction « et demi », « demi » s’accorde en genre seulement avec le nom « cuillérées », au féminin donc.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Mets deux cuillérées et demie supplémentaires d’huile afin que la pâte ne colle pas au moule.

**Explication proposée**

> Correction : « Mets deux cuillérées et demie supplémentaires d’huile afin que la pâte ne colle pas au moule. »
> 
> Dans et demi, l’accord se fait en genre seulement. Cuillérées est féminin : deux cuillérées et demie, sans s à demie. Cuillérées et cuillerées sont deux graphies admises.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 21 (index à partir de 0), copie `module2_test1::module2_docx_048`.
- **Test général : réviser le module 2** : initiale, position 3 (index à partir de 0), copie `module2_general::module2_docx_048`.

### Q49 — « certes »

Clé : `module2_docx_049`. Cours : **« certes »** (`certes.docx`).

**Texte source**

> Certes, vous avez raison sur ce point.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Certes, vous avez raison sur ce point.

**Explication proposée**

> La phrase est correcte.
> 
> Certes s’écrit toujours avec un s final. Il renforce ici l’affirmation ou admet le point évoqué.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 25 (index à partir de 0), copie `module2_test1::module2_docx_049`.
- **Test général : réviser le module 2** : initiale, position 4 (index à partir de 0), copie `module2_general::module2_docx_049`.

### Q50 — « certes »

Clé : `module2_docx_050`. Cours : **« certes »** (`certes.docx`).

**Texte source**

> Les feuilles, certe, n’étaient pas agrafées et elles sont maintenant tout éparpillées.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Les feuilles, certes, n’étaient pas agrafées et elles sont maintenant tout éparpillées.

**Texte proposé après retouche validée**

> Les feuilles, certe, n’étaient pas agrafées et elles sont maintenant éparpillées.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les feuilles, certes, n’étaient pas agrafées et elles sont maintenant éparpillées.

**Explication proposée**

> Correction : « Les feuilles, certes, n’étaient pas agrafées et elles sont maintenant éparpillées. »
> 
> L’adverbe certes prend toujours un s final. Il introduit ici une concession. Le texte proposé retire tout, dont l’accord n’est pas l’objet de cette question.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 26 (index à partir de 0), copie `module2_test1::module2_docx_050`.
- **Test général : réviser le module 2** : réserve, position 43 (index à partir de 0), copie `module2_general::module2_docx_050`.

**Autres règles présentes** : Tout adverbe : présent dans l’original, retiré du texte proposé.


### Q51 — « plutôt » / « plus tôt »

Clé : `module2_docx_051`. Cours : **« plutôt » / « plus tôt »** (`plutot.docx`).

**Texte source**

> Le prof n’a pas corrigé le bon exercice, ou plutôt celui que j’avais mal compris.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Plutôt » introduit une rectification dans le propos.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Le prof n’a pas corrigé le bon exercice, ou plutôt celui que j’avais mal compris.

**Explication proposée**

> La phrase est correcte.
> 
> Ou plutôt introduit une rectification de ce qui vient d’être dit. Il ne situe pas un événement plus tôt dans le temps.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 28 (index à partir de 0), copie `module2_test1::module2_docx_051`.
- **Test général : réviser le module 2** : réserve, position 45 (index à partir de 0), copie `module2_general::module2_docx_051`.

### Q52 — « plutôt » / « plus tôt »

Clé : `module2_docx_052`. Cours : **« plutôt » / « plus tôt »** (`plutot.docx`).

**Texte source**

> Afin de soigner ce champignon, il faut voir un dermatologue et le plutôt sera le mieux.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Afin de soigner ce champignon, il faut voir un dermatologue et le plus tôt sera le mieux. » C’est le temps qui est exprimé et il est donc possible de dire « plus tard » (en ne s’occupant toutefois pas du sens).

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Afin de soigner ce champignon, il faut voir un dermatologue et le plus tôt sera le mieux.

**Explication proposée**

> Correction : « Afin de soigner ce champignon, il faut voir un dermatologue et le plus tôt sera le mieux. »
> 
> On parle du moment de la consultation : le plus tôt sera le mieux. L’opposition temporelle plus tard permet de distinguer plus tôt de plutôt.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 29 (index à partir de 0), copie `module2_test1::module2_docx_052`.
- **Test général : réviser le module 2** : réserve, position 46 (index à partir de 0), copie `module2_general::module2_docx_052`.

### Q53 — « on » / « on n’ »

Clé : `module2_docx_053`. Cours : **« on » / « on n’ »** (`on.docx`).

**Texte source**

> Qu’est-ce qu’on attend pour y aller ?

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Il s’agit d’une phrase affirmative.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Qu’est-ce qu’on attend pour y aller ?

**Explication proposée**

> La phrase est correcte.
> 
> La phrase est interrogative, mais ne comporte pas de négation. On attend s’écrit donc sans n’. La liaison après on ne doit pas être confondue avec ne élidé.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 1 (index à partir de 0), copie `module2_test2::module2_docx_053`.
- **Test général : réviser le module 2** : réserve, position 51 (index à partir de 0), copie `module2_general::module2_docx_053`.

### Q54 — « on » / « on n’ »

Clé : `module2_docx_054`. Cours : **« on » / « on n’ »** (`on.docx`).

**Texte source**

> « Quand on a que l’amour » est une célèbre chanson de Jacques Brel.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « “Quand on n’a que l’amour” » est une célèbre chanson de Jacques Brel. « Quand nous n’avons que l’amour… » ; en remplaçant « on » par « nous », l’adverbe « n’ » est audible et... s'écrit.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> « Quand on n’a que l’amour » est une célèbre chanson de Jacques Brel.

**Explication proposée**

> Correction : « « Quand on n’a que l’amour » est une célèbre chanson de Jacques Brel. »
> 
> La restriction se construit avec ne… que : on n’a que l’amour. Avec nous, le ne est plus facile à entendre : nous n’avons que l’amour.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 13 (index à partir de 0), copie `module2_test2::module2_docx_054`.
- **Test général : réviser le module 2** : initiale, position 6 (index à partir de 0), copie `module2_general::module2_docx_054`.

### Q55 — « exigence » / « exigeant »

Clé : `module2_docx_055`. Cours : **« exigence » / « exigeant »** (`exigeant-exigence.docx`).

**Texte source**

> Pour réussir, il est d’abord très exigeant envers lui-même.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Il est « sévère » envers lui-même. Il s’agit de l’adjectif « exigeant ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Pour réussir, il est d’abord très exigeant envers lui-même.

**Explication proposée**

> La phrase est correcte.
> 
> Exigeant est un adjectif qualifiant la personne. Il peut être remplacé par sévère et s’écrit avec eant.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 16 (index à partir de 0), copie `module2_test2::module2_docx_055`.
- **Test général : réviser le module 2** : réserve, position 56 (index à partir de 0), copie `module2_general::module2_docx_055`.

### Q56 — « exigence » / « exigeant »

Clé : `module2_docx_056`. Cours : **« exigence » / « exigeant »** (`exigeant-exigence.docx`).

**Texte source**

> Exigeant un compte-rendu de la réunion, notre responsable nous a fait partir plus tard du bureau.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. On peut dire « en exigeant… »

**Texte proposé après retouche validée**

> Exigeant un compte rendu de la réunion, notre responsable nous a fait partir plus tard du bureau.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Exigeant un compte rendu de la réunion, notre responsable nous a fait partir plus tard du bureau.

**Explication proposée**

> La phrase est correcte.
> 
> Exigeant est ici le participe présent du verbe exiger : il est invariable et se termine par eant. Il ne s’agit pas du nom exigence. Compte rendu est écrit sans trait d’union dans le texte proposé.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 17 (index à partir de 0), copie `module2_test2::module2_docx_056`.
- **Test général : réviser le module 2** : réserve, position 57 (index à partir de 0), copie `module2_general::module2_docx_056`.

**Autres règles présentes** : Graphie de compte rendu.


### Q57 — « employé » / « employer »

Clé : `module2_docx_057`. Cours : **« employé » / « employer »** (`employe-employer.docx`).

**Texte source**

> Comme les personnes refusaient de sortir de la pièce, ils ont dû employer la force.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Ils ont dû faire intervenir la force » ; il s’agit bien d’un verbe à l’infinitif, donc « employer » l’est également et se termine par « -er ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Comme les personnes refusaient de sortir de la pièce, ils ont dû employer la force.

**Explication proposée**

> La phrase est correcte.
> 
> Après ont dû, employer est à l’infinitif. On pourrait écrire ont dû utiliser la force ; la terminaison er est donc correcte.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 20 (index à partir de 0), copie `module2_test2::module2_docx_057`.
- **Test général : réviser le module 2** : réserve, position 61 (index à partir de 0), copie `module2_general::module2_docx_057`.

### Q58 — « employé » / « employer »

Clé : `module2_docx_058`. Cours : **« employé » / « employer »** (`employe-employer.docx`).

**Texte source**

> Tes employés fêtent ton départ ? C’est une bonne ou une mauvaise nouvelle ?

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Tes collaborateurs fêtent ton départ ? » est possible. Il s’agit du nom « employés ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Tes employés fêtent ton départ ? C’est une bonne ou une mauvaise nouvelle ?

**Explication proposée**

> La phrase est correcte.
> 
> Employés est un nom désignant des personnes, précédé de tes. On pourrait écrire tes collaborateurs. Le nom prend ici le pluriel.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 21 (index à partir de 0), copie `module2_test2::module2_docx_058`.
- **Test général : réviser le module 2** : initiale, position 8 (index à partir de 0), copie `module2_general::module2_docx_058`.

### Q59 — « faites » / « faîte »

Clé : `module2_docx_059`. Cours : **« faites » / « faîte »** (`faites-faite.docx`).

**Texte source**

> Parce que vous satisfaisez à tous nos critères, nous vous embauchons.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Parce que vous satisfaites à tous nos critères, nous vous embauchons. »

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Parce que vous satisfaites à tous nos critères, nous vous embauchons.

**Explication proposée**

> Correction : « Parce que vous satisfaites à tous nos critères, nous vous embauchons. »
> 
> Satisfaire suit la conjugaison de faire : vous faites, vous satisfaites. La forme satisfaisez est incorrecte. Ce dérivé figure dans le cours.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 25 (index à partir de 0), copie `module2_test2::module2_docx_059`.
- **Test général : réviser le module 2** : initiale, position 9 (index à partir de 0), copie `module2_general::module2_docx_059`.

### Q60 — « faites » / « faîte »

Clé : `module2_docx_060`. Cours : **« faites » / « faîte »** (`faites-faite.docx`).

**Texte source**

> Vous faites bien de lui rappeler de prendre ses médicaments : il les oublie une fois sur deux.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Vous faites bien de lui rappeler de prendre ses médicaments : il les oublie une fois sur deux.

**Explication proposée**

> La phrase est correcte.
> 
> Vous faites est la forme correcte de faire au présent, à la deuxième personne du pluriel.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 26 (index à partir de 0), copie `module2_test2::module2_docx_060`.
- **Test général : réviser le module 2** : réserve, position 66 (index à partir de 0), copie `module2_general::module2_docx_060`.

### Q61 — « presque » / « presqu’ »

Clé : `module2_docx_061`. Cours : **« presque » / « presqu’ »** (`presque.docx`).

**Texte source**

> « Un dîner presque parfait » est une émission qui remporte un franc succès.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> « Un dîner presque parfait » est une émission qui remporte un franc succès.

**Explication proposée**

> La phrase est correcte.
> 
> Presque conserve son e devant parfait. Il signifie ici quasiment ; le mot n’est pas élidé.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 29 (index à partir de 0), copie `module2_test2::module2_docx_061`.
- **Test général : réviser le module 2** : réserve, position 71 (index à partir de 0), copie `module2_general::module2_docx_061`.

### Q62 — « presque » / « presqu’ »

Clé : `module2_docx_062`. Cours : **« presque » / « presqu’ »** (`presque.docx`).

**Texte source**

> Pour leurs vacances en Bretagne, ils ont préféré la presque île de Quiberon à celle de Crozon.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Pour leurs vacances en Bretagne, ils ont préféré la presqu’île de Quiberon… » L’exception confirme la règle : le « e » de « presqu’île » s’élide.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Pour leurs vacances en Bretagne, ils ont préféré la presqu’île de Quiberon à celle de Crozon.

**Explication proposée**

> Correction : « Pour leurs vacances en Bretagne, ils ont préféré la presqu’île de Quiberon à celle de Crozon. »
> 
> Presqu’île est le nom qui fait exception à l’absence habituelle d’élision de presque. On écrit une presqu’île, avec une apostrophe.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 9 (index à partir de 0), copie `module2_test2::module2_docx_062`.
- **Test général : réviser le module 2** : réserve, position 72 (index à partir de 0), copie `module2_general::module2_docx_062`.

### Q63 — Adverbes en « -amment » / « -emment »

Clé : `module2_docx_063`. Cours : **Adverbes en « -amment » / « -emment »** (`adverbes-en-amment-emment.docx`).

**Texte source**

> Tu mâches trop bruyamment : ton dentier te gêne ?

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Tu mâches trop bruyamment : ton dentier te gêne ?

**Explication proposée**

> La phrase est correcte.
> 
> Bruyant se termine par ant. L’adverbe correspondant est bruyamment, avec a et deux m.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 33 (index à partir de 0), copie `module2_test2::module2_docx_063`.
- **Test général : réviser le module 2** : réserve, position 76 (index à partir de 0), copie `module2_general::module2_docx_063`.

### Q64 — Adverbes en « -amment » / « -emment »

Clé : `module2_docx_064`. Cours : **Adverbes en « -amment » / « -emment »** (`adverbes-en-amment-emment.docx`).

**Texte source**

> Comme dit précédemment, je me rendrai à Moscou pour signer ce contrat.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. L’adjectif « précédent » s’écrit avec un « e » : l’adverbe « précédemment » également !

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Comme dit précédemment, je me rendrai à Moscou pour signer ce contrat.

**Explication proposée**

> La phrase est correcte.
> 
> Précédent se termine par ent. L’adverbe correspondant se termine par emment : précédemment, avec e et deux m.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 11 (index à partir de 0), copie `module2_test2::module2_docx_064`.
- **Test général : réviser le module 2** : réserve, position 77 (index à partir de 0), copie `module2_general::module2_docx_064`.

### Q65 — « d’en » / « dans »

Clé : `module2_docx_065`. Cours : **« d’en » / « dans »** (`d-en-dans.docx`).

**Texte source**

> D’après ce que tu me décris, le costume que tu souhaites devrait coûter d’en les huit cents euros.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « D’après ce que tu me décris, le costume que tu souhaites devrait coûter dans les huit cents euros. » Il s’agit d’une estimation : « dans » a le sens ici de « environ ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> D’après ce que tu me décris, le costume que tu souhaites devrait coûter dans les huit cents euros.

**Explication proposée**

> Correction : « D’après ce que tu me décris, le costume que tu souhaites devrait coûter dans les huit cents euros. »
> 
> Dans exprime ici une approximation : dans les huit cents euros signifie environ huit cents euros. D’en ne convient pas. Huit cents est correctement accordé.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 12 (index à partir de 0), copie `module2_general::module2_docx_065`.

**Autres règles présentes** : Les nombres.


### Q66 — « d’en » / « dans »

Clé : `module2_docx_066`. Cours : **« d’en » / « dans »** (`d-en-dans.docx`).

**Texte source**

> Dans l’Égypte antique, un scribe était une personne lettrée qui remplissait plusieurs fonctions.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Dans » introduit un complément circonstanciel de temps (« à l’époque de l’Égypte antique »).

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Dans l’Égypte antique, un scribe était une personne lettrée qui remplissait plusieurs fonctions.

**Explication proposée**

> La phrase est correcte.
> 
> Dans introduit ici le cadre de la situation, l’Égypte antique. Il s’agit de la préposition dans, et non de la succession de et en.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 81 (index à partir de 0), copie `module2_general::module2_docx_066`.

### Q67 — « pause » / « pose »

Clé : `module2_docx_067`. Cours : **« pause » / « pose »** (`pause-pose.docx`).

**Texte source**

> Quel est le temps de pose pour cet enduit ?

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Voici le sens de la phrase : une fois l’enduit posé, mis, combien de temps doit-on attendre ?

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Quel est le temps de pose pour cet enduit ?

**Explication proposée**

> La phrase est correcte.
> 
> Pose est le nom lié à poser un produit ou un matériau. Dans temps de pose, on écrit o ; pause désignerait un arrêt de l’activité.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 86 (index à partir de 0), copie `module2_general::module2_docx_067`.

### Q68 — « pause » / « pose »

Clé : `module2_docx_068`. Cours : **« pause » / « pose »** (`pause-pose.docx`).

**Texte source**

> À cause d’un brouillard très dense, le pilote a hésité à se poser.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Le « verbe » a le sens d’« atterrir » (se poser sur le sol).

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> À cause d’un brouillard très dense, le pilote a hésité à se poser.

**Explication proposée**

> La phrase est correcte.
> 
> Se poser signifie ici atterrir. Il s’agit du verbe poser, écrit avec o.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 87 (index à partir de 0), copie `module2_general::module2_docx_068`.

### Q69 — « voilà »

Clé : `module2_docx_069`. Cours : **« voilà »** (`voila.docx`).

**Texte source**

> Voici vos affaires et voilà les miennes.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Voici vos affaires et voilà les miennes.

**Explication proposée**

> La phrase est correcte.
> 
> Voilà s’écrit avec un accent grave sur le a. Le rapprochement avec voici ne supprime pas cet accent.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 14 (index à partir de 0), copie `module2_general::module2_docx_069`.

### Q70 — « voilà »

Clé : `module2_docx_070`. Cours : **« voilà »** (`voila.docx`).

**Texte source**

> Voila une bonne chose de faite !

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact !« Voilà une bonne chose de faite ! » « Voilà » prend toujours un accent sur le « a ». Et voilà !

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Voilà une bonne chose de faite !

**Explication proposée**

> Correction : « Voilà une bonne chose de faite ! »
> 
> Voilà prend toujours un accent grave sur le a, même en début de phrase : Voilà une bonne chose de faite !

**Affectations**

- **Test général : réviser le module 2** : réserve, position 91 (index à partir de 0), copie `module2_general::module2_docx_070`.

**Variante proche** : Q30 (texte différent).


### Q71 — « près de » / « prêt à »

Clé : `module2_docx_071`. Cours : **« près de » / « prêt à »** (`pret-de-pret-a.docx`).

**Texte source**

> Pratique ton déjeuner : il est prêt à cuire.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Prêt » est suivi de la préposition « à » :  il s’agit de l’adjectif accordé avec le nom « déjeuner ».

**Texte proposé après retouche validée**

> Pratique, ton déjeuner : il est prêt à cuire.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Pratique, ton déjeuner : il est prêt à cuire.

**Explication proposée**

> La phrase est correcte.
> 
> Prêt à signifie que le déjeuner peut être cuit. Prêt est un adjectif accordé avec déjeuner, masculin singulier ; la virgule clarifie le début détaché de la phrase.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 93 (index à partir de 0), copie `module2_general::module2_docx_071`.

### Q72 — « près de » / « prêt à »

Clé : `module2_docx_072`. Cours : **« près de » / « prêt à »** (`pret-de-pret-a.docx`).

**Texte source**

> Gênée par son orthographe, cette étudiante ne sera jamais prête à rédiger ce texte à temps.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Indice : « prête » est suivi de la préposition « à ». C’est un adjectif qui s’accorde avec le nom féminin singulier « étudiante ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Gênée par son orthographe, cette étudiante ne sera jamais prête à rédiger ce texte à temps.

**Explication proposée**

> La phrase est correcte.
> 
> Prête est l’adjectif féminin singulier, accordé avec cette étudiante. Il est suivi de à et signifie disposée ou préparée à rédiger.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 94 (index à partir de 0), copie `module2_general::module2_docx_072`.

### Q73 — « tache » / « tâche »

Clé : `module2_docx_073`. Cours : **« tache » / « tâche »** (`tache.docx`).

**Texte source**

> Ces tâches de cambouis résistent à chaque lavage.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Ces taches de cambouis résistent à chaque lavage. » On pourrait dire « ces traces de cambouis », donc pas d’accent.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ces taches de cambouis résistent à chaque lavage.

**Explication proposée**

> Correction : « Ces taches de cambouis résistent à chaque lavage. »
> 
> Le cambouis laisse des traces : ce sont des taches, sans accent circonflexe. Des tâches seraient des travaux à accomplir.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 98 (index à partir de 0), copie `module2_general::module2_docx_073`.

### Q74 — « tache » / « tâche »

Clé : `module2_docx_074`. Cours : **« tache » / « tâche »** (`tache.docx`).

**Texte source**

> Tâchez de vous tenir prêts quand il arrivera pour la visite des lieux.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Ici, « tâcher » a le sens de « faire l’effort ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Tâchez de vous tenir prêts quand il arrivera pour la visite des lieux.

**Explication proposée**

> La phrase est correcte.
> 
> Tâcher signifie faire l’effort de : Tâchez porte un accent circonflexe. Prêts est également correct, au masculin pluriel pour les personnes interpellées.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 17 (index à partir de 0), copie `module2_general::module2_docx_074`.

**Autres règles présentes** : « près de » / « prêt à ».


### Q75 — « à l’attention » / « à l’intention »

Clé : `module2_docx_075`. Cours : **« à l’attention » / « à l’intention »** (`a-l-attention-a-l-intention.docx`).

**Texte source**

> Cette lettre envoyée à l’intention de la DRH n’était peut-être pas très judicieuse.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Cette lettre envoyée à l’attention de la DRH n’était peut-être pas très judicieuse. » La lettre a été envoyée dans un cadre strictement professionnel.

**En attente — aucune affectation**

Le contexte professionnel ne suffit pas à rendre « à l’intention » incorrect ; préciser la destination et le sens.

**Catégorie documentaire suggérée** : `non arrêté` ; non importable.


### Q76 — « à l’attention » / « à l’intention »

Clé : `module2_docx_076`. Cours : **« à l’attention » / « à l’intention »** (`a-l-attention-a-l-intention.docx`).

**Texte source**

> Ils ont donné une messe à l’intention des défunts.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « L’intention » souligne le sentiment sous-jacent de compassion et le caractère intimiste des relations.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ils ont donné une messe à l’intention des défunts.

**Explication proposée**

> La phrase est correcte.
> 
> La messe est célébrée pour les défunts, à leur intention. L’expression indique le bénéficiaire ou la personne pour qui une action est accomplie ; elle ne suppose pas nécessairement une relation intime.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 103 (index à partir de 0), copie `module2_general::module2_docx_076`.

### Q77 — « quasi »

Clé : `module2_docx_077`. Cours : **« quasi »** (`quasi.docx`).

**Texte source**

> Après tout ce que tu as fait pour elle, je suis quasi sûr qu’elle te rendra la pareille.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Après tout ce que tu as fait pour elle, je suis quasi sûr qu’elle te rendra la pareille.

**Explication proposée**

> La phrase est correcte.
> 
> Devant l’adjectif sûr, quasi est un adverbe séparé par une espace, sans trait d’union.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 105 (index à partir de 0), copie `module2_general::module2_docx_077`.

### Q78 — « quasi »

Clé : `module2_docx_078`. Cours : **« quasi »** (`quasi.docx`).

**Texte source**

> Depuis l’ouverture de cette zone commerciale, les rues du centre-ville sont quasi-désertes.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Depuis l’ouverture de cette zone commerciale, les rues du centre-ville sont quasi désertes. » « Quasi » est suivi de l’adjectif « désertes » : il n’y a pas de trait d’union entre les deux mots.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Depuis l’ouverture de cette zone commerciale, les rues du centre-ville sont quasi désertes.

**Explication proposée**

> Correction : « Depuis l’ouverture de cette zone commerciale, les rues du centre-ville sont quasi désertes. »
> 
> Désertes est un adjectif : quasi doit en être séparé par une espace, sans trait d’union. On écrit quasi désertes.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 20 (index à partir de 0), copie `module2_general::module2_docx_078`.

### Q79 — « tout » (2)

Clé : `module2_docx_079`. Cours : **« tout » (2)** (`tout-2.docx`).

**Texte source**

> Les danseuses sont tout excitées à l’idée de participer à une comédie musicale.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Le participe adjectival « excitées » commence par une voyelle : « tout » reste invariable.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les danseuses sont tout excitées à l’idée de participer à une comédie musicale.

**Explication proposée**

> La phrase est correcte.
> 
> Tout adverbe reste invariable devant un adjectif féminin commençant par une voyelle : tout excitées. Excitées s’accorde avec danseuses.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 108 (index à partir de 0), copie `module2_general::module2_docx_079`.

### Q80 — « tout » (2)

Clé : `module2_docx_080`. Cours : **« tout » (2)** (`tout-2.docx`).

**Texte source**

> Toutes habillées de bleu, les mannequins ont défilé devant un parterre de photographes.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Tout habillées de bleu, les mannequins ont défilé devant un parterre de photographes. » Puisque « habillées » commence par un « h » muet, « tout » ne s’accorde pas et la liaison se fait à l’oral.

**En attente — aucune affectation**

Distinguer « entièrement habillées » et « toutes les personnes habillées » ; la lecture attendue doit être explicitée.

**Catégorie documentaire suggérée** : `non arrêté` ; non importable.


**Autres règles présentes** : Tout pronom ou adverbe.


### Q81 — Les jours de la semaine

Clé : `module2_docx_081`. Cours : **Les jours de la semaine** (`jours-de-la-semaine.docx`).

**Texte source**

> Cette semaine, je finirai exceptionnellement à 17 heures les mardi et vendredi.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Il n’y a qu’un mardi et qu’un vendredi lors d'une semaine. Pensez à lire la phrase dans son ensemble.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Cette semaine, je finirai exceptionnellement à 17 heures les mardi et vendredi.

**Explication proposée**

> La phrase est correcte.
> 
> La phrase vise une semaine déterminée, avec un mardi et un vendredi. Les mardi et vendredi peut se comprendre comme le mardi et le vendredi ; ce singulier est admis.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 1 (index à partir de 0), copie `module2_test1::module2_docx_081`.
- **Test général : réviser le module 2** : réserve, position 26 (index à partir de 0), copie `module2_general::module2_docx_081`.

### Q82 — Les jours de la semaine

Clé : `module2_docx_082`. Cours : **Les jours de la semaine** (`jours-de-la-semaine.docx`).

**Texte source**

> Ces deux derniers mercredis après-midi, j’ai fait du shopping avec ma fille aînée.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. La phrase évoque deux mercredis : le pluriel est donc de mise. « Après-midi » est un mot invariable.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ces deux derniers mercredis après-midi, j’ai fait du shopping avec ma fille aînée.

**Explication proposée**

> La phrase est correcte.
> 
> Deux mercredis sont concernés : mercredis prend un s. Après-midi est laissé invariable dans la graphie traditionnelle ; la graphie après-midis est également admise selon les rectifications de 1990.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 14 (index à partir de 0), copie `module2_test1::module2_docx_082`.
- **Test général : réviser le module 2** : réserve, position 27 (index à partir de 0), copie `module2_general::module2_docx_082`.

### Q83 — « lorsque » / « lorsqu’ »

Clé : `module2_docx_083`. Cours : **« lorsque » / « lorsqu’ »** (`lorsque.docx`).

**Texte source**

> On évite de parler la bouche pleine lorsqu’on mange…

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Placé devant « on », « lorsque » perd le « e » final au profit d’une apostrophe.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> On évite de parler la bouche pleine lorsqu’on mange…

**Explication proposée**

> La phrase est correcte.
> 
> Lorsque s’élide devant on : on écrit lorsqu’on, avec une apostrophe à la place du e final.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 17 (index à partir de 0), copie `module2_test1::module2_docx_083`.
- **Test général : réviser le module 2** : réserve, position 30 (index à partir de 0), copie `module2_general::module2_docx_083`.

### Q84 — « lorsque » / « lorsqu’ »

Clé : `module2_docx_084`. Cours : **« lorsque » / « lorsqu’ »** (`lorsque.docx`).

**Texte source**

> Vous pourrez leur poser toutes vos questions lorsque elles seront revenues de leur voyage.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Vous pourrez leur poser toutes vos questions lorsqu’elles seront revenues de leur voyage. » Devant « elle » (ou « elles »), « lorsque » s’élide.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Vous pourrez leur poser toutes vos questions lorsqu’elles seront revenues de leur voyage.

**Explication proposée**

> Correction : « Vous pourrez leur poser toutes vos questions lorsqu’elles seront revenues de leur voyage. »
> 
> Lorsque s’élide devant elles : il faut écrire lorsqu’elles, avec une apostrophe à la place du e final.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 3 (index à partir de 0), copie `module2_test1::module2_docx_084`.
- **Test général : réviser le module 2** : réserve, position 31 (index à partir de 0), copie `module2_general::module2_docx_084`.

### Q85 — Les nombres

Clé : `module2_docx_085`. Cours : **Les nombres** (`les-nombres.docx`).

**Texte source**

> Cette paire de chaussures de luxe vaut trois-cent-quatre-vingt-cinq euros.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Cent » et « quatre-vingt » sont tous deux suivis d’un nombre et ne s’accordent donc pas.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Cette paire de chaussures de luxe vaut trois-cent-quatre-vingt-cinq euros.

**Explication proposée**

> La phrase est correcte.
> 
> Cent et vingt sont suivis d’autres nombres : ils restent invariables dans trois-cent-quatre-vingt-cinq. Les traits d’union généralisés sont admis par les rectifications de 1990.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 5 (index à partir de 0), copie `module2_test1::module2_docx_085`.
- **Test général : réviser le module 2** : réserve, position 34 (index à partir de 0), copie `module2_general::module2_docx_085`.

**Autres règles présentes** : Accord de cent ; Accord de vingt ; Traits d’union des numéraux.


### Q86 — Les nombres

Clé : `module2_docx_086`. Cours : **Les nombres** (`les-nombres.docx`).

**Texte source**

> Elle a mille vêtements dans sa garde-robe et elle se plaint toujours de n’avoir rien à se mettre.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Mille » exprimant une quantité demeure invariable.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Elle a mille vêtements dans sa garde-robe et elle se plaint toujours de n’avoir rien à se mettre.

**Explication proposée**

> La phrase est correcte.
> 
> Mille exprimant un nombre reste invariable : mille vêtements. Il ne faut pas le confondre avec le nom mille, unité de distance.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 20 (index à partir de 0), copie `module2_test1::module2_docx_086`.
- **Test général : réviser le module 2** : réserve, position 35 (index à partir de 0), copie `module2_general::module2_docx_086`.

### Q87 — « demi » / « à demi » / « et demi »

Clé : `module2_docx_087`. Cours : **« demi » / « à demi » / « et demi »** (`demi-a-demi.docx`).

**Texte source**

> Dans la mythologie grecque, les héros sont souvent des demi dieux.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Dans la mythologie grecque, les héros sont souvent des demi-dieux. » « Demi » placé devant un nom (ou un adjectif) est suivi d’un trait d’union.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Dans la mythologie grecque, les héros sont souvent des demi-dieux.

**Explication proposée**

> Correction : « Dans la mythologie grecque, les héros sont souvent des demi-dieux. »
> 
> Demi placé avant un nom est invariable et relié par un trait d’union : des demi-dieux. Dieux porte le pluriel.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 22 (index à partir de 0), copie `module2_test1::module2_docx_087`.
- **Test général : réviser le module 2** : réserve, position 39 (index à partir de 0), copie `module2_general::module2_docx_087`.

### Q88 — « demi » / « à demi » / « et demi »

Clé : `module2_docx_088`. Cours : **« demi » / « à demi » / « et demi »** (`demi-a-demi.docx`).

**Texte source**

> À demi-réveillée, ma fille est partie à l’école avec une chaussette bleue et l’autre violette.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « À demi réveillée, ma fille est partie à l’école avec une chaussette bleue et l’autre violette. » « À demi » précède l’adjectif « réveillée » : le trait d’union est incorrect.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> À demi réveillée, ma fille est partie à l’école avec une chaussette bleue et l’autre violette.

**Explication proposée**

> Correction : « À demi réveillée, ma fille est partie à l’école avec une chaussette bleue et l’autre violette. »
> 
> À demi placé devant un adjectif s’écrit sans trait d’union : À demi réveillée. La locution à demi est invariable.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 23 (index à partir de 0), copie `module2_test1::module2_docx_088`.
- **Test général : réviser le module 2** : réserve, position 40 (index à partir de 0), copie `module2_general::module2_docx_088`.

**Variante proche** : Q47 (texte différent).


### Q89 — « plutôt » / « plus tôt »

Clé : `module2_docx_089`. Cours : **« plutôt » / « plus tôt »** (`plutot.docx`).

**Texte source**

> Ne pensez-vous pas plutôt que cela relève de ses compétences ?

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. On rectifie ainsi ce qui est pensé de prime abord.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ne pensez-vous pas plutôt que cela relève de ses compétences ?

**Explication proposée**

> La phrase est correcte.
> 
> Plutôt introduit ici une autre appréciation de la situation. Il s’écrit en un mot ; il n’exprime pas un moment plus précoce.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : réserve, position 30 (index à partir de 0), copie `module2_test1::module2_docx_089`.
- **Test général : réviser le module 2** : réserve, position 47 (index à partir de 0), copie `module2_general::module2_docx_089`.

### Q90 — « plutôt » / « plus tôt »

Clé : `module2_docx_090`. Cours : **« plutôt » / « plus tôt »** (`plutot.docx`).

**Texte source**

> Dans quelle affaire sommes-nous maintenant ! Ils auraient mieux fait de nous dire la vérité plus tôt.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Plus tard » serait possible puisque le temps est exprimé.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Dans quelle affaire sommes-nous maintenant ! Ils auraient mieux fait de nous dire la vérité plus tôt.

**Explication proposée**

> La phrase est correcte.
> 
> Plus tôt situe l’annonce de la vérité dans le temps. On pourrait opposer plus tôt à plus tard : il faut deux mots.

**Affectations**

- **Test : écrire les nombres et les expressions courantes** : initiale, position 11 (index à partir de 0), copie `module2_test1::module2_docx_090`.
- **Test général : réviser le module 2** : réserve, position 48 (index à partir de 0), copie `module2_general::module2_docx_090`.

### Q91 — « on » / « on n’ »

Clé : `module2_docx_091`. Cours : **« on » / « on n’ »** (`on.docx`).

**Texte source**

> Il faudrait qu’on appelle le club pour avoir davantage de renseignements.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Il s’agit d’une phrase affirmative.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Il faudrait qu’on appelle le club pour avoir davantage de renseignements.

**Explication proposée**

> La phrase est correcte.
> 
> On appelle ne comporte aucune négation. Il n’y a donc pas de n’ à ajouter, même si la liaison peut donner cette impression à l’oral.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 14 (index à partir de 0), copie `module2_test2::module2_docx_091`.
- **Test général : réviser le module 2** : réserve, position 52 (index à partir de 0), copie `module2_general::module2_docx_091`.

### Q92 — « on » / « on n’ »

Clé : `module2_docx_092`. Cours : **« on » / « on n’ »** (`on.docx`).

**Texte source**

> Il faut préparer un nouveau dossier alors qu’on n’a pas encore bouclé le précédent.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Pas » est le second terme de la négation et « n’ » le précède obligatoirement.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Il faut préparer un nouveau dossier alors qu’on n’a pas encore bouclé le précédent.

**Explication proposée**

> La phrase est correcte.
> 
> La négation ne… pas est présente : on n’a pas encore bouclé. Ne s’élide devant a, d’où n’a.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 15 (index à partir de 0), copie `module2_test2::module2_docx_092`.
- **Test général : réviser le module 2** : réserve, position 53 (index à partir de 0), copie `module2_general::module2_docx_092`.

### Q93 — « exigence » / « exigeant »

Clé : `module2_docx_093`. Cours : **« exigence » / « exigeant »** (`exigeant-exigence.docx`).

**Texte source**

> En exigeant que tes enfants fassent leur chambre, tu auras moins de ménage à faire à la maison.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « En » précède « exigeant » : il s’agit du gérondif avec la terminaison « -ant ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> En exigeant que tes enfants fassent leur chambre, tu auras moins de ménage à faire à la maison.

**Explication proposée**

> La phrase est correcte.
> 
> En exigeant est un gérondif formé de en et du participe présent exigeant. Il est invariable et s’écrit avec eant.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 18 (index à partir de 0), copie `module2_test2::module2_docx_093`.
- **Test général : réviser le module 2** : initiale, position 7 (index à partir de 0), copie `module2_general::module2_docx_093`.

### Q94 — « exigence » / « exigeant »

Clé : `module2_docx_094`. Cours : **« exigence » / « exigeant »** (`exigeant-exigence.docx`).

**Texte source**

> Par amitié, Chloé accepte toutes les exigences de sa copine Jeanne.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Le nom « demandes » à la place du nom  « exigences » pourrait convenir.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Par amitié, Chloé accepte toutes les exigences de sa copine Jeanne.

**Explication proposée**

> La phrase est correcte.
> 
> Exigences est le nom que l’on pourrait remplacer par demandes. Il s’écrit avec ence et prend le pluriel après toutes les.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 19 (index à partir de 0), copie `module2_test2::module2_docx_094`.
- **Test général : réviser le module 2** : réserve, position 58 (index à partir de 0), copie `module2_general::module2_docx_094`.

### Q95 — « employé » / « employer »

Clé : `module2_docx_095`. Cours : **« employé » / « employer »** (`employe-employer.docx`).

**Texte source**

> Pour employer cette jeune fille, tu dois d’abord vérifier ses compétences.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Un verbe à l’infinitif comme « prendre » pourrait remplacer « employer ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Pour employer cette jeune fille, tu dois d’abord vérifier ses compétences.

**Explication proposée**

> La phrase est correcte.
> 
> Après pour, le verbe est ici à l’infinitif : employer. Le remplacement par recruter confirme la terminaison er.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 22 (index à partir de 0), copie `module2_test2::module2_docx_095`.
- **Test général : réviser le module 2** : réserve, position 62 (index à partir de 0), copie `module2_general::module2_docx_095`.

### Q96 — « employé » / « employer »

Clé : `module2_docx_096`. Cours : **« employé » / « employer »** (`employe-employer.docx`).

**Texte source**

> Ces employés sont toutes des secrétaires.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Ces employées sont toutes des secrétaires. » Attention ! Si elles sont « toutes secrétaires », ces « employées » sont des femmes ! Le nom « employées » s'écrit donc au féminin pluriel.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Ces employées sont toutes des secrétaires.

**Explication proposée**

> Correction : « Ces employées sont toutes des secrétaires. »
> 
> Employés et toutes ne concordent pas en genre. En conservant toutes, on écrit employées pour désigner des femmes. Si l’on désignait un groupe masculin ou mixte, une autre correction possible serait tous à la place de toutes. La phrase présente une seule incohérence d’accord.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 23 (index à partir de 0), copie `module2_test2::module2_docx_096`.
- **Test général : réviser le module 2** : réserve, position 63 (index à partir de 0), copie `module2_general::module2_docx_096`.

**Autres règles présentes** : Accord du pronom toutes.


### Q97 — « faites » / « faîte »

Clé : `module2_docx_097`. Cours : **« faites » / « faîte »** (`faites-faite.docx`).

**Texte source**

> Au fait de sa gloire, John Fitzgerald Kennedy fut assassiné en 1963, à Dallas.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Au faîte de sa gloire, John Fitzgerald Kennedy fut assassiné en 1963, à Dallas. » Bien qu’elle ait ici un sens figuré, l’expression « au faîte de sa gloire » signifie « au sommet de sa gloire ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Au faîte de sa gloire, John Fitzgerald Kennedy fut assassiné en 1963, à Dallas.

**Explication proposée**

> Correction : « Au faîte de sa gloire, John Fitzgerald Kennedy fut assassiné en 1963, à Dallas. »
> 
> L’expression au faîte de sa gloire signifie au sommet de sa gloire. Faîte est un nom qui prend un accent circonflexe et un e final ; il ne faut pas le confondre avec fait.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : initiale, position 7 (index à partir de 0), copie `module2_test2::module2_docx_097`.
- **Test général : réviser le module 2** : réserve, position 67 (index à partir de 0), copie `module2_general::module2_docx_097`.

### Q98 — « faites » / « faîte »

Clé : `module2_docx_098`. Cours : **« faites » / « faîte »** (`faites-faite.docx`).

**Texte source**

> Soyez plus attentifs à ce que vous faisez.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Soyez plus attentifs à ce que vous faites. »

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Soyez plus attentifs à ce que vous faites.

**Explication proposée**

> Correction : « Soyez plus attentifs à ce que vous faites. »
> 
> Au présent, on écrit vous faites. La forme faisez est incorrecte.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 27 (index à partir de 0), copie `module2_test2::module2_docx_098`.
- **Test général : réviser le module 2** : réserve, position 68 (index à partir de 0), copie `module2_general::module2_docx_098`.

**Variante proche** : Q20 (texte différent).


### Q99 — « presque » / « presqu’ »

Clé : `module2_docx_099`. Cours : **« presque » / « presqu’ »** (`presque.docx`).

**Texte source**

> Il faut envoyer ce courrier au plus vite : la date limite est presqu’atteinte.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Il faut envoyer ce courrier au plus vite : la date limite est presque atteinte. »

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Il faut envoyer ce courrier au plus vite : la date limite est presque atteinte.

**Explication proposée**

> Correction : « Il faut envoyer ce courrier au plus vite : la date limite est presque atteinte. »
> 
> Presque conserve son e devant une voyelle : presque atteinte. L’élision de presqu’île ne s’étend pas à cet emploi.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 30 (index à partir de 0), copie `module2_test2::module2_docx_099`.
- **Test général : réviser le module 2** : réserve, position 73 (index à partir de 0), copie `module2_general::module2_docx_099`.

**Variante proche** : Q21 (texte différent).


### Q100 — « presque » / « presqu’ »

Clé : `module2_docx_100`. Cours : **« presque » / « presqu’ »** (`presque.docx`).

**Texte source**

> Il serait presque intéressant de le leur dire afin d’observer leurs réactions.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Il serait presque intéressant de le leur dire afin d’observer leurs réactions.

**Explication proposée**

> La phrase est correcte.
> 
> Presque reste écrit en entier devant intéressant, malgré la voyelle initiale. L’absence d’élision est correcte.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 31 (index à partir de 0), copie `module2_test2::module2_docx_100`.
- **Test général : réviser le module 2** : initiale, position 10 (index à partir de 0), copie `module2_general::module2_docx_100`.

### Q101 — Adverbes en « -amment » / « -emment »

Clé : `module2_docx_101`. Cours : **Adverbes en « -amment » / « -emment »** (`adverbes-en-amment-emment.docx`).

**Texte source**

> Suite à leur discussion, elle a violamment claqué la porte.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Suite à leur discussion, elle a violemment claqué la porte. » L’adjectif « violent », orthographié avec un « e », permet la construction de l’adverbe « violemment ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Suite à leur discussion, elle a violemment claqué la porte.

**Explication proposée**

> Correction : « Suite à leur discussion, elle a violemment claqué la porte. »
> 
> L’adjectif violent se termine par ent. L’adverbe correspondant s’écrit violemment, avec e et deux m.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 34 (index à partir de 0), copie `module2_test2::module2_docx_101`.
- **Test général : réviser le module 2** : initiale, position 11 (index à partir de 0), copie `module2_general::module2_docx_101`.

### Q102 — Adverbes en « -amment » / « -emment »

Clé : `module2_docx_102`. Cours : **Adverbes en « -amment » / « -emment »** (`adverbes-en-amment-emment.docx`).

**Texte source**

> Elle a supporté patiemment tous ses caprices.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Elle a supporté patiemment tous ses caprices.

**Explication proposée**

> La phrase est correcte.
> 
> Patient se termine par ent. L’adverbe se termine donc par emment : patiemment, avec e et deux m.

**Affectations**

- **Test : distinguer les formes et les terminaisons** : réserve, position 35 (index à partir de 0), copie `module2_test2::module2_docx_102`.
- **Test général : réviser le module 2** : réserve, position 78 (index à partir de 0), copie `module2_general::module2_docx_102`.

### Q103 — « d’en » / « dans »

Clé : `module2_docx_103`. Cours : **« d’en » / « dans »** (`d-en-dans.docx`).

**Texte source**

> J’ai encore oublié mon manteau dans la voiture.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Dans la voiture » indique un lieu et il n'est pas suivi d'un infinitif.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> J’ai encore oublié mon manteau dans la voiture.

**Explication proposée**

> La phrase est correcte.
> 
> Dans introduit le lieu où se trouve le manteau : dans la voiture. D’en est une autre construction et ne convient pas ici.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 82 (index à partir de 0), copie `module2_general::module2_docx_103`.

### Q104 — « d’en » / « dans »

Clé : `module2_docx_104`. Cours : **« d’en » / « dans »** (`d-en-dans.docx`).

**Texte source**

> Veuillez patienter ici : monsieur Laurent va vous recevoir dans cinq minutes.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Dans cinq minutes » indique ici la durée.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Veuillez patienter ici : monsieur Laurent va vous recevoir dans cinq minutes.

**Explication proposée**

> La phrase est correcte.
> 
> Dans cinq minutes annonce un délai avant le début de la réception. Il ne s’agit pas de la durée de l’entretien. La préposition dans est correcte.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 83 (index à partir de 0), copie `module2_general::module2_docx_104`.

### Q105 — « pause » / « pose »

Clé : `module2_docx_105`. Cours : **« pause » / « pose »** (`pause-pose.docx`).

**Texte source**

> À la pause-café, Romain nous dira s’il a aimé le film de science-fiction qu’il a vu hier au cinéma.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. La « pause-café » indique une coupure entre deux temps de travail.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> À la pause-café, Romain nous dira s’il a aimé le film de science-fiction qu’il a vu hier au cinéma.

**Explication proposée**

> La phrase est correcte.
> 
> Une pause-café est une interruption consacrée au café. Pause s’écrit avec au ; le trait d’union du nom composé est correct.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 13 (index à partir de 0), copie `module2_general::module2_docx_105`.

### Q106 — « pause » / « pose »

Clé : `module2_docx_106`. Cours : **« pause » / « pose »** (`pause-pose.docx`).

**Texte source**

> Au lieu de toujours poser des questions, trouve donc des réponses !

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Poser une question », c’est aussi « exposer sa question à autrui ». Ces deux verbes de la même famille s’écrivent avec un « o ». Connaissez-vous d’autres mots de cette même famille ?...

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Au lieu de toujours poser des questions, trouve donc des réponses !

**Explication proposée**

> La phrase est correcte.
> 
> Poser une question s’écrit avec o, comme exposer une question. Il ne s’agit pas de faire une pause.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 88 (index à partir de 0), copie `module2_general::module2_docx_106`.

### Q107 — « près de » / « prêt à »

Clé : `module2_docx_107`. Cours : **« près de » / « prêt à »** (`pret-de-pret-a.docx`).

**Texte source**

> Mes amis sont prêts à t’aider pour ton déménagement si cela peut te rendre service.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. Ils sont « disposés » à t’aider. « Prêts », adjectif, s’accorde en genre et en nombre avec le nom « amis ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Mes amis sont prêts à t’aider pour ton déménagement si cela peut te rendre service.

**Explication proposée**

> La phrase est correcte.
> 
> Prêts signifie disposés. Cet adjectif s’accorde avec mes amis, masculin pluriel, et se construit ici avec à : prêts à t’aider.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 95 (index à partir de 0), copie `module2_general::module2_docx_107`.

### Q108 — « près de » / « prêt à »

Clé : `module2_docx_108`. Cours : **« près de » / « prêt à »** (`pret-de-pret-a.docx`).

**Texte source**

> L’avare est près de ses sous.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « L’avare est à côté de ses sous », c’est-à-dire qu’il y veille de très près et ne s’en éloigne pas. C’est la proximité (le lieu) qui est suggérée, même au sens figuré !

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> L’avare est près de ses sous.

**Explication proposée**

> La phrase est correcte.
> 
> Près de signifie proche de. Dans l’expression être près de ses sous, près reste invariable ; prêt à aurait un autre sens.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 16 (index à partir de 0), copie `module2_general::module2_docx_108`.

### Q109 — « tache » / « tâche »

Clé : `module2_docx_109`. Cours : **« tache » / « tâche »** (`tache.docx`).

**Texte source**

> « La boue cache un rubis, mais ne le tache pas. » (proverbe chinois)

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Tacher » a le sens de « salir » dans ce proverbe.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> « La boue cache un rubis, mais ne le tache pas. » (proverbe chinois)

**Explication proposée**

> La phrase est correcte.
> 
> Tacher signifie salir ou laisser une trace. Il ne prend pas d’accent circonflexe dans ne le tache pas.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 99 (index à partir de 0), copie `module2_general::module2_docx_109`.

### Q110 — « tache » / « tâche »

Clé : `module2_docx_110`. Cours : **« tache » / « tâche »** (`tache.docx`).

**Texte source**

> La plus grande tâche qu’il ait accomplie ? Ranger son bureau.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « La plus grande besogne qu’il ait accomplie ? »

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> La plus grande tâche qu’il ait accomplie ? Ranger son bureau.

**Explication proposée**

> La phrase est correcte.
> 
> Tâche désigne le travail ou la besogne accomplie. Le nom s’écrit avec un accent circonflexe.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 100 (index à partir de 0), copie `module2_general::module2_docx_110`.

### Q111 — « à l’attention » / « à l’intention »

Clé : `module2_docx_111`. Cours : **« à l’attention » / « à l’intention »** (`a-l-attention-a-l-intention.docx`).

**Texte source**

> Il a lu par erreur les mails à l’intention de Mme Lutz concernant les commandes de matériel.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Il a lu par erreur les mails à l’attention de Mme Lutz concernant les commandes de matériel. »

**En attente — aucune affectation**

« À l’intention » peut exprimer la destination ou le bénéfice ; le contexte du courrier doit être précisé.

**Catégorie documentaire suggérée** : `non arrêté` ; non importable.


### Q112 — « à l’attention » / « à l’intention »

Clé : `module2_docx_112`. Cours : **« à l’attention » / « à l’intention »** (`a-l-attention-a-l-intention.docx`).

**Texte source**

> Je crois que pour une demande écrite auprès du Premier ministre, il faut écrire « à l’intention de Monsieur le Premier ministre ».

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Je crois que pour une demande écrite auprès du Premier ministre, il faut écrire « à l’attention de Monsieur le Premier ministre. » Le sens de la phrase montre que l’expéditeur (trice) n’est pas un(e) intime du Premier ministre.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Je crois que pour une demande écrite auprès du Premier ministre, il faut écrire « à l’attention de Monsieur le Premier ministre ».

**Explication proposée**

> Correction : « Je crois que pour une demande écrite auprès du Premier ministre, il faut écrire « à l’attention de Monsieur le Premier ministre ». »
> 
> Pour la mention adressant une demande écrite à une personne afin qu’elle en prenne connaissance, on utilise à l’attention de. À l’intention de concerne une action faite pour quelqu’un ou en son honneur. Le critère n’est pas le degré d’intimité avec le destinataire.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 18 (index à partir de 0), copie `module2_general::module2_docx_112`.

### Q113 — « quasi »

Clé : `module2_docx_113`. Cours : **« quasi »** (`quasi.docx`).

**Texte source**

> Selon le testament, la quasi-totalité de l’héritage lui revient.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Selon le testament, la quasi-totalité de l’héritage lui revient.

**Explication proposée**

> La phrase est correcte.
> 
> Totalité est un nom : quasi s’y rattache par un trait d’union, dans quasi-totalité.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 106 (index à partir de 0), copie `module2_general::module2_docx_113`.

### Q114 — « quasi »

Clé : `module2_docx_114`. Cours : **« quasi »** (`quasi.docx`).

**Texte source**

> Les travaux du magasin sont quasi-finis ; il rouvrira après-demain.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! Les travaux du magasin sont quasi finis ; il rouvrira après-demain. » Il n’y a pas de trait d’union entre « quasi » et l’adjectif « finis ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Les travaux du magasin sont quasi finis ; il rouvrira après-demain.

**Explication proposée**

> Correction : « Les travaux du magasin sont quasi finis ; il rouvrira après-demain. »
> 
> Finis est un participe employé comme adjectif. Quasi en est séparé par une espace : quasi finis, sans trait d’union.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 107 (index à partir de 0), copie `module2_general::module2_docx_114`.

### Q115 — « tout » (2)

Clé : `module2_docx_115`. Cours : **« tout » (2)** (`tout-2.docx`).

**Texte source**

> Elle est toute heureuse depuis qu’elle l’a rencontré.

**Réponses source** : 0 erreur, faux ; 1 erreur, vrai.

**Explication source**

> C'est exact ! « Elle est tout heureuse depuis qu’elle l’a rencontré. » « Heureuse » commence par un « h » muet, c’est-à-dire qui ne se prononce pas à l’oral. « Tout » reste donc invariable et la liaison se fait à l’oral.

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=true` ; **bonne réponse** : « 1 erreur ». Mode unique.

**Phrase corrigée de référence**

> Elle est tout heureuse depuis qu’elle l’a rencontré.

**Explication proposée**

> Correction : « Elle est tout heureuse depuis qu’elle l’a rencontré. »
> 
> Heureuse commence par un h muet, qui permet l’élision et la liaison. Devant cet adjectif féminin, tout adverbe reste invariable : tout heureuse. Un h aspiré se comporterait différemment.

**Affectations**

- **Test général : réviser le module 2** : initiale, position 23 (index à partir de 0), copie `module2_general::module2_docx_115`.

### Q116 — « tout » (2)

Clé : `module2_docx_116`. Cours : **« tout » (2)** (`tout-2.docx`).

**Texte source**

> Depuis qu’ils l’ont apprise, ils sont tout envahis par cette nouvelle.

**Réponses source** : 0 erreur, vrai ; 1 erreur, faux.

**Explication source**

> C'est exact ! Phrase correcte. « Envahis » commençant par une voyelle, « tout » reste invariable ; il a le sens ici de « tout à fait ».

**Texte proposé** : identique au texte source.

**Catégorie** : `contains_error=false` ; **bonne réponse** : « 0 erreur ». Mode unique.

**Phrase corrigée de référence**

> Depuis qu’ils l’ont apprise, ils sont tout envahis par cette nouvelle.

**Explication proposée**

> La phrase est correcte.
> 
> Tout est un adverbe signifiant entièrement. Devant le participe masculin pluriel envahis, il reste invariable ; envahis s’accorde avec ils.

**Affectations**

- **Test général : réviser le module 2** : réserve, position 109 (index à partir de 0), copie `module2_general::module2_docx_116`.

