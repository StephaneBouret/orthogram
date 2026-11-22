# Module 4 — recette locale des quiz

Stéphane a vérifié l’emplacement et le compteur de questions par passage de chacun des trois quiz. Tous trois affichaient le mode **« Avec renouvellement »**. Les effectifs de réserve proviennent du manifeste et ont été contrôlés par les tests d’import.

| Quiz | Emplacement dans le module | Questions par passage | Réserves |
| --- | --- | ---: | ---: |
| `module4_test1` | Après « la » / « l’a » / « là », avant « chiffre d’affaires » et « appel d’offres » | 7 | 11 |
| `module4_test2` | Après « accueil » / « acceuil », avant les verbes construits avec « mettre » au présent de l’indicatif | 6 | 9 |
| `module4_general` | Après « Les adjectifs de couleur (2) », avant « Ma dictée après les cours » | 20 | 37 |

**Parcours apprenant.** Stéphane a effectué la recette manuelle du parcours sur le quiz local **16** uniquement. Les deux autres parcours disposent d’une couverture automatisée.

**Contrôles automatisés.** Sur bases jetables, PHPUnit vérifie pour les trois quiz la création en mode fixe, sans rattachement et avec quota `NULL`, puis l’activation séparée du renouvellement. Les tests contrôlent l’ordre du premier passage, la reprise après une réponse, les effectifs par thème au renouvellement et la conservation des historiques. Le premier import crée 90 questions et 180 propositions. Un second import fait zéro ajout. Une collision ou une retouche en base ou dans le manifeste produit un écart et bloque l’écriture.

Pour prévisualiser un manifeste avec la commande générique `app:quiz:manifest`, cette recette crée une base jetable, affiche le plan et supprime la base.

```powershell
php tests/manual/preview_quiz_manifest.php resources/quiz/module4/module4_quiz_manifest.json
```
