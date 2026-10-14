# Moteur de correction des dictées

Le contrat **`orthogram-dictation-v1`** applique les règles pédagogiques validées
par Caroline et Stéphane : une erreur par unité, accents et majuscules évalués,
ajout/omission à un point, fusion/scission à un point sans double pénalisation,
mots composés avec trait d'union en une unité, ponctuation signalée séparément
sans diminuer le pourcentage orthographique. Il remplace le contrat de travail
`orthogram-dictation-work-v1`, dont le décompte des élisions était différent.
Les futures tentatives devront conserver leur contrat ; aucune migration n'est
nécessaire, aucune tentative persistée n'ayant été créée dans ce lot.

## Utilisation et séparation des résultats

```php
$result = (new DictationCorrector())->correct($reference, $copy, $variants);
$feedback = $result->publicFeedback();
```

Les classes sont dans `src/Services/Dictation/`. `DictationCorrector` coordonne
`DictationTokenizer`, `PassageVariants`, `WordAligner` et `PunctuationComparator`.
`TokenizedText` conserve les originaux et leurs positions. `CorrectionResult`
conserve les deux textes originaux et l'alignement interne : **ne jamais sérialiser
cet objet ou le transmettre à un template public**. Les deux objets de données
sont exclus de la découverte des services Symfony.

Seul `publicFeedback()` fournit le contrat public : `contractVersion`,
`referenceWordCount`, `copyWordCount`, `lexicalErrorCount`,
`punctuationErrorCount`, `score`, `noErrors`, `issues`.
Le nom interne `lexicalErrorCount` reste stable : il désigne les **erreurs
d'orthographe**, y compris les différences grammaticales telles que les accords.
La comparaison détecte `porte` à la place de `portes`, sans classifier la faute
ni produire d'explication grammaticale. `punctuationErrorCount` compte les zones
de ponctuation à revoir ; `score` est le pourcentage orthographique.
Chaque écart contient seulement `domain` (`lexical` ou `punctuation`), `type`
(`substitution`, `insertion`, `omission`, ou `segmentation` pour le lexique),
`start`, `end`. Aucun texte attendu, variante, commentaire pédagogique ou HTML.
Les exceptions de validation sont génériques et n'incorporent jamais les entrées.
Les futurs contrôleurs devront également éviter toute exposition des arguments
d'exception ou de l'objet interne dans leurs réponses d'erreur.

## Décompte et normalisation

Une référence contient au moins une unité ; une copie vide est évaluable. Les
textes originaux restent intacts. Une unité ordinaire commence par une lettre ou
un chiffre Unicode, puis comporte lettres, marques combinatoires et chiffres.
Les traits d'union internes conservent le composé en une unité : `porte-monnaie`.
Une marque combinatoire isolée n'est pas une unité. `countWords()` utilise
exactement le tokenizer de correction, même si son nom historique reste inchangé.

Les préfixes d'élision reconnus, sans distinction de casse pour le découpage,
sont **c, d, j, l, m, n, s, t, qu, jusqu, lorsqu, puisqu**, suivis d'une apostrophe.
L'apostrophe appartient au préfixe : `l’école` → `l’` + `école`, `j’aime` → `j’` +
`aime`, `qu’il` → `qu’` + `il`, `n’ont` → `n’` + `ont`, soit deux unités chacun.
Le découpage s'applique successivement de gauche à droite : `qu'n'ont` produit
trois unités. Il s'arrête sur une forme non reconnue ; ce mécanisme n'affirme pas
la validité grammaticale de la suite.

L'exception lexicale explicite est **aujourd'hui**, en une unité, quelle que soit
sa casse ou la forme droite/typographique de l'apostrophe. `qu'aujourd'hui`
compte donc deux unités. La liste des préfixes n'est pas exhaustive : les formes
non reconnues avec apostrophe interne restent entières, par exemple `quelqu'un`,
`presqu'île`, `prud'homme`, `O'Neill`, `rock'n'roll`. Aucune analyse grammaticale
ni recherche de dictionnaire n'est effectuée.

