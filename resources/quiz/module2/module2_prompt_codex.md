# Prompt Codex — import des quiz du module 2 d’Orthogram

Tu travailles dans le dépôt `StephaneBouret/orthogram`. Prépare l’outillage et la recette pour importer le dossier éditorial validé du module 2. Travaille sur l’état local constaté : commence par lire les instructions du dépôt et `git status`, préserve intégralement les changements déjà présents. Aucun commit, push, import sur la base de travail, accès Hostinger, activation réelle ou déploiement n’est autorisé.

## Documents fournis et décisions acquises

- `module2_quiz_manifest.json` : référence structurée, format `orthogram.quiz.editorial`, version 1.
- `module2_guide_relecture.md` : décisions, textes source et proposés, corrigés et limites pédagogiques.
- `valider_module2.py` et `module2_validation.json` : contrôles éditoriaux et simulation documentaire, sans validation applicative prétendue.

Stéphane a validé trois quiz de **12, 12 et 24 questions par tentative**, les jeux initiaux et réserves indiqués, les retouches de Q4/Q37/Q50/Q56/Q71 et les six mises en attente **Q2/Q44/Q45/Q75/Q80/Q111**. Ne redemande pas ces validations. Q45 est toujours en attente dans cette version, même si la lecture complémentaire du cours a confirmé son rattachement. Ne modifie ni contenu pédagogique, ni affectation, ni catégorie pour faire passer un contrôle technique. Signale toute incohérence nouvelle par un diff documenté.

Effectifs à respecter : **116 sources documentaires, 110 sources importables, 6 sources en attente ; 177 copies de questions dont 48 initiales et 129 réserves ; 354 propositions.** 67 sources ont deux copies (intermédiaire et général), 43 ont une copie (général seulement). Les trois nouvelles banques contiennent respectivement **31, 36 et 110 questions** ; elles n’en présentent que **12, 12 et 24**. Ne pas confondre ces décomptes.

## Inspection préalable et adaptation nécessaire

Une inspection en lecture seule a été effectuée au commit `b44724cd25be830a40d79036e4d9247ba5b23bf1`. Réinspecte le code local actuel ; le commit n’est pas une instruction de revenir en arrière. Les chemins ci-dessous ont été observés :

- `src/Entity/Quiz.php` : `renewalEnabled`, `errorQuestionQuota` et collection ordonnée des questions.
- `src/Entity/QuizQuestion.php` : `reserve`, `containsError`, `theme`, `position`, `title`, `text`, `explanation`, `multiple`.
- `src/Entity/QuizAnswer.php` : `content`, `correct`, `position`.
- `src/Entity/QuizReserveImport.php` et `migrations/Version20261025150000.php` : table `quiz_reserve_import`, clé composite `(quiz_id, source_key)`, référence unique à la question, `payload_hash` et `manifest_hash`. Une question supprimée laisse une trace avec `question_id=NULL`.
- `migrations/Version20261025120000.php` : champs de renouvellement et catégorie.
- `src/Services/QuizQuestionSelector.php`, `QuizConfigurationValidator.php`, `QuizEditorialService.php`, `QuizAttemptService.php` : sélection, validation, transactions et snapshots à préserver.
- `src/Services/ModuleOneReserveManifest.php`, `ModuleOneReserveImporter.php` ; `src/Command/QuizReserveCommand.php`, `QuizRenewalCommand.php` : chargement, comptes, clés et résolution **spécifiques au module 1**.
- `src/Services/QuizCommandDestination.php` : contrôles de destination existants, y compris une liste de noms de bases jetables spécifique à des chantiers précédents.

Les commandes **`app:quiz:module1-reserves` et `app:quiz:module1-renewal` ne sont pas des importeurs génériques**. Le chargeur module 1 impose notamment 47 sources, ses quatre volumes et des clés `module1_docx_NNN`. Ne lui passe pas ce manifeste et ne remplace pas ses constantes pour le détourner. Préserve son comportement et ses tests.

Construis un importeur de manifeste capable de créer de nouveaux quiz avec initiales et réserves, ou une adaptation propre dédiée au module 2 en réutilisant les services réellement génériques. Privilégie une conception réutilisable sans élargir ce chantier au moteur de sélection. Un nom tel que `app:quiz:import-manifest` serait **une nouvelle commande à implémenter**, pas une commande existante à exécuter immédiatement.

