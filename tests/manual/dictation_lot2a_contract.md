# Dictées — lot 2A : persistance et transitions serveur

État de départ vérifié le 16 octobre 2026 : branche `preprod`, commit
`dd63412c0bb8f2df9c92c00dec9f8e1a7deb13e6`, contrat `orthogram-dictation-v1`.
L'archive `orthogram_dictees_lot1_revue.zip` reste non suivie et inchangée.
Le [contrat du moteur](dictation_lot1_contract.md) continue de s'appliquer.

Ce lot installe des services internes, sans route, formulaire, administration,
audio, écran ni nouveau contrôle d'accès aux cours. Il n'active aucune dictée
pour les apprenants. Aucun contenu réel de Caroline ni fixture de production
n'est créé. Les textes et corrections des tests sont exclusivement synthétiques.

## Modèle

| Entité / table | Rôle et invariants |
| --- | --- |
| `Dictation` / `dictation` | Identité attachée à `Sections`, relation unique par section. Ne devient jamais un `Courses` et ne modifie pas le dénominateur de complétion. |
| `DictationVersion` / `dictation_version` | Numéro unique par dictée, titre, texte canonique, variantes ciblées (liste vide par défaut), correction distincte nullable, contrat, dates de création/publication. |
| `DictationAttempt` / `dictation_attempt` | Propriétaire, identité et version figée, état, révision, brouillon, copies évaluées et résultats réels, dates, indicateur de passage tardif et reçus d'idempotence bornés. Unique par utilisateur et dictée. |

Les associations sont unidirectionnelles : aucune collection supplémentaire
sur `User`, `Sections` ou `Courses`. Les dates utilisent le type UTC immuable
existant. Les textes sont des colonnes Doctrine `TEXT` (MySQL `LONGTEXT`), les
résultats et reçus des objets JSON compacts. Aucun historique de frappes,
alignement interne ni copie canonique dupliquée par tentative.

Une version est brouillon tant que `publishedAt` est nul. `publish()` valide le
texte et les variantes avec le moteur puis fige toute la version. Les méthodes
métier et callbacks Doctrine interdisent les modifications et suppressions
d'une version publiée. Une révision optimiste Doctrine protège aussi contre un
brouillon chargé avant une publication concurrente. Une FK `RESTRICT` interdit
en SQL la suppression d'une version utilisée, même hors ORM. Les requêtes SQL
directes contournent les gardes de modification métier : aucune API de mise à
jour directe ne doit les remplacer.

`DictationEditorialService` sérialise la numérotation et le gel par un verrou sur
l'identité. Une modification passe par `createVersion()` ; aucun changement
d'une version ne migre les tentatives. La publication de 2A est un **gel
éditorial interne**, pas une disponibilité apprenant : l'intégration 2B devra
imposer les audios/segments validés avant d'exposer une dictée.

### Enregistrement et publication éditoriale

Le service distingue deux commandes avec révision attendue obligatoire :
`saveDraft(version, expectedRevision)` et `publish(version, expectedRevision)`.
La révision soumise doit être celle affichée par le formulaire, pas une révision
relue automatiquement en base à réception de la demande. Le futur formulaire
EasyAdmin proposera une action d'enregistrement puis une action de publication
sur le brouillon enregistré ; il devra conserver la saisie et afficher les
conflits, sans réessayer automatiquement avec une révision plus récente.

`publish()` refuse explicitement (409) une entité modifiée mais non enregistrée,
avant toute transaction ou relecture. Le contrôle compare les champs au snapshot
Doctrine et tient aussi compte d'un change set déjà préparé sans flush. Les
champs générés d'identifiant/révision sont contrôlés séparément. L'objet conserve
la saisie ; aucune publication n'est effectuée. Le chemin normal est :

```php
$version->edit($title, $text, $correction, $variants);
$editorial->saveDraft($version, $expectedRevision);
// Après enregistrement confirmé, utiliser la nouvelle révision pour l'action suivante.
$editorial->publish($version, $version->getRevision());
```

Les deux commandes prennent les verrous dans le même ordre : identité de la
dictée, puis ligne de version (`SELECT revision ... FOR UPDATE`). La révision
SQL doit correspondre à la révision attendue **et** à celle de l'objet soumis.
Aucun `refresh($version)` n'abandonne les champs locaux. Le flush de sauvegarde
intervient uniquement après acquisition des verrous et validation de la révision ;
la protection optimiste Doctrine et l'immuabilité d'une version publiée restent
actives. Une ancienne révision est refusée même si l'objet transmis au service
vient d'être rechargé. Une publication répétée sur la version publiée, avec sa
révision courante, reste sans effet supplémentaire.