Les espaces autour de l'apostrophe d'un préfixe reconnu sont conservés dans les
positions et évalués : `l' école`, `l 'école` et `l ’ école` ont deux unités mais
chacun une erreur de séparation face à `l’école`. Les espaces avant l'apostrophe
appartiennent à l'intervalle du préfixe ; ceux qui suivent font partie de sa
frontière de comparaison. Le signalement de cette séparation couvre le préfixe
et ses espaces, sans inclure le mot suivant. Si le mot suivant est omis, son
omission n'entraîne pas une seconde erreur pour l'espace résiduel après le
préfixe, y compris au milieu de la copie : `L’école ouvre.` / `L’ ouvre.`
compte trois unités de référence, une omission en `[3,3)`, aucune erreur de
ponctuation, 66,7 % et `noErrors = false`. L'ancrage reste le début du prochain
mot saisi. Les espaces avant l'apostrophe, une mauvaise apostrophe ou une erreur
de casse sur le préfixe restent évalués, ainsi que les erreurs des mots conservés.
Si `école` est présent, `L’ école ouvre.` conserve une erreur de séparation et
`L’ ecole ouvre.` conserve deux erreurs, séparation et accent.

Après un préfixe reconnu, les signes voisins d'une apostrophe **‘, ʼ, `, ´, ", “, ”**
et les apostrophes répétées restent dans l'unité préfixée, mais ne sont pas des
équivalences : ils provoquent une erreur d'orthographe, sans second signalement
de ponctuation. Le même traitement lexical couvre un signe incorrect dans
`aujourd‘hui`. Les signes isolés et ceux des autres formes non couvertes restent
des signes externes : le moteur ne devine pas s'il s'agit d'une citation ou
d'une apostrophe déplacée hors du mot.

Les valeurs comparées sont normalisées NFC. U+0027 et U+2019 sont équivalents,
ainsi que les traits U+002D, U+2010 et U+2011. Les majuscules et accents restent
significatifs ; aucune conversion générale de `œ` en `oe`.
Les séparateurs ordinaires équivalents sont Unicode Zs/Zl/Zp et U+0009 à U+000D ;
leur présence autour d'une élision reste significative comme décrit ci-dessus.
U+200B, le BOM, les autres contrôles, symboles, tirets longs et signes externes
sont évalués dans le canal ponctuation, jamais supprimés silencieusement.
Les signes externes sont tokenisés par graphème et normalisés NFC.

Les positions sont des intervalles UTF-16 `[start,end)` dans la copie originale.
La longueur originale de chaque mot/graphème est enregistrée **avant** sa
normalisation : `e` suivi d'un accent combiné occupe toujours deux unités ; un
caractère hors BMP en occupe deux. Pas de calcul des positions à partir du texte
normalisé. Les omissions lexicales au début ou au milieu se placent toujours au
début du prochain mot saisi. Pour les omissions après le dernier mot saisi,
l'ancrage de base est la fin de ce mot, avant les signes et espaces finaux.
Les signes conservés précisent ensuite cet ancrage : comparer les ponctuations
de référence après le dernier groupe lexical aligné avec celles de la copie
après son dernier mot, puis placer chaque omission après le dernier signe
conservé qui précède ce mot omis dans la référence.

Ainsi `Le chat dort.` / `Le chat.` donne `[7,7)`, avant le point, tandis que
`Le chat. Demain` / `Le chat.` donne `[8,8)`, après le point. Un guillemet fermant
conservé participe à la même règle. Les espaces ordinaires ou insécables finaux
ne déplacent pas l'ancrage. Sans mot saisi, partir de zéro, puis tenir compte
des signes conservés : `« Le chat. »` / `« . »` ancre les deux mots après `«`,
en `[1,1)`. Plusieurs omissions peuvent partager un point ; celles séparées par
un signe conservé dans la référence peuvent avoir des points distincts.

