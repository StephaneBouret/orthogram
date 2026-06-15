<?php

namespace App\DataFixtures;

use App\Entity\Exercice;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

class ExerciceFixtures extends Fixture implements FixtureGroupInterface
{
    public const CLICK_WORDS_NOUNS_REFERENCE = 'exercice_click_words_nouns';

    public static function getGroups(): array
    {
        return ['exercice'];
    }

    public function load(ObjectManager $manager): void
    {
        $exercice = (new Exercice())
            ->setTitle('Ce qu’est un mot : reconnaître les noms')
            ->setInstruction('Clique sur tous les noms présents dans chaque phrase, puis valide tes réponses.')
            ->setType(Exercice::TYPE_CLICK_WORDS)
            ->setData([
                'sentences' => [
                    [
                        'id' => 's1',
                        'words' => [
                            ['id' => 's1_w1', 'text' => 'Si', 'isAnswer' => false],
                            ['id' => 's1_w2', 'text' => 'tu', 'isAnswer' => false],
                            ['id' => 's1_w3', 'text' => 'ne', 'isAnswer' => false],
                            ['id' => 's1_w4', 'text' => 'parviens', 'isAnswer' => false],
                            ['id' => 's1_w5', 'text' => 'pas', 'isAnswer' => false],
                            ['id' => 's1_w6', 'text' => 'à', 'isAnswer' => false],
                            ['id' => 's1_w7', 'text' => 'joindre', 'isAnswer' => false],
                            ['id' => 's1_w8', 'text' => 'Sarah', 'isAnswer' => true, 'explanation' => 'Sarah est un nom propre.'],
                            ['id' => 's1_w9', 'text' => 'par', 'isAnswer' => false],
                            ['id' => 's1_w10', 'text' => 'téléphone', 'isAnswer' => true, 'punctuationAfter' => ',', 'explanation' => 'Téléphone est un nom commun.'],
                            ['id' => 's1_w11', 'text' => 'envoie-lui', 'isAnswer' => false],
                            ['id' => 's1_w12', 'text' => 'un', 'isAnswer' => false],
                            ['id' => 's1_w13', 'text' => 'mail', 'isAnswer' => true, 'punctuationAfter' => '.', 'explanation' => 'Mail est un nom commun.'],
                        ],
                    ],
                    [
                        'id' => 's2',
                        'words' => [
                            ['id' => 's2_w1', 'text' => 'Hormis', 'isAnswer' => false],
                            ['id' => 's2_w2', 'text' => 'quelques', 'isAnswer' => false],
                            ['id' => 's2_w3', 'text' => 'jours', 'isAnswer' => true, 'explanation' => 'Jours est un nom commun.'],
                            ['id' => 's2_w4', 'text' => 'pluvieux', 'isAnswer' => false, 'punctuationAfter' => ','],
                            ['id' => 's2_w5', 'text' => 'ce', 'isAnswer' => false],
                            ['id' => 's2_w6', 'text' => 'fut', 'isAnswer' => false],
                            ['id' => 's2_w7', 'text' => 'un', 'isAnswer' => false],
                            ['id' => 's2_w8', 'text' => 'excellent', 'isAnswer' => false],
                            ['id' => 's2_w9', 'text' => 'séjour', 'isAnswer' => true, 'explanation' => 'Séjour est un nom commun.'],
                            ['id' => 's2_w10', 'text' => 'dans', 'isAnswer' => false],
                            ['id' => 's2_w11', 'text' => 'l’ensemble', 'isAnswer' => true, 'punctuationAfter' => '.', 'explanation' => 'Ensemble est un nom commun.'],
                        ],
                    ],
                    [
                        'id' => 's3',
                        'words' => [
                            ['id' => 's3_w1', 'text' => 'Retrouvez', 'isAnswer' => false],
                            ['id' => 's3_w2', 'text' => 'la', 'isAnswer' => false],
                            ['id' => 's3_w3', 'text' => 'copie', 'isAnswer' => true, 'explanation' => 'Copie est un nom commun.'],
                            ['id' => 's3_w4', 'text' => 'de', 'isAnswer' => false],
                            ['id' => 's3_w5', 'text' => 'la', 'isAnswer' => false],
                            ['id' => 's3_w6', 'text' => 'dernière', 'isAnswer' => false],
                            ['id' => 's3_w7', 'text' => 'commande', 'isAnswer' => true, 'explanation' => 'Commande est un nom commun.'],
                            ['id' => 's3_w8', 'text' => 'et', 'isAnswer' => false],
                            ['id' => 's3_w9', 'text' => 'déposez-la', 'isAnswer' => false],
                            ['id' => 's3_w10', 'text' => 'sur', 'isAnswer' => false],
                            ['id' => 's3_w11', 'text' => 'mon', 'isAnswer' => false],
                            ['id' => 's3_w12', 'text' => 'bureau', 'isAnswer' => true, 'punctuationAfter' => '.', 'explanation' => 'Bureau est un nom commun.'],
                        ],
                    ],
                    [
                        'id' => 's4',
                        'words' => [
                            ['id' => 's4_w1', 'text' => 'Café', 'isAnswer' => true, 'punctuationAfter' => ',', 'explanation' => 'Café est un nom commun.'],
                            ['id' => 's4_w2', 'text' => 'thé', 'isAnswer' => true, 'punctuationAfter' => ',', 'explanation' => 'Thé est un nom commun.'],
                            ['id' => 's4_w3', 'text' => 'viennoiseries', 'isAnswer' => true, 'explanation' => 'Viennoiseries est un nom commun.'],
                            ['id' => 's4_w4', 'text' => 'miniatures', 'isAnswer' => false, 'punctuationAfter' => ' :'],
                            ['id' => 's4_w5', 'text' => 'très', 'isAnswer' => false],
                            ['id' => 's4_w6', 'text' => 'bon', 'isAnswer' => false],
                            ['id' => 's4_w7', 'text' => 'accueil', 'isAnswer' => true, 'punctuationAfter' => '.', 'explanation' => 'Accueil est un nom commun.'],
                        ],
                    ],
                    [
                        'id' => 's5',
                        'words' => [
                            ['id' => 's5_w1', 'text' => 'M. Le Bihan', 'isAnswer' => true, 'explanation' => 'M. Le Bihan est un nom propre complet.'],
                            ['id' => 's5_w2', 'text' => 'a', 'isAnswer' => false],
                            ['id' => 's5_w3', 'text' => 'contacté', 'isAnswer' => false],
                            ['id' => 's5_w4', 'text' => 'l’agence', 'isAnswer' => true, 'explanation' => 'Agence est un nom commun.'],
                            ['id' => 's5_w5', 'text' => 'hier', 'isAnswer' => false],
                            ['id' => 's5_w6', 'text' => 'afin', 'isAnswer' => false],
                            ['id' => 's5_w7', 'text' => 'd’obtenir', 'isAnswer' => false],
                            ['id' => 's5_w8', 'text' => 'des', 'isAnswer' => false],
                            ['id' => 's5_w9', 'text' => 'informations', 'isAnswer' => true, 'explanation' => 'Informations est un nom commun.'],
                            ['id' => 's5_w10', 'text' => 'complémentaires', 'isAnswer' => false],
                            ['id' => 's5_w11', 'text' => 'sur', 'isAnswer' => false],
                            ['id' => 's5_w12', 'text' => 'les', 'isAnswer' => false],
                            ['id' => 's5_w13', 'text' => 'tarifs', 'isAnswer' => true, 'punctuationAfter' => '.', 'explanation' => 'Tarifs est un nom commun.'],
                        ],
                    ],
                ],
            ]);

        $manager->persist($exercice);
        $manager->flush();

        $this->addReference(self::CLICK_WORDS_NOUNS_REFERENCE, $exercice);
    }
}