Si une autre connexion a enregistré ou publié la version, la sauvegarde ou la
publication périmée échoue sans écraser la base ni réhydrater l'objet local.
Le refus de modifications non sauvegardées laisse l'EntityManager ouvert ; un
conflit détecté dans la transaction le ferme selon `wrapInTransaction`. Dans ce
dernier cas, conserver les valeurs du formulaire et comparer explicitement avec
la version courante dans une nouvelle requête, sans réutiliser cet EntityManager.

Reproduction avant correctif : création/enregistrement de `Texte A.`, édition
locale en `Texte B.` sans flush, appel de `publish()`, puis `clear()`/relecture.
Le test MySQL a constaté **`Texte A.` publié**, malgré B soumis en mémoire. Le
test de non-régression vérifie désormais le refus initial, B conservé en mémoire,
A non publié en base, puis B publié après enregistrement explicite sous verrou.

Le démarrage choisit côté serveur la dernière version publiée uniquement si
aucune tentative n'existe. Sinon il restitue la tentative existante, y compris
après publication d'une nouvelle version. Il n'existe pas de redémarrage du
parcours pédagogique dans ce lot.

## Copies et résultats

`draft` est la copie modifiable courante. `initialCopy`, `correctedCopy` et
`finalCopy` sont distinctes et ne sont renseignées que lors d'une évaluation
effective correspondante. Les dates `initialEvaluatedAt`, `closedAt`,
`resumedAt` et `finalizedAt` décrivent les transitions réelles.

`initialResult` conserve `publicFeedback()` du moteur, y compris les intervalles
UTF-16 liés à **initialCopy**. Son texte, ses scores et son signalement ne sont
jamais recalculés au rechargement. L'ordre des clés et le type numérique du score
sont stabilisés après lecture du JSON MySQL. Les résultats d'autocorrection et
de reprise ne conservent que les compteurs, score, `noErrors` et contrat, sans
`issues`. Aucun `CorrectionResult` ou `TokenizedText` n'est sérialisé.

Si l'évaluation initiale est parfaite, la clôture référence implicitement ce
résultat : `correctedCopy` et `correctedResult` restent nuls. Si la copie clôturée
est parfaite, la finalisation après les cours réutilise le résultat clôturé :
`finalCopy` et `finalResult` restent nuls. La projection finale indique alors
`finalResultSource = initial_closure`, contre `post_courses` pour une véritable
dernière évaluation. Cela ne crée aucun score ni copie artificiels.

## Transitions et projections

| État | Actions nouvelles permises | Projection apprenant |
| --- | --- | --- |
| `writing` | `save`, `evaluate` | Brouillon, état, révision ; aucun score ni signalement. |
| `self_correction` | `save`, `close` | Brouillon distinct, première copie et premier signalement figés. Aucune justesse calculée pendant la saisie. |
| `awaiting_courses` | `resume` si cours complets | Copie et résumé clôturés, sans signalement ni corrigé. |
| `resuming` | `save`, `finalize` | Brouillon issu de la copie clôturée ; aucun signalement ni résultat de la nouvelle saisie. |
| `reviewing` | `finalize` sans copie | Consultation du résumé clôturé sans nouvelle écriture. Corrigé encore fermé. |
| `completed` | Aucune mutation nouvelle | Bilan et corrigé si les cours sont toujours complets. Aucun intervalle du premier signalement. |

`evaluate` prend une copie explicite et la calcule côté serveur avec la version
attachée. Si `noErrors` est vrai, il clôture immédiatement et passe à
`awaiting_courses`. Sinon il passe à `self_correction`. `close` évalue la copie
autocorrigée et satisfait le prérequis, quel que soit son score. `resume` exige
la complétion et repart exactement de la copie clôturée ; il choisit `reviewing`
si elle est sans erreur, sinon `resuming`. `finalize` recontrôle la complétion.
En consultation sans erreur, cette action explicite ouvre le corrigé sans
nouvelle évaluation.

Une note de 100 avec une erreur de ponctuation ne permet aucun raccourci : toutes
les décisions utilisent `noErrors`. Les copies vides ou composées uniquement
des séparateurs Unicode reconnus par le moteur sont refusées à l'évaluation.
Un brouillon peut être vide ; une copie incomplète ou seulement ponctuée reste
évaluable. Le tokenizer applique les limites et la validation UTF-8 dès la
sauvegarde. Aucun texte n'est tronqué ou normalisé dans le stockage.

L'API interne expose `start(actor, dictationId)`, `state(actor, attemptId)` et
`transition(actor, attemptId, action, expectedRevision, copy)`. L'acteur doit
provenir de l'authentification serveur et être un utilisateur persistant actif.
Le propriétaire est vérifié en base pour chaque lecture/mutation. Une tentative
étrangère produit 404, un conflit d'état/révision 409. Les entrées invalides
produisent une exception de validation à traduire en 400/422 par les futurs
contrôleurs. Ils devront aussi appliquer CSRF et les droits applicables.