Les signes conservés sont ceux du préfixe commun, puis du suffixe commun sans
chevauchement, comme pour la comparaison de ponctuation. Si les signes se répètent,
le préfixe est prioritaire : `Le chat. Demain.` / `Le chat.` ancre `Demain` après
le point conservé, en `[8,8)`. Les signes divergents de la zone centrale ne sont
pas appariés ; sans repère conservé antérieur à l'omission, rester après le dernier
mot saisi (ou à zéro sans mot). C'est une convention déterministe, pas une
reconstitution de l'intention de l'apprenant. Le moteur ne modifie pas la copie
et ne fournit pas les espaces qui accompagneront la future insertion.

## Alignement et barème validés

L'alignement est global, avec substitution, insertion et omission à coût 1.
Une fusion/scission consomme de une à trois unités de chaque côté et coûte 1 si
leurs lettres/chiffres accentués, avec leur casse, concordent après retrait des
apostrophes, traits d'union et séparateurs internes. Il faut plusieurs unités sur
au moins un côté. Aucune virgule ou autre ponctuation ne peut être absorbée.
Un séparateur interne absent dans une unité ordinaire constitue une substitution.
Pour une élision fusionnée en une unité, la détection de liaison peut rapprocher
des lettres dont les accents ou la casse diffèrent : `l’école` / `lecole` coûte **une** erreur
de liaison pour deux unités de référence, soit 50 %. Ce rapprochement sert
uniquement à une opération pénalisée, jamais à accepter une graphie sans accent.
`l’école` / `l'ecole` signale seulement `ecole` ; `école` / `ecole` reste une erreur.
Le même groupe pénalisé couvre `LECOLE`. Les ligatures ne sont pas converties.
Ce rapprochement n'utilise aucune ressemblance approximative entre des lettres
différentes ; une fusion accompagnée d'autres changements de lettres peut donc
nécessiter plusieurs opérations dans l'alignement conservateur.

Les frontières des préfixes font partie de l'alignement même quand le nombre
d'unités ne change pas. Deux élisions séparées incorrectement restent deux
erreurs ; un groupe ne peut absorber une erreur de frontière située après sa
dernière unité. Une différence de lettres et de frontière sur un même préfixe
ne compte qu'une fois. Une séparation incorrecte suivie d'un accent incorrect
dans l'autre unité (`l' ecole`) compte deux erreurs.

Pour l'omission intermédiaire, une transition d'alignement associe le préfixe
élidé au préfixe saisi et omet l'unité immédiatement suivante dans la référence.
Elle ne dispense du contrôle que la jonction après le préfixe, lorsque la
référence joint les unités et que la copie les sépare par des espaces. Le coût
reste celui de l'omission, plus une éventuelle erreur indépendante sur le préfixe.
Cette transition restitue deux étapes ordinaires (préfixe, puis omission) ; les
omissions supplémentaires se traitent normalement. Aucun écart n'est supprimé
après coup de la projection publique. La frontière dispensée ne reçoit pas le
bonus d'une correspondance exacte, pour ne pas préférer artificiellement une
omission suivie d'un ajout à une séparation suivie d'une substitution.

Le départage minimise d'abord les opérations pénalisées, puis maximise les
correspondances exactes avec les unités canoniques et leurs frontières. À égalité complète, la priorité
locale lors du retour depuis la fin est : préfixe élidé avec omission immédiate
(lorsque la transition ci-dessus s'applique), diagonale, variantes dans l'ordre
déclaré, groupes par tailles croissantes référence puis copie, omission,
insertion. Ainsi `chat chat dort` / `chat dort` signale le premier `chat` omis.
Ce choix est stable, sans prétendre identifier l'intention de l'apprenant.
En particulier, à coût et bonus égaux dans le cas d'une omission après élision,
le préfixe saisi reste apparié au préfixe de référence, même s'il comporte une
erreur indépendante ; il n'est pas substitué au mot omis.

Pour N unités canoniques et E erreurs d'orthographe :
`score = round(100 * max(0, 1 - E/N), 1)`.
Une copie parfaite de 10 unités vaut 100 ; un ajout, une omission ou une
substitution vaut 90 ; une copie vide ou E >= N vaut 0. Plusieurs différences
dans une unité substituée ne coûtent qu'un point.
`porte-monnaie` / `porte monnaie`, `parce que` / `parceque` et
`l’école` / `l' école` coûtent chacun une opération de segmentation.

