<?php

namespace App\Tests\Form;

use App\Entity\Exercice;
use App\Form\ExerciceSentenceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class ExerciceSentenceValidationTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, bool}>
     */
    public static function cases(): iterable
    {
        yield 'aucune réponse' => [true, false, true];
        yield 'plusieurs bonnes réponses' => [false, true, true];
        yield 'contradiction' => [true, true, false];
    }

    #[DataProvider('cases')]
    public function testValidationBlocksFormWithoutChangingData(bool $noAnswer, bool $isAnswer, bool $valid): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
        $exercice = (new Exercice())->setTitle('Test')->setInstruction('Sélectionnez les réponses.')->setSentences([
            ['id' => 's1', 'noAnswer' => $noAnswer, 'noAnswerExplanation' => 'Explication.', 'words' => [
                ['id' => 'w1', 'text' => 'l’', 'isAnswer' => $isAnswer],
                ['id' => 'w2', 'text' => 'agence', 'joinPrevious' => true, 'isAnswer' => $isAnswer],
            ]],
        ]);
        $original = $exercice->getData();
        $violations = $validator->validate($exercice);
        self::assertCount($valid ? 0 : 1, $violations);
        if (!$valid) {
            self::assertSame('Une phrase marquée comme « aucune réponse » ne peut pas contenir de mot défini comme bonne réponse.', $violations->get(0)->getMessage());
            self::assertSame('sentences[0][noAnswer]', $violations->get(0)->getPropertyPath());
        }

        $factory = Forms::createFormFactoryBuilder()->addExtension(new ValidatorExtension($validator))->getFormFactory();
        $form = $factory->createBuilder(FormType::class, $exercice, ['data_class' => Exercice::class])
            ->add('sentences', CollectionType::class, ['entry_type' => ExerciceSentenceType::class, 'by_reference' => false])
            ->getForm();
        $sentences = $exercice->getSentences();
        $sentences[0]['noAnswer'] = $noAnswer ? '1' : null;
        foreach ($sentences[0]['words'] as &$word) {
            $word['isAnswer'] = $isAnswer ? '1' : null;
            $word['joinPrevious'] = ($word['joinPrevious'] ?? false) ? '1' : null;
        }
        unset($word);
        $form->submit(['sentences' => $sentences]);
        self::assertSame($valid, $form->isValid());
        self::assertSame($original, $exercice->getData());
        self::assertSame($original, (new Exercice())->setDataAsJson($exercice->getDataAsJson())->getData());
        if (!$valid) {
            self::assertCount(1, $form->get('sentences')->get('0')->get('noAnswer')->getErrors());
        }
    }
}