La projection est une liste explicite de champs autorisés. Elle ne contient
jamais le texte canonique, les variantes ou les entités internes. La correction
éditoriale n'apparaît que lorsque `canConsultCorrection` est vrai. Un rejeu tardif
de `evaluate` conserve le résultat enregistré mais renvoie la projection de
l'état **actuel**, sans réintroduire le signalement dans la reprise.

## Transactions et idempotence

Toutes les opérations de tentative passent dans une transaction Doctrine et verrouillent
d'abord la même ligne `User` en écriture, comme le service de quiz existant.
Ce verrou existe même avant la première tentative : il sérialise les créations.
La contrainte SQL utilisateur/dictée reste une deuxième protection. La tentative
est ensuite relue sous verrou avec `HINT_REFRESH`, y compris si elle était déjà
présente dans l'identity map Doctrine. Aucun navigateur ne fournit de score,
état cible, contrat, version éditoriale ou état de complétion.

Chaque mutation nouvelle exige la révision courante et l'incrémente une fois.
Le reçu SHA-256 porte sur l'action, la révision attendue et les octets exacts de
la copie (ou null). Une répétition strictement identique d'une transition figée
ne recalcule rien et ne modifie pas les dates. Un payload contradictoire ou une
révision périmée sont refusés. Une demande identique envoyée avec une autre
révision n'est pas le rejeu de la demande initiale.

Seul le dernier reçu de sauvegarde est conservé, et toute mutation suivante
l'invalide. Une ancienne sauvegarde, même identique, ne peut donc pas être
acceptée après clôture ou finalisation. Les quatre reçus d'étapes figées et un
reçu de sauvegarde représentent une taille bornée, sans journal des frappes.
Si une sauvegarde gagne la course, la finalisation doit être renvoyée avec la
nouvelle révision ; si la finalisation gagne, la sauvegarde est refusée.

Les cours et leçons sont lus sous verrou partagé, avec rafraîchissement des
objets. La décision porte sur l'état SQL courant, même sous MySQL REPEATABLE
READ. La règle de complétion est extraite sans changement de comportement dans
`SectionCompletionService::areCoursesComplete()` : section non vide et **tous**
ses cours validés par des `Lesson::DONE`, quels que soient leur type et leur
gratuité. Le chemin d'affichage existant utilise la même règle. Aucun nouvel
enregistrement `Lesson` n'est créé par une dictée.

Les verrous sont conservés jusqu'au commit ; une exception annule la transaction.
Doctrine ferme l'EntityManager en cas d'échec de `wrapInTransaction` : la future
couche HTTP doit terminer cette requête, sans continuer avec cet EntityManager.
Les deadlocks/ruptures de connexion restent des erreurs transactionnelles à
traiter à la frontière HTTP ; le serveur ne rejoue pas automatiquement une
mutation avec une nouvelle révision.

## Intégration future et absence de rétroactivité

`prerequisiteSatisfied`, `canResume` et `canConsultCorrection` préparent les
décisions pédagogiques. Ce sont des faits serveur, **pas** un remplacement des
autorisations `CourseVoter`. Aucun voter, contrôleur, recherche, téléchargement,
abonnement ou cours gratuit n'est modifié ici.

Un passage tardif est détecté si une leçon existe dans la section (STUDY ou DONE)
ou si le dernier cours visité de l'utilisateur appartient à cette section. Le
drapeau est conservé dès le démarrage et peut devenir vrai jusqu'à la clôture
initiale ; les cours suivis normalement ensuite ne le déclenchent pas.

**Limite des données existantes :** `Lesson.studiedAt` peut changer, et le signet
ne conserve que le dernier cours visité. Le projet ne dispose pas d'un historique
exhaustif de début des sections. L'absence de ces indices ne prouve donc pas
qu'une section n'a jamais été commencée. Le futur déploiement des prérequis devra
définir une exemption conservatrice pour les accès déjà ouverts et une date ou
politique d'activation explicite, sans inventer de date de début. Ce lot ne
crée aucune obligation rétroactive et ne migre aucune progression.

La lecture publique des cours gratuits et les autres droits existants restent
prioritaires lors du futur branchement. Préserver explicitement les chemins
`VIEW` et `INTERACT`, ainsi que les contrôles sur recherche, extraits et fichiers.
Si une validation de cours est retirée ou si un cours est ajouté, la complétion
est réévaluée : reprise/finalisation refusées tant qu'elle manque ; le corrigé
est à nouveau masqué, sans modifier les copies et résultats déjà figés.