La ponctuation est comparée dans les espaces entre les groupes lexicaux alignés,
en regroupant ceux qui entourent des mots omis ou ajoutés. Elle ne dépend pas des
numéros de phrases. Les préfixes/suffixes identiques sont retirés ; le reste
compte comme **une zone de ponctuation divergente**, pas comme un nombre de
caractères fautifs. Les suites `?!;` / `.,:` donnent donc une zone. Pour un signe
manquant, utiliser la fin du dernier signe du préfixe conservé ou, à défaut, la
fin du groupe lexical aligné précédent, avant les espaces. Au début, utiliser le
début du premier mot aligné, sans dépasser le prochain signe conservé ; sans
groupe aligné, partir de zéro. Le signe manquant reste ainsi avant le suffixe
conservé. `Le chat, dort.` / `Le chat dort.` ancre la virgule en `[7,7)`, après
`chat` et avant l'espace. Les apostrophes/traits internes déjà pris en compte
dans une segmentation ne sont pas pénalisés à nouveau.

Cette comparaison reste linéaire même pour une longue suite de signes. Elle
évalue la séquence des signes dans chaque intervalle ; elle n'interprète pas la
grammaire et ne juge pas leur déplacement relatif à un mot absent à l'intérieur
du même intervalle.

`noErrors` signifie exactement zéro opération lexicale **et** zéro zone de
ponctuation divergente. Ne jamais utiliser `score == 100` pour décider de la suite.

| Référence | Copie | Lexique | Ponctuation | Score | noErrors | Position |
| --- | --- | ---: | ---: | ---: | --- | --- |
| Le petit chat dort. | Le chat dort. | 1 | 0 | 75 | false | omission `[3,3)` |
| Le chat, dort. | Le chat dort. | 0 | 1 | 100 | false | omission `[7,7)` |
| Le chat dort. | Le chat. | 1 | 0 | 66,7 | false | omission `[7,7)` |
| Le chat. Demain | Le chat. | 1 | 0 | 66,7 | false | omission `[8,8)` |
| Le chat dort. | Le chat dort. | 0 | 0 | 100 | true | aucune |

Les deux cas d'acceptation validés utilisent la référence
`L’école ouvre ses portes aux enfants chaque lundi matin.` :
**L’ / école / ouvre / ses / portes / aux / enfants / chaque / lundi / matin**,
soit dix unités (sans ajouter de guillemets au texte évalué).

| Copie | Erreurs d'orthographe | Zones de ponctuation | Score | noErrors |
| --- | ---: | ---: | ---: | --- |
| L’ecole ouvre ses porte aux enfants chaque lundi matin. | 2 (accent et accord) | 0 | 80 | false |
| L’école ouvre ses portes aux enfants lundi matin | 1 (omission de chaque) | 1 (point final) | 90 | false |

## Variantes et limites

Caroline ne fournit aucune variante pour la première dictée. Par défaut, seuls
la référence et les équivalences typographiques documentées sont acceptés :
aucun synonyme ni conversion `œ`/`oe`. Aucun contenu éditorial n'est ajouté ici.

Une variante vise **une seule unité canonique à un index précis**, commençant à zéro.
L'index est lié au texte de référence et au contrat, à figer ensemble dans le
futur modèle éditorial. Exemple :

```php
[['referenceIndex' => 0, 'alternatives' => ['coeur']]]
```

