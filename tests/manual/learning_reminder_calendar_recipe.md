# Recette manuelle des cas temporels A à D

Le lot de recette manuelle A–D est clos avec les écarts consignés ci-dessous. Cette clôture ne signifie pas que tous les imports respectent les attentes. Les conclusions concernent uniquement les parcours et dates contrôlés ; aucune compatibilité universelle Apple/Outlook n’est annoncée. Aucun nouveau correctif, scénario ou contournement n’est engagé dans ce lot.

Le support a généré uniquement B et son manifeste dans `var/calendar-recette/correction-b/`. A, C et D n’ont pas été réécrits pour ce lot ; leurs attentes fixes et leurs tests de régression sont conservés. Les commandes et procédures ci-dessous restent documentées à titre d’historique, sans nouvelle génération ni nouvel import demandé pour cette clôture.

## Bilan final des imports A–D

### A corrigé — conforme sur les contrôles rapportés

Fichier : `var/calendar-recette/correction-a/A-premiere-heure-inexistante.ics`.

- Apple sur iPhone, import depuis Mail : 29/03/2026 à 03:30–03:45 Paris ; 30 et 31/03 à 02:30–02:45.
- Outlook classique : début à 03:30 le 29/03, puis 02:30 du 30/03 au 05/04. Les fins sont confirmées à 03:45 le 29/03 et à 02:45 les 30 et 31/03. Aucune confirmation des fins du 01 au 05/04 n’est rapportée.

La première occurrence séparée et le retour à l’heure nominale sont conformes à ces dates. Cela ne valide ni toutes les occurrences ni les opérations de modification/suppression des deux objets.

### B corrigé — conforme dans Apple aux dates contrôlées, écart persistant dans Outlook

Fichier : `var/calendar-recette/correction-b/B-premiere-heure-ambigue.ics`.

Le fichier corrigé vérifié porte le SHA-256 `97fcc0884d10a41813e4bf482e25fc6bf3e7ea8adb5f58ba238325a48fb5cffe`. Son premier événement contient exactement :

```ics
UID:ac80b9266e27f2da8bca6471006e3d16@orthogram
DTSTART:20261025T013000Z
DTEND:20261025T014500Z
```

- Apple, affichage Calendrier imposé à Reykjavik : 25/10/2026 et 01/11/2026 à 01:30–01:45 UTC, conformes. L’aperçu avant ajout affichait les intitulés « samedi 24 octobre » et « samedi 31 octobre » ; les fiches enregistrées avaient les bonnes dates. Aucune cause n’est attribuée à cette observation.
- Outlook, premier événement du 25/10, après correction : `StartUTC = 2026-10-25 00:30:00`, `EndUTC = 2026-10-25 00:45:00`. L’attendu reste 01:30–01:45 UTC : une heure trop tôt, durée de 900 secondes conservée. Le réexport Outlook conserve l’UID ci-dessus, mais écrit 02:30–02:45 locales avec le TZID `Romance Standard Time`. Ces éléments décrivent les fichiers et instants observés, sans démontrer la cause interne de l’écart.
- Outlook, 01/11 après correction : le début est cohérent dans la grille, mais aucune nouvelle lecture COM complète de début et fin n’a été fournie. La conformité COM mentionnée dans l’historique est antérieure à la correction B ; elle n’est pas une nouvelle validation.

### C — conforme dans Apple aux dates contrôlées, durée incorrecte dans Outlook le 30 mars

Fichier : `C-duree-transition-printemps.ics`, conservé dans les lots initial et correction-a, avec le même contenu temporel. Dans Outlook, l’import a été effectué depuis `var/calendar-recette/correction-a/C-duree-transition-printemps.ics`, conformément au parcours explicitement rapporté. L’exemplaire utilisé dans Apple n’est pas précisé dans le bilan.

- Apple : occurrences locales des 29 et 30/03 conformes aux attentes conservées ci-dessous, durée de 900 secondes.
- Outlook, lecture COM, 29/03 : 00:55–01:10 UTC, conforme.
- Outlook, occurrence locale du 30/03 : début `2026-03-29 23:55:00 UTC`, correct ; fin `2026-03-30 01:10:00 UTC`, au lieu de `2026-03-30 00:10:00 UTC`. Durée observée : 75 minutes au lieu de 15.