La traçabilité actuelle associe une source à un `quiz_id` ; elle ne fournit pas, à elle seule, une résolution persistante d’un nouveau `quiz_key`. Prévoir explicitement ce registre ou une solution persistante équivalente, avec unicité et protection concurrente. Réutiliser `QuizReserveImport` pour toutes les copies, y compris initiales, seulement si sa sémantique et les usages existants le permettent ; sinon ajouter un mécanisme distinct, justifié et testé. Ne prétendre à l’existence d’aucune table ou colonne supplémentaire avant sa création dans le code. Si une migration est nécessaire, la livrer et la tester uniquement sur base jetable, sans l’appliquer sur la base de travail.

## Contrat du manifeste

`sources` contient l’original, une clé stable `module2_docx_NNN`, le thème, la référence documentaire au cours, les décisions éditoriales, et une `candidate` pour chaque source importable. Les six sources en attente ont `candidate=null` et ne doivent produire aucune ligne de question ni réponse.

`quizzes` décrit les trois titres validés, leur clé éditoriale, leurs listes initiales/réserves, leur placement indicatif et les quotas par thème. Ce sont des **nouveaux titres validés**, pas des identités de quiz déjà présents en base.

`copies` porte l’affectation effective : clé `quiz_key::source_key`, rôle et position. Une même source peut produire deux questions distinctes dans deux quiz. Il est interdit de mutualiser une même ligne `QuizQuestion` entre ces quiz.

Champs persistables de chaque copie :

| Origine | Cible réelle |
| --- | --- |
| `quiz.title` | `Quiz.title` |
| `quiz.renewal_enabled=false` | `Quiz.renewalEnabled` à la création uniquement |
| `quiz.error_question_quota=null` | `Quiz.errorQuestionQuota` à la création uniquement |
| `source.candidate.title/text/explanation/theme/multiple/contains_error` | Propriétés correspondantes de `QuizQuestion` |
| `copy.role` | `reserve=false` pour initiale, `true` pour réserve |
| `copy.position` | `QuizQuestion.position` |
| `candidate.answers` | Deux `QuizAnswer` avec contenu, position et bonne réponse |

`corrected_text`, `error_count`, `correction_edits`, textes source, références aux cours, empreintes documentaires et notes de relecture sont **documentaires**. En particulier, ne crée pas une colonne `corrected_text` par supposition. La phrase corrigée complète est déjà incluse dans l’explication importable pour les questions erronées.

Chaque candidate possède exactement deux propositions : `0 erreur` et `1 erreur`, une seule bonne réponse, `multiple=false` et un `contains_error` booléen explicite. Valider la cohérence de ces champs ; ne pas inférer silencieusement la catégorie depuis le corrigé ou le texte.

Les positions sont uniques et commencent à zéro par quiz. Toutes les initiales précèdent les réserves. Respecter l’ordre exact livré : il détermine le premier passage et le départage ultérieur. Les réserves reprennent exactement le libellé de thème des initiales. Ne pas convertir librement les guillemets, espaces, apostrophes ou accents des thèmes et contenus.

L’empreinte `payload_sha256` utilise SHA-256 sur la sérialisation JSON UTF-8 canonique de : `title`, `text`, `explanation`, `theme`, `multiple`, `contains_error`, `answers`, `position`, `reserve`. Trier récursivement les clés des objets, conserver l’ordre des listes, sans espaces de formatage, sans fin de ligne ajoutée, sans échappement des caractères Unicode ni des barres obliques `/`. Se référer à `canonical` et `payload` du validateur Python. En PHP, tenir compte de `JSON_UNESCAPED_UNICODE` **et** `JSON_UNESCAPED_SLASHES` : le helper de hash module 1 ne doit pas être supposé compatible sans contrôle. L’empreinte du fichier manifeste est calculée sur ses octets exacts, fin de ligne comprise.

## Résolution et protection des données existantes