Pour `cœur cœur`, cela accepte `coeur cœur`, mais pas `cœur coeur` sans erreur.
Une alternative peut contenir une à trois unités, sans ponctuation externe. Elle
est intégrée à l'alignement à coût zéro ; le dénominateur reste celui du texte
canonique, même si l'alternative comporte plusieurs unités. Les variantes de
passages canoniques de plusieurs unités ne sont pas supportées en V1. Les index
répétés, alternatives équivalentes après normalisation, alternatives canoniques
redondantes et champs inconnus sont refusés.
Dans `l’école l’école`, l'index 1 désigne la première occurrence d'`école`, l'index
3 la seconde. Les frontières d'élision des alternatives sont comparées elles
aussi ; un préfixe alternatif final hérite de la frontière du préfixe canonique
remplacé. Les anciens index ne doivent pas être réutilisés sans leur contrat.

Limites : 500 unités de référence, 750 unités saisies, 20 000 unités UTF-16 par texte ;
32 passages de variantes, 4 alternatives par passage, 128 unités UTF-16 par
alternative et 4 096 unités au total. Refus explicite, aucune troncature.
Les limites sont vérifiées avant l'alignement ; la taille en octets est bornée
avant décodage. Une copie plus longue/courte en unités que la référence est admise.
Le score lexical est calculé avec quatre lignes de coûts réutilisées et une
matrice de directions à un octet par cellule, sans chemins ou objets par cellule.
La transition préfixe + omission ajoute un travail constant par cellule et
utilise les mêmes quatre lignes et la même matrice ; les bornes restent O(N×M)
en temps et en mémoire pour N unités de référence et M unités saisies.

## Dépendances et recette

PHP 8.4 ou supérieur selon le projet. `ext-intl` (`Normalizer`) et
`ext-mbstring` (validation UTF-8 et conversion UTF-16LE) sont déclarées dans
Composer ; aucun paquet n'a été mis à jour. Les extensions natives sont présentes
sur la machine de mesure. Composer peut satisfaire mbstring par le polyfill
déjà installé : vérifier aussi les extensions natives et refaire les mesures sur
Hostinger, dont la configuration et le budget par requête restent à préciser.

```powershell
vendor\bin\phpunit --do-not-cache-result tests/Services/Dictation
vendor\bin\phpstan analyse --configuration=tests/Services/Dictation/phpstan.dist.neon --no-progress src/Services/Dictation tests/Services/Dictation
vendor\bin\php-cs-fixer fix --config=.php-cs-fixer.dist.php --dry-run --diff --using-cache=no src/Services/Dictation tests/Services/Dictation
composer validate --no-check-publish
composer check-platform-reqs --lock
php bin\console lint:container
php -d xdebug.mode=off tests/Services/Dictation/benchmark.php words
```

Le fichier PHPStan ciblé hérite du niveau 6 et désactive uniquement les alertes
sur les exclusions du dépôt non rencontrées dans ce sous-ensemble.
Le benchmark accepte aussi `repeated`, `punctuation`, `length`, `variants`,
`combined`, `elisions`. Chaque cas s'exécute dans un processus séparé, sans noyau ni base.

Mesures du contrat `orthogram-dictation-v1`, le 14 octobre 2026, Windows,
PHP 8.4.15, Xdebug désactivé :

| Corpus synthétique | Secondes | Pic alloué du processus |
| --- | ---: | ---: |
| 500 / 750 unités différentes | 0,3075 | 6 Mio |
| 500 / 750 unités répétées | 0,4175 | 6 Mio |
| Deux textes de 20 000 unités UTF-16, ponctuation divergente | 0,0587 | 34 Mio |
| Deux mots identiques de 20 000 unités UTF-16 | 0,0056 | 4 Mio |
| 500 / 750 unités, 32 passages de 4 variantes | 0,3421 | 6 Mio |
| 500 / 750 unités, textes proches de 20 000 unités UTF-16 chacun | 0,3058 | 8 Mio |
| 500 / 750 unités avec élisions et séparations incorrectes | 0,3966 | 6 Mio |

Mesures d'un passage par cas, après chargement de l'autoloader, incluant le
premier chargement des classes du moteur. Le pic est celui de l'allocateur PHP,
pas la mémoire totale du serveur. Ce ne sont pas des garanties de performance
sur un hébergement mutualisé ni sous concurrence.