### D — durée incorrecte le 25 octobre dans les deux clients

Fichier : `D-duree-transition-automne.ics`, conservé dans les lots initial et correction-a, avec le même contenu temporel. Le bilan rapporté ne précise pas lequel de ces deux exemplaires a été importé.

| Date locale | Début–fin UTC observés dans Apple et Outlook | Conclusion sur ce contrôle |
| --- | --- | --- |
| 24/10/2026 | 00:55–01:10 | Conforme, 900 secondes |
| 25/10/2026 | 00:55–02:10 | Début conforme ; fin attendue 01:10 ; 75 minutes au lieu de 15 |
| 26/10/2026 | 01:55–02:10 | Conforme, 900 secondes |

Les heures Outlook proviennent de `StartUTC`/`EndUTC` via COM. Les fiches Apple affichent explicitement UTC+0. La similitude des résultats ne démontre ni un mécanisme interne ni une cause commune aux importeurs.

## Historique des lots — générations du 7 septembre 2026

Les dates ci-dessous proviennent des champs `generated_at_utc` des manifestes conservés. Elles datent la génération des fichiers, pas les imports manuels, dont les dates d’exécution ne sont pas consignées.

| Lot archivé | Génération UTC |
| --- | --- |
| initial, `var/calendar-recette/` | 2026-09-07 10:08:50Z |
| `var/calendar-recette/correction-a/` | 2026-09-07 10:33:50Z |
| `var/calendar-recette/correction-b/` | 2026-09-07 15:06:54Z |

Les lots initial et `correction-a/` sont conservés intégralement.

L’ancien A initial (SHA-256 `dc9f0750e9d3c47a601f9bb7250ab7f62725ef086afaad028fb3885d8aa23a89`) reste archivé avec son écart : Apple à 03:30–03:45 les 29 et 30 mars ; débuts Outlook à 03:30 aux dates visibles jusqu’au 05/04, sans contrôle des fins. Ne pas confondre ce lot avec A corrigé.

Avant correction B, dans les environnements Apple et Outlook testés, B non séparé échouait sur sa première occurrence du 25/10/2026 : attendu 01:30–01:45 UTC, observé 00:30–00:45 UTC. Apple a été lu avec l’affichage Calendrier imposé à Reykjavik ; Outlook via `StartUTC` et `EndUTC` en COM. Le 01/11/2026 était conforme dans Outlook avant correction B : `StartUTC = 2026-11-01 01:30:00`, `EndUTC = 2026-11-01 01:45:00`. Cette mesure historique ne valide pas l’occurrence après correction. Ces faits ne prouvent ni les mécanismes internes des importeurs ni une cause commune.

## Génération locale sous PowerShell — lot du 7 septembre 2026

Depuis la racine du projet, avec PHP et les dépendances Composer déjà présentes :

```powershell
Set-Location 'C:\Formations\orthogram'
php -l .\tests\manual\generate_learning_reminder_calendar_cases.php
php -d xdebug.mode=off .\tests\manual\generate_learning_reminder_calendar_cases.php --base-uri="https://127.0.0.1:8000"
```

La valeur passée correspond à la dernière définition locale de `DEFAULT_URI` inspectée pour ce lot. Le script exige cet argument explicite et ne charge aucun fichier `.env`. Il utilise le calculateur, le service calendrier et le writer existants, ainsi que les attributs réels du contrôleur pour générer `app_user_training`. Il ne démarre pas le noyau Symfony, n’instancie pas le contrôleur et n’accède ni à Doctrine ni à la BDD. Aucun changement de l’horloge système : chaque appel à `prepare()` reçoit la référence UTC indiquée ci-dessous.

Les deux nouvelles sorties sont dans `var/calendar-recette/correction-b/` :

- `B-premiere-heure-ambigue.ics`
- `manifest.json`