Restent pour 2B et les intégrations suivantes : administration avec autorisations,
médias et segments validés, disponibilité apprenant distincte du gel éditorial,
routes/protections HTTP, interfaces et branchement effectif des accès. Aucun de
ces travaux n'est démarré ici.

## Migration et vérifications

`Version20261016120000` crée uniquement les trois tables et leurs contraintes.
Le `up` ne modifie aucune donnée existante ; le `down` supprime les trois tables
dans l'ordre des dépendances et détruit donc les éventuelles données de dictées.
MySQL DDL n'est pas transactionnel : la migration le déclare explicitement.

La migration **n'a pas été appliquée en développement ou production**. Les tests
créent une base locale aléatoire `orthogram_dictation_2a_<16 hex>_test`, refusent
un hôte distant/un autre pilote, retirent le nom de base de la configuration
applicative puis contrôlent le nom de la connexion avant le DDL. Les tables
préexistantes y sont créées par SchemaTool ; seule cette migration y est jouée.
Le mapping complet est validé et le diff de schéma doit être vide. Les bases
jetables sont supprimées à la fin. Aucun serveur mail, média ou service externe
n'est utilisé.

Commandes exécutées :

```powershell
vendor\bin\phpunit --do-not-cache-result tests/Services/Dictation tests/Services/Courses/SectionCompletionServiceTest.php tests/Integration/DictationPersistenceTest.php
php -d xdebug.mode=off tests/manual/verify_dictation_lot2a_mysql.php
php bin\console lint:container --env=test
git diff --check
```

PHPStan et PHP CS Fixer sont exécutés sur le périmètre suivant, avec les commandes
équivalentes à celles utilisées pendant le correctif :

```powershell
$dictationPaths = @(
    'src/Services/Dictation', 'src/Entity/Dictation.php',
    'src/Entity/DictationVersion.php', 'src/Entity/DictationAttempt.php',
    'src/Enum/DictationAction.php', 'src/Enum/DictationState.php',
    'src/Services/Courses/SectionCompletionService.php', 'tests/Services/Dictation',
    'tests/Integration/DictationPersistenceTest.php', 'tests/Support/DictationFactory.php',
    'tests/Support/DictationTestDatabase.php', 'tests/manual/verify_dictation_lot2a_mysql.php',
    'migrations/Version20261016120000.php'
)
vendor\bin\phpstan analyse --configuration=tests/Services/Dictation/phpstan.dist.neon --no-progress --error-format=table @dictationPaths
vendor\bin\php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no @dictationPaths
```

Les tests unitaires couvrent toutes les transitions interdites, les copies
vides/limites, le premier résultat figé, les révisions, les deux raccourcis sans
erreur et le score 100 avec ponctuation incorrecte. Le moteur du lot 1 reste
intégralement dans la suite.

Résultat après le correctif de publication : **264 tests, 1 372 assertions** réussis (194 tests
du moteur existant, 44 tests de parcours, 2 tests de complétion et 24 tests de
persistance MySQL). PHPStan niveau 6 sans erreur ; PHP CS Fixer ne propose aucune
modification sur les 29 fichiers PHP contrôlés ; conteneur Symfony valide.

Les huit cas de publication ajoutés couvrent l'édition non sauvegardée suivie
du chemin d'enregistrement/publication, la publication normale et l'immuabilité,
un change set déjà calculé sans flush, quatre combinaisons d'objet ancien
(modifié ou non) et d'action concurrente (enregistrement ou publication), ainsi
que la révision périmée d'un formulaire même lorsque l'objet a été rechargé.
La commande de reproduction initiale, exécutée avant correction et constatant
l'échec attendu A/B, était :

```powershell
vendor\bin\phpunit --do-not-cache-result --filter testPublicationAfterUnsavedEditPreservesSubmittedContent tests/Integration/DictationPersistenceTest.php
```

Les tests sur **MySQL réel**, et non sur des mocks ou SQLite, vérifient la
persistance/relecture des copies Unicode exactes, les projections, les accès
croisés, les contraintes uniques et FK, la protection ORM des données figées,
la publication concurrente face à un brouillon ancien, les validations réelles
de cours et leur retrait depuis une autre connexion. Le script de concurrence
lance deux processus PHP sur des connexions SQL distinctes pour chaque course :
création, première évaluation, sauvegardes contradictoires, clôture, reprise,
finalisation/sauvegarde et double finalisation. Les sauvegardes tardives sont
refusées. Le test de migration exerce `up`, conformité au mapping et `down/up`.

Les garanties SQL ont été vérifiées sur le MySQL local du projet. MariaDB est
accepté par le garde de plateforme de la migration mais n'a pas été exécuté.
Aucun test de charge ni déploiement sur Hostinger n'est inclus dans 2A.