1. Ne jamais utiliser les numéros de questions du Word comme identifiants SQL. Laisser la base générer les identifiants.
2. Ne jamais résoudre un quiz uniquement par son titre et écraser ce qui correspond. S’il existe déjà un quiz non tracé qui paraît correspondre, produire un diff et refuser toute adoption implicite.
3. Résoudre durablement chaque `quiz_key` vers son identité créée par cet import. Un second import identique doit être un no-op, même dans un environnement où les identifiants SQL diffèrent du local.
4. Tracer **les 177 copies**, initiales comprises, avec clé source, identité quiz/question, empreinte du contenu et empreinte du manifeste. Une suppression, un déplacement, une modification de texte, de réponse, de thème ou de rôle doit être détectée.
5. Sur écart : rapport par quiz/source/champ, aucune mise à jour corrective automatique, aucune suppression/recréation, aucune écriture partielle sur les autres quiz. Pas d’option force qui écrase les historiques ou masque un conflit.
6. Ne pas réinitialiser à fixe un quiz déjà activé lors d’un réimport. La création est fixe ; les opérations ultérieures préservent son état. Signaler les différences de configuration. Ne pas remettre un quota existant à NULL automatiquement.
7. Aucun contenu, quiz, rattachement, tentative, snapshot, réponse apprenant, bilan, progression ou dictée préexistant ne doit être modifié par l’import. Les empreintes et rapports ne doivent pas exposer de secrets ou de données personnelles inutiles.

## Rattachement aux cours

Ce dossier n’invente **aucun identifiant, slug ou position de cours existant**. Les `course_reference` et `placement` sont éditoriaux ; les champs d’identité sont NULL.

Créer les trois quiz indépendamment des cours. Leur rattachement se fera ensuite dans EasyAdmin, selon les emplacements validés, après autorisation de l’import réel. Ne pas créer un cours, changer son titre, son slug, sa position ou son quiz automatiquement. Si un outil de liaison est proposé ultérieurement, il devra avoir sa propre prévisualisation sur des identités réellement résolues.

## Prévisualisation, transaction et idempotence

- La commande doit être sans écriture par défaut. Fournir un rapport lisible et JSON : destination, empreinte du manifeste, identités trouvées, créations prévues, sources en attente, compte initial/réserve par thème, divergences, prérequis de schéma.
- Ne pas lancer automatiquement de migration pendant une prévisualisation. Un schéma manquant est un prérequis signalé.
- Une écriture exige une option explicite, une destination vérifiée et l’empreinte du plan relu. Lier le plan à la base, au serveur, à l’environnement et au manifeste, pas seulement aux contenus.
- Vérifier la destination effective (`SELECT DATABASE()`, hôte, port, serveur) ; ne pas se fier uniquement au nom de l’environnement Symfony. Ne jamais afficher les mots de passe.
- Importer les trois quiz, leurs questions, propositions et traces dans une transaction DML cohérente. Les migrations DDL restent une opération distincte ; ne pas prétendre que MySQL les annulera avec cette transaction.
- Recalculer le plan sous protection concurrente avant toute écriture. Le verrou d’un quiz déjà existant ne suffit pas à protéger deux créations simultanées d’un même `quiz_key` : traiter explicitement ce cas avec unicité persistante et transaction.
- Un second import identique n’ajoute aucune question/réponse/trace et ne modifie aucun état. Si un import partiel est constaté hors transaction, le signaler ; ne pas le compléter silencieusement comme s’il était valide.
- Utiliser les validations Symfony/Doctrine réelles et valider également la banque future de renouvellement sans enregistrer le mode activé. Attention : `QuizEditorialService::validate()` ignore volontairement certains contrôles en mode fixe sans quota ; ne pas en déduire que toutes les réserves ont été validées.
- Tester les refus et le rollback avec une erreur injectée après le début de la transaction.

## Séparation import / activation

Les nouveaux quiz restent fixes : `renewal_enabled=false`, `error_question_quota=NULL`. La réserve n’augmente jamais la longueur d’une tentative.

Prévoir une activation **séparée et ciblée**, avec prévisualisation, validation de banque et conservation de tous les snapshots. Ne pas réutiliser la commande d’activation spécifique module 1 pour les nouvelles cibles. Une activation ou désactivation réelle restera hors autorisation ; seule sa recette est permise sur base jetable.