Mesures avant/après le correctif d'omission intermédiaire après élision, dans les
mêmes conditions (un passage par corpus, résultats lexicaux inchangés) :

| Corpus | Avant (s) | Après (s) | Pic avant / après |
| --- | ---: | ---: | ---: |
| `elisions` | 0,3876 | 0,4127 | 6 / 6 Mio |
| `words` | 0,3083 | 0,3067 | 6 / 6 Mio |
| `combined` | 0,3106 | 0,3289 | 8 / 8 Mio |

Recette après ce correctif : PHPUnit **194 tests, 1 154 assertions**,
PHPStan niveau 6, PHP CS Fixer et `git diff --check` réussis. `ElisionTest.php`
vérifie les deux copies validées, les préfixes, exceptions et formes non couvertes,
les variantes ciblées, frontières, apostrophes incorrectes et positions Unicode.
Les limites en unités s'appliquent aussi aux chaînes d'élisions successives.

`ElisionOmissionTest.php` ajoute 18 tests : omission intermédiaire simple ou
multiple, mots conservés avec séparation et/ou accent erroné, omission finale,
liaison, ponctuation conservée, espace insécable, erreurs indépendantes sur le
préfixe ou les mots conservés, occurrences répétées et positions UTF-16 avec
caractère hors BMP et accent décomposé. L'alignement des mots conservés est
vérifié explicitement. Avant le correctif, 13 de ces tests échouaient ; le cas
`L’école ouvre.` / `L’ ouvre.` produisait réellement deux erreurs, 33,3 %,
une omission `[0,0)` et une substitution `[0,2)`, sans erreur de ponctuation.

`OmissionAnchoringTest.php` reste intégralement actif : points/guillemets, espaces
finaux, phrase suivante, Unicode hors BMP et décomposé, omissions multiples,
copie vide ou sans mots, ponctuation manquante initiale/médiane/finale et signes
répétés. Le nouveau corpus ajoute les omissions après un préfixe élidé et dans
la phrase suivante. Le corpus inclut aussi un mot composé de 19 999 unités UTF-16
et une longue suite de marques combinatoires : pas d'épuisement de pile PCRE ni
de texte ignoré.

## Contexte à conserver pour les lots suivants

Décision explicite de Stéphane et Caroline : **dictée initiale obligatoire avant
les cours de la section concernée**, après les fondamentaux. L'architecture
retenue la rattache à `Sections`, hors du décompte des cours qui ouvre la reprise,
avec versions éditoriales immuables et conservation des copies/résultats réels.

Parcours validé : terminer la phase initiale ouvre les cours sans score minimal ;
copie vide refusée par le futur parcours, copie incomplète recevable ; signalement
initial unique et figé ; reprise après les cours sans nouveau signalement.
Préserver les accès déjà commencés, identifier les tentatives tardives et
préserver la politique des cours gratuits. Les règles de déploiement restent à
préciser. Propriété, droits, autosave, idempotence et concurrence restent à créer.

Si aucun écart ne subsiste dès la première évaluation ou après autocorrection,
éviter les phases inutiles, conserver uniquement les résultats
réellement obtenus, puis proposer le bilan après les cours. Aucun score fictif.
Le corrigé de Caroline reste fermé jusqu'à la fin des cours et la finalisation
applicable, même sans erreur.

Le verrou pédagogique devra couvrir la recherche et son autocomplétion, les
extraits, les liens directs et les actions serveur, tout en préservant la
politique des cours gratuits. Ces contrôles ne sont pas implémentés dans le lot 1.

EasyAdmin gérera titre, section, référence, commentaire facultatif, deux audios et
segments. Toute modification publiée créera une nouvelle version ; remplacement
audio = nouvelle validation des segments. Préserver les anciens médias.
L'interface future utilisera CSS Orthogram, thèmes, Stimulus, marqueurs accessibles,
édition locale/large et commandes audio segmentées respectant la saisie et Tab.
Aucun de ces états, droits, modèles, médias ou écrans n'est implémenté au lot 1.