Chaque ICS est la chaîne exacte renvoyée par `prepare()`, écrite en binaire puis relue pour comparaison stricte. Aucun ajout, réparation, changement de titre, UID ou fin de ligne. Le manifeste conserve les paramètres, références, attentes, UID de chaque VEVENT, tailles et SHA-256 des fichiers, versions PHP/fuseaux et empreintes des sources utilisées. Les attentes ne sont pas des occurrences calculées par un vérificateur.

Les manifestes restent figés à leur génération : leurs mentions d’imports « non exécutés » décrivent cet instant historique. Le présent guide consigne les résultats ultérieurs ; aucun manifeste ni ICS n’est réécrit pour la clôture.

Le script refuse tout écrasement. Ne pas le relancer pour préparer chaque import : utiliser les mêmes fichiers de ce lot dans les trois agendas. Une nouvelle génération produit de nouveaux UID. Les lots initial et correction-a restent intacts. Leurs dix empreintes figurent dans le manifeste et sont contrôlées avant et après écriture. Le script exige leur présence et ne les réécrit jamais. En cas d’échec d’écriture, ne pas importer un lot incomplet.

`var/` est déjà ignoré par Git. Les références de mars sont historiques : retrouver explicitement mars 2026 dans l’agenda après import. L’expiration de la préparation dans le manifeste ne rend pas le fichier inutilisable ; elle concerne seulement la préparation affichée dans Orthogram. Le lien de ce lot est `https://127.0.0.1:8000/ma-formation` : il ne permet pas de tester l’accès à la bêta depuis l’iPhone.

## Références et nature des attentes

Tous les cas utilisent `Europe/Paris`, une durée de 900 secondes et l’horizon applicatif de cinq ans. `scheduledDate` vaut `null` dans les quatre payloads.

| Cas / fichier | Fréquence et heure nominale | Référence UTC passée à prepare() | Référence à Paris |
| --- | --- | --- | --- |
| A / `A-premiere-heure-inexistante.ics` | daily, 02:30 | 2026-03-28 23:00:00Z | 2026-03-29 00:00:00 +01:00 |
| B / `B-premiere-heure-ambigue.ics` | weekly, dimanche ISO 7, 02:30 | 2026-10-25 01:00:00Z | 2026-10-25 02:00:00 +01:00, second passage |
| C / `C-duree-transition-printemps.ics` | daily, 01:55 | 2026-03-28 23:00:00Z | 2026-03-29 00:00:00 +01:00 |
| D / `D-duree-transition-automne.ics` | daily, 02:55 | 2026-10-24 00:00:00Z | 2026-10-24 02:00:00 +02:00 |

**A : premier événement Orthogram séparé, lot correction-a conservé.** Son fichier contient un événement unique UTC le 29 mars et une série commençant le 30 mars à 02:30 Paris, avec deux UID distincts. Aucun RECURRENCE-ID, EXDATE ou RDATE. L’instant initial choisi par Orthogram est conservé. Vérifier que l’import ajoute les deux objets. Le premier événement ne sera pas modifié ni supprimé avec la série : pour agir sur l’ensemble, intervenir sur les deux. L’avertissement correspondant figure dans les descriptions et dans les précisions de l’export Orthogram.

**B : premier événement Orthogram séparé dans correction-b.** Un événement unique UTC, avec son propre UID, porte `DTSTART:20261025T013000Z` et `DTEND:20261025T014500Z`. La série, avec un autre UID, commence par `DTSTART;TZID=Europe/Paris:20261101T023000`, `DURATION:PT15M` et `RRULE:FREQ=WEEKLY;BYDAY=SU;WKST=MO;UNTIL=20311025T013000Z`. Aucun EXDATE, RDATE ni RECURRENCE-ID. Attendre une seule occurrence le 25 octobre, à 01:30 UTC, et aucune à 00:30 UTC.

La séparation est déclenchée parce que le calculateur choisit 01:30 UTC, différent du premier passage nominal 00:30 UTC. Une heure ambiguë avec premier passage choisi ne déclenche pas de séparation. L’ancrage est le premier jour civil suivant sélectionné ; s’il est inexistant ou ambigu, hors borne ou non postérieur, l’export échoue sans sauter ce jour.

