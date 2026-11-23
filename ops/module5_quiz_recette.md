# Module 5 — recette locale des quiz

En navigateur, Stéphane a vérifié les emplacements et les compteurs ci-dessous. Le renouvellement était actif sur les trois quiz. Stéphane a testé manuellement le parcours apprenant sur le quiz local **18** uniquement.

| Quiz | Emplacement vérifié | Questions par passage (navigateur) | Réserves (import) |
| --- | --- | ---: | ---: |
| `module5_test1` | Après « je suis allé » / « j’ai été », avant « faire partie » / « tirer parti » | 6 | 8 |
| `module5_test2` | Après « développement », avant « les centres d’intérêt » | 6 | 8 |
| `module5_general` | Après « différend » / « différent », avant « Ma dictée après les cours » | 20 | 41 |

Sur base jetable, le premier import avec `app:quiz:manifest` a créé trois quiz en mode fixe, sans rattachement et avec quota `NULL`. La relecture en base a confirmé les 32 questions initiales, les 57 réserves et les 178 propositions. Le second import a ajouté zéro ligne. La prévisualisation de l’activation ciblait uniquement les trois nouveaux quiz.