Ne pas modifier `QuizQuestionSelector` pour parcourir davantage de réserves. La simulation livrée montre qu’avec des positions constantes le moteur peut cycler sur une partie de la banque. C’est une limite connue et documentée du contrat actuel, pas un motif pour élargir discrètement le chantier. Aucun tirage aléatoire, aucune promesse de parcours intégral, aucun quota global d’erreurs à ajouter.

## Recette obligatoire sur base jetable

Préparer une base MySQL jetable explicitement identifiée et distincte des bases de travail, de test habituelles et de production. Ne pas renommer une base de travail pour contourner un garde-fou. Adapter proprement le contrôle des bases de recette si nécessaire ; ne pas desserrer les protections des commandes module 1. Ne pas copier des données personnelles réelles pour la recette.

Utiliser des fixtures représentatives comprenant des quiz existants fixes et renouvelables, cours rattachés, tentatives terminées/en cours, snapshots historiques et progressions. Contrôler :

1. Validation du manifeste : 116/110/6 sources, 177 copies, 354 propositions, 12/12/24 initiales, 19/24/86 réserves, une bonne réponse, `contains_error` explicite, thèmes exacts, clés et positions uniques.
2. Prévisualisation : aucune modification des données ; schéma manquant et collision de titre signalés ; destination différente refusée.
3. Premier import : trois nouveaux quiz fixes, aucun cours attaché automatiquement, comptes exacts, toutes les copies tracées.
4. Deuxième import identique : zéro ajout/modification ; identifiants conservés. Refaire le test avec des valeurs AUTO_INCREMENT différentes sur une autre base jetable : aucune dépendance aux IDs du Word/local.
5. Divergences : question modifiée/supprimée/déplacée, réponse corrigée différemment, rôle/thème/position changé, trace manquante ou étrangère, manifeste changé. Rapport précis et aucune écriture.
6. Concurrence réelle de deux imports : une seule création par clé ; aucune banque partielle ou doublon. Invalidation d’un plan devenu périmé entre aperçu et application.
7. Conservation : comparaison avant/après des lignes et contenus préexistants, pas seulement du nombre de lignes. Tentatives, snapshots et progressions strictement inchangés.
8. Parcours sur rattachements créés uniquement dans les fixtures : premier passage dans l’ordre livré ; mode fixe ne prenant aucune réserve ; reprise après interruption inchangée ; correction 0/1 cohérente ; bilan avec le bon dénominateur.
9. Activation séparée sur la base jetable : nombre constant et quotas exacts aux reprises, sélection déterministe par le vrai service PHP, réutilisation minimale par rapport au snapshot précédent ; pour tout, au moins une question commune dans le général. Réponses et corrigés historiques conservés après modification éditoriale de la banque de test.
10. Aucun test ne doit exiger que toute la réserve soit parcourue. Comparer les premiers passages au simulateur documentaire quand les positions sont celles du manifeste ; tenir compte du fait qu’une activation après des tentatives fixes possède déjà un snapshot précédent.

Exécuter les contrôles de qualité pertinents du dépôt et ses tests de non-régression requis. Rapporter ce qui a réellement été exécuté, ses limites et les éventuels échecs préexistants ; ne pas transformer le rapport Python en preuve de tests Symfony ou MySQL.

## Livraison attendue

- Code de l’importeur/commande et, si nécessaire, migration de traçabilité ; diff limité et relisible.
- Copie versionnée des fichiers éditoriaux validés dans un emplacement du dépôt cohérent avec les conventions existantes.
- Rapports de prévisualisation et de recette jetable, preuve du no-op au second import et de conservation des données préexistantes.
- Documentation des commandes réellement créées, des paramètres nécessaires et de la séquence ultérieure import / rattachement EasyAdmin / activation distincte. Ne fournir aucun exemple prêt à exécuter sur une destination réelle inventée.
- Prérequis restants clairement identifiés ; base de recette et outils temporaires nettoyés après conservation des rapports utiles.

Arrête-toi après cette préparation et cette recette. **Aucune migration sur la base de travail, aucun import réel, aucune activation réelle, aucun commit/push, aucun déploiement.**