Le premier rendez-vous et la série se modifient et se suppriment séparément, comme A. L’avertissement réutilisé dans la modale et les descriptions ICS est : « Le premier rendez-vous sera ajouté séparément. Pour modifier ou supprimer l’ensemble dans votre agenda, intervenez sur ce rendez-vous et sur la série. » L’expiration reste 01:05 UTC avec la référence 01:00 UTC, ou 01:30 UTC avec une référence à 01:28 UTC. Le VTIMEZONE couvre la période d’origine jusqu’au 25/10/2031 à 01:45 UTC.

**C et D : récurrences iCalendar ordinaires, sans exception initiale.** Pour D, le 25 octobre est une occurrence ultérieure : l’heure ambiguë désigne le premier passage selon la RFC, contrairement au premier rendez-vous séparé de B. Les 15 minutes de `DURATION:PT15M` sont une durée écoulée, même si les heures locales semblent s’éloigner ou reculer. Une heure locale inexistante produite ultérieurement par RRULE doit être ignorée ; cela ne constitue pas une promesse de reproduction globale du calculateur Orthogram. Références : [RFC 5545 §3.3.5](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.3.5), [durées §3.3.6](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.3.6), [récurrences §3.3.10](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.3.10) et [RECURRENCE-ID §3.8.4.4](https://www.rfc-editor.org/rfc/rfc5545.html#section-3.8.4.4).

## Attentes initiales conservées — lot clos

Ces tableaux et critères restent les attentes du produit ; ils ne sont pas ajustés aux écarts des importeurs. Les consignes de contrôle sont conservées comme historique de la recette, pas comme demande de nouveaux scénarios. Le bilan ci-dessus indique les contrôles réellement rapportés.

Dans les tableaux, les secondes valent toujours `00`. Les décalages sont ceux de Paris aux instants concernés. Chaque ligne doit correspondre à exactement une occurrence, avec une différence fin UTC moins début UTC de 900 secondes.

### A — première heure inexistante

Fichier : `A-premiere-heure-inexistante.ics`.

| Début local | Fin locale | Début UTC | Fin UTC |
| --- | --- | --- | --- |
| 2026-03-29 03:30 +02:00 | 2026-03-29 03:45 +02:00 | 2026-03-29 01:30Z | 2026-03-29 01:45Z |
| 2026-03-30 02:30 +02:00 | 2026-03-30 02:45 +02:00 | 2026-03-30 00:30Z | 2026-03-30 00:45Z |

Le 29 mars, 02:30 n’existe pas. Vérifier la présence unique du rendez-vous séparé à 03:30, puis la série à 02:30 le lendemain. Contrôler aussi chaque début et fin visible jusqu’au 05/04 : 02:30–02:45 +02:00, soit 00:30–00:45 UTC à chaque date. Une série décalée durablement à 03:30 reste un écart.

La reprise de A portait sur **le fichier A du lot correction-a**, avec une première occurrence indépendante de la série. Les anciens imports ne sont pas mis à jour automatiquement. La gestion séparée des deux objets est une conséquence connue de leurs UID distincts ; aucune preuve de manipulation des commandes de modification ou suppression n’a été fournie. Les fins confirmées dans Outlook sont maintenant consignées dans le bilan final, sans étendre cette confirmation aux autres dates.

### B — première heure ambiguë après le premier passage

Fichier corrigé de cette recette : `var/calendar-recette/correction-b/B-premiere-heure-ambigue.ics`, identifié par le SHA-256 du manifeste et du bilan final, distinct des lots antérieurs.

| Début local | Fin locale | Début UTC | Fin UTC |
| --- | --- | --- | --- |
| 2026-10-25 02:30 +01:00 | 2026-10-25 02:45 +01:00 | 2026-10-25 01:30Z | 2026-10-25 01:45Z |
| 2026-11-01 02:30 +01:00 | 2026-11-01 02:45 +01:00 | 2026-11-01 01:30Z | 2026-11-01 01:45Z |

Aucun événement à `2026-10-25 00:30Z` (premier 02:30, +02:00). Vérifier les dimanches sélectionnés et l’absence d’événement le lundi 26 octobre. Un affichage « 02:30–02:45 » sans possibilité de distinguer le décalage ne suffit pas.

### C — durée traversant le passage au printemps

Fichier : `C-duree-transition-printemps.ics`.

| Début local | Fin locale | Début UTC | Fin UTC |
| --- | --- | --- | --- |
| 2026-03-29 01:55 +01:00 | 2026-03-29 03:10 +02:00 | 2026-03-29 00:55Z | 2026-03-29 01:10Z |
| 2026-03-30 01:55 +02:00 | 2026-03-30 02:10 +02:00 | 2026-03-29 23:55Z | 2026-03-30 00:10Z |

La première durée est bien 15 minutes, malgré un écart de 75 minutes entre les libellés locaux. Attention au changement de date UTC pour la ligne du 30 mars.

### D — durée traversant le passage à l’automne

Fichier : `D-duree-transition-automne.ics`.

| Début local | Fin locale | Début UTC | Fin UTC |
| --- | --- | --- | --- |
| 2026-10-24 02:55 +02:00 | 2026-10-24 03:10 +02:00 | 2026-10-24 00:55Z | 2026-10-24 01:10Z |
| 2026-10-25 02:55 +02:00 | 2026-10-25 02:10 +01:00 | 2026-10-25 00:55Z | 2026-10-25 01:10Z |
| 2026-10-26 02:55 +01:00 | 2026-10-26 03:10 +01:00 | 2026-10-26 01:55Z | 2026-10-26 02:10Z |

Le 25 octobre, le début est dans le premier passage et la fin dans le second. Aucun deuxième départ à `01:55Z`. Une fin à `02:10Z` après un début à `00:55Z` représente 75 minutes et constitue un écart, même si l’interface semble afficher un quart d’heure. Ne jamais déplacer l’occurrence pour faciliter la lecture.

## Lecture UTC pratique, particulièrement pour B et D — méthode conservée

Dans cette recette, la grille secondaire UTC d’Outlook n’a pas suffi à départager les passages de l’heure ambiguë : « non vérifiable avec cet affichage ». Les lectures COM `StartUTC`/`EndUTC` font foi pour les observations Outlook rapportées. Une cohérence visuelle du début dans la grille ne remplace pas une mesure complète de début et fin. La méthode Google ci-dessous était proposée ; elle n’a pas été exécutée pour les séries ICS de ce lot.

Noter le réglage d’affichage initial, puis le rétablir après contrôle. Ne modifier ni les heures/fuseaux des événements, ni la série, ni la date ou l’heure du système. Examiner les occurrences importées, pas seulement l’aperçu de la pièce jointe.

- **Google Agenda sur ordinateur** : après l’import ICS dans un agenda de recette, ouvrir Paramètres → Général → Fuseau horaire. Choisir temporairement UTC comme fuseau principal d’affichage ; si le sélecteur recherche une ville, utiliser Reykjavik. Revenir au calendrier et ouvrir les occurrences du 25 octobre. Relever les deux heures exactes, puis rétablir Paris. L’ajout d’un fuseau secondaire peut aussi aider, mais une grille trop imprécise ne suffit pas. [Aide Google sur les fuseaux](https://support.google.com/calendar/answer/37064?hl=en-au).
- **Outlook classique** : Fichier → Options → Calendrier → Fuseaux horaires → Afficher un deuxième fuseau horaire, choisir UTC et le nommer « UTC ». Garder le fuseau principal existant. En vue Jour/Semaine, examiner le 25 octobre avec une échelle assez fine pour lire les bornes à cinq minutes. Le simple nom « UTC » saisi dans une étiquette ne règle pas le fuseau : vérifier aussi la sélection. Si la grille fusionne les heures répétées ou ne permet pas de déterminer les deux instants exacts, inscrire « non vérifiable avec cet affichage ». Ne pas permuter le fuseau principal pour cette recette. [Aide Microsoft, section Outlook classique](https://support.microsoft.com/en-us/outlook/getstarted/manage-time-zone-settings-in-outlook).
- **Calendrier Apple sur iPhone, après import depuis Mail** : Réglages → Apps → Calendrier → Ignorer le fuseau horaire (Time Zone Override, libellé selon iOS), activer puis choisir Reykjavik. Ouvrir à nouveau les occurrences importées du 25 octobre et relever les heures affichées dans ce fuseau, en distinguant une éventuelle mention de l’heure d’origine. Cette option change l’affichage de Calendrier seulement. Si les deux heures ne sont pas accessibles clairement, inscrire « non vérifiable avec cet affichage ». Rétablir ensuite le réglage initial. [Guide Apple](https://support.apple.com/en-ie/guide/iphone/iph69525c028/ios).

Reykjavik correspond à UTC+00:00 aux dates de cette recette, sans changement saisonnier ; ce choix de ville sert uniquement à lire les instants. Sources de fuseaux : [alias IANA](https://data.iana.org/time-zones/tzdb/backward) et [règles de la zone correspondante](https://data.iana.org/time-zones/tzdb/africa). Ne pas remplacer par Londres, dont le décalage varie.

Lecture décisive le **25 octobre 2026** : B = **01:30–01:45 UTC**, aucun 00:30 ; D = **00:55–01:10 UTC**, aucun 01:55. Capturer le réglage du fuseau et les deux heures, avec la date. Une conversion mentale partant du décalage supposé n’est pas une preuve. Si un autre client synchronisé est nécessaire, consigner ce parcours séparément : il ne prouve pas à lui seul le rendu du client initial.

## Horizon et déroulement des imports — attentes et procédure conservées

La borne est calculée depuis le premier début en UTC : même mois, jour et heure cinq années civiles plus tard, avec rabattement du 29 février au dernier jour de février si nécessaire. Elle est inclusive pour les débuts, pas une obligation de produire un événement ce jour-là. Le VTIMEZONE couvre jusqu’à cette borne plus 900 secondes. Le rappel Orthogram n’est pas limité par cet export.

| Cas | UNTIL attendu (UTC, inclusif pour les débuts) |
| --- | --- |
| A | 2031-03-29 01:30:00Z |
| B | 2031-10-25 01:30:00Z |
| C | 2031-03-29 00:55:00Z |
| D | 2031-10-24 00:55:00Z |

La reprise de B concernait uniquement le fichier corrigé, sans régénération de A, C et D. Le résultat après import est consigné dans le bilan final. Les anciennes séries ne sont pas mises à jour automatiquement. La modification et la suppression séparées du premier rendez-vous et de la série étaient prévues dans la procédure ; elles ne sont pas déclarées testées faute de preuve. La procédure initiale ci-dessous est archivée, sans nouvelle exécution demandée.

1. Garder le manifeste avec le lot. Utiliser le nom de fichier et son SHA-256 pour identifier le cas ; les titres ICS restent volontairement identiques.
2. Importer un seul fichier dans un agenda de recette vide/isolé. Google : parcours d’import ICS sur ordinateur ; Outlook classique : import du fichier dans un calendrier de recette ; iPhone : pièce jointe Mail comme lors du premier lot. Noter le parcours réel et le compte/calendrier de destination. Le script n’envoie aucun e-mail et ne réalise aucun import.
3. Contrôler les lignes du tableau du cas, le nombre d’occurrences aux instants concernés, les départs interdits et la durée. Pour B et D, effectuer obligatoirement la lecture UTC. Consigner tout refus d’import ou occurrence manquante.
4. Noter application/version, système, type de compte, nom de fichier, SHA-256, début/fin réellement observés, fuseau de lecture et captures. Qualifier chaque point : « conforme sur ce point », « écart observé », « non vérifiable avec cet affichage » ou « non exécuté ».
5. Isoler le cas suivant pour éviter de confondre les séries ou de créer des doublons par réimport. Ne pas modifier les ICS ni réenregistrer les occurrences pour obtenir les attentes.

## Portée et limites

Le succès du générateur démontre seulement l’appel des services et la conservation exacte des octets. Il n’exécute ni sabre, ni expansion de récurrences, ni suite PHPUnit. Ces contrôles manuels ciblent les occurrences listées ; ils ne certifient pas chaque occurrence sur cinq ans ni l’interprétation indépendante du VTIMEZONE embarqué.

Les premiers résultats déjà rapportés restent acquis uniquement dans leur périmètre :

- Orthogram : export sans enregistrement du rappel en BDD, fermeture possible après export, enregistrement du rappel distinct, corrections de présentation conformes pendant le parcours testé.
- Google Agenda : événement unique ajouté et confirmé. Ce test ne valide pas l’import des séries ICS.
- Outlook classique : événement unique du 08/09/2026 à 16:00–16:15 sans périodicité ; série lundi/jeudi avec 522 occurrences reconnues, premières occurrences conformes, débuts à 16:00 aux dates contrôlées autour d’octobre 2026 et mars 2027 ; dernière occurrence le 04/09/2031, aucune les 08 et 11/09/2031.
- Apple sur iPhone depuis Mail : événement unique du 08/09/2026 à 16:00–16:15 ; série lundi/jeudi conforme aux dates contrôlées, notamment 26/10/2026 et 29/03/2027 à 16:00–16:15 ; dernière occurrence le 04/09/2031, absence après la borne contrôlée. Série quotidienne du 07/09/2026 au 07/09/2031 à 16:00–16:15, aucun événement le 08/09/2031.

La série quotidienne Outlook s’arrête au 01/06/2029, soit 999 occurrences. Une série créée directement dans Outlook avec une fin saisie au 07/09/2031 est également ramenée au 01/06/2029 après enregistrement. Cette restriction est reproductible sans import Orthogram, uniquement dans cet environnement ; sa cause exacte reste inconnue, ce n’est pas une limite universelle établie. Cette coupure ne se reproduit pas sur l’iPhone testé. Aucune adaptation du writer, de la durée ou de l’horizon n’est faite pour la contourner.

Les imports Google récurrents, le véritable parcours bêta et l’URL issue de son `DEFAULT_URI` restent non vérifiés. Les exports locaux contiennent l’URL locale attendue. Aucune nouvelle recette n’est ouverte ici pour ces points.

Les résultats automatisés du comportement Orthogram et du fichier produit restent distincts des tests de caractérisation de sabre sur des fixtures fixes et des observations d’import. Les caractérisations de sabre ne constituent pas des validations produit et ne démontrent pas l’interprétation indépendante du `VTIMEZONE` embarqué. Une limite connue du vérificateur ne valide pas le fichier. Les écarts constatés sur B dans Outlook, C dans Outlook et D dans les deux clients restent ouverts comme constats, sans cause interne attribuée ni nouvel essai de correction dans ce lot. Les attentes de 900 secondes et les instants UTC d’origine sont conservés.

## Livraison du correctif B : historique lié au lot du 7 septembre 2026

Lors de la livraison du correctif B : 74 tests de services et de caractérisation sabre (27 187 assertions), puis 45 tests fonctionnels (565 assertions), tous réussis. Les tests fonctionnels imposent APP_ENV=test et vérifient le suffixe `_test` de la base avant les écritures de leurs fixtures. La suite complète n’avait pas été lancée lors de cette livraison ; son exécution de clôture est consignée séparément ci-dessous.

Sept fichiers PHP modifiés ont passé `php -l`. `git diff --check` et le contrôle des fichiers non suivis n’ont signalé aucun problème d’espaces. La comparaison de A, C et D avec correction-a, exclusivement en mémoire et hors UID, n’a relevé aucune autre différence, donc aucun impact sur leur contenu temporel. Aucun de ces trois fichiers n’a été écrit dans correction-b.

À cette livraison, les dix empreintes des lots antérieurs étaient inchangées. Le calculateur, l’oracle, ses fixtures de caractérisation, les dépendances, le Twig, le Stimulus, le CSS et le contrôleur HTTP étaient également inchangés, vérifiés par empreintes. B corrigé n’avait alors pas encore été importé manuellement ; ses résultats ultérieurs figurent désormais dans le bilan final.

Fichiers modifiés pour cette livraison :

- `src/Services/LearningReminderCalendarService.php`
- `src/Services/LearningReminderIcalendarWriter.php`
- `tests/Services/LearningReminderCalendarServiceTest.php`
- `tests/Services/LearningReminderCalendarOccurrencesTest.php`
- `tests/Services/LearningReminderVtimezoneTest.php`
- `tests/Controller/Course/LearningReminderControllerTest.php`
- `tests/manual/generate_learning_reminder_calendar_cases.php`
- `tests/manual/learning_reminder_calendar_recipe.md`

Commandes de validation et de génération exécutées depuis la racine (PowerShell) :

```powershell
$calendarPhpFiles = @('src/Services/LearningReminderCalendarService.php', 'src/Services/LearningReminderIcalendarWriter.php', 'tests/Services/LearningReminderCalendarServiceTest.php', 'tests/Services/LearningReminderCalendarOccurrencesTest.php', 'tests/Services/LearningReminderVtimezoneTest.php', 'tests/Controller/Course/LearningReminderControllerTest.php', 'tests/manual/generate_learning_reminder_calendar_cases.php')
foreach ($calendarPhpFile in $calendarPhpFiles) { php -d xdebug.mode=off -l $calendarPhpFile; if ($LASTEXITCODE -ne 0) { throw "Syntaxe PHP : $calendarPhpFile" } }
php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit tests/Services/LearningReminderCalendarServiceTest.php tests/Services/LearningReminderCalendarOccurrencesTest.php tests/Services/LearningReminderVtimezoneTest.php tests/Support/IcalendarOracleTest.php
php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit tests/Controller/Course/LearningReminderControllerTest.php
php -d xdebug.mode=off .\tests\manual\generate_learning_reminder_calendar_cases.php --base-uri="https://127.0.0.1:8000"
git diff --check
```

La génération a déjà eu lieu : utiliser le lot existant. La commande de génération refuse son écrasement.

## Vérifications automatisées de clôture

La suite complète prévue par `phpunit.dist.xml` a été exécutée une seule fois : **239 tests, 28 186 assertions, tous réussis**, code de sortie 0, durée PHPUnit 8,997 secondes. Environnement : PHP 8.4.15, PHPUnit 13.2.3. Un contrôle préalable du noyau et de la connexion Doctrine a confirmé `APP_ENV=test` et la base `orthogram_test` ; la configuration PHPUnit force cet environnement. Aucun test fonctionnel n’a utilisé la BDD métier.

Commande exécutée depuis la racine du projet, sous PowerShell :

```powershell
php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit
```

Cette réussite inclut les assertions produit, les tests de sécurité et d’absence de mutation ainsi que les caractérisations des limites de sabre ; elle ne transforme pas les écarts d’import rapportés en conformités. Aucun import manuel, nouveau scénario ou générateur de lot n’a été exécuté pour cette clôture. Seul ce guide a été actualisé ; aucun correctif applicatif, test ou changement de dépendance n’a été réalisé.

`git diff --check` a réussi sans diagnostic. Un contrôle complémentaire `git diff --no-index --check -- /dev/null <fichier>` sur les onze fichiers non suivis a également réussi sans anomalie d’espaces. Le contrôle par empreintes SHA-256 des 31 fichiers recensés avant intervention confirme que seul ce guide a changé : les 18 autres fichiers du périmètre de commit et les 12 artefacts des trois lots sont inchangés. Le SHA-256, l’UID et les deux instants UTC du premier événement B correspondent aux valeurs du bilan ci-dessus.

La revue de `git status`, du diff et des fichiers non suivis identifie 19 fichiers liés au lot exports et à sa modale, sans changement étranger identifié. Dans `composer.lock`, seuls `sabre/vobject` 5.0.0, `sabre/uri` 3.1.0 et `sabre/xml` 4.1.0 sont ajoutés aux dépendances de développement, avec le `content-hash` associé ; aucun paquet existant ne change. Ces ajouts sont antérieurs à la clôture. Aucune modification n’est indexée, les artefacts de `var/calendar-recette/` restent ignorés, aucun ajout à l’index ni commit n’a été exécuté.
