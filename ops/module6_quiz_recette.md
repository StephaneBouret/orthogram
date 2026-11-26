# Module 6 — recette locale des quiz

## Navigateur local

Stéphane a vérifié les trois emplacements, les compteurs de questions par passage et le mode « Avec renouvellement » sur les quiz 21, 22 et 23.

| Quiz local | Clé | Emplacement vérifié | Questions par passage |
| ---: | --- | --- | ---: |
| 21 | `module6_test1` | Après « quel que », avant « rémunérer » | 6 |
| 22 | `module6_test2` | Après « souci » / « soucis », avant « en l’occurrence » | 6 |
| 23 | `module6_general` | Après « leur » / « leurs », avant « Ma dictée après les cours » | 20 |

Stéphane a validé manuellement le parcours apprenant du quiz **21** uniquement.

## Base jetable

La prévisualisation avec `app:quiz:manifest` a laissé les 29 tables intactes. Le premier import a créé trois quiz en mode fixe, sans rattachement à un cours et avec un quota `NULL`. Le quiz témoin, sa question et ses deux propositions ont été conservés à l’identique.

La relecture en base a confirmé 32 questions initiales, 61 réserves et 186 propositions. Les deux tests intermédiaires comptaient respectivement 8 et 10 réserves. Le quiz général en comptait 43 et couvrait 18 thèmes. L’ordre des questions, leur contenu et les bonnes réponses correspondaient au manifeste.

Le second import a reconnu les 93 questions et laissé les 29 tables intactes. La prévisualisation de l’activation ciblait uniquement les trois nouveaux quiz.

## Qu’est-ce qui reste incertain ?

Le renouvellement et la reprise des quiz **22** et **23** restent à confirmer en navigateur local.
