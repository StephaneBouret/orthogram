<?php

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TogglePasswordTypeExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [PasswordType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'toggle' => false,
            'hidden_label' => 'Masquer',
            'visible_label' => 'Afficher',
            'button_classes' => ['toggle-password-button'],
            'toggle_container_classes' => ['toggle-password-container'],
        ]);

        $resolver->setAllowedTypes('toggle', 'bool');
        $resolver->setAllowedTypes('hidden_label', ['string', 'null']);
        $resolver->setAllowedTypes('visible_label', ['string', 'null']);
        $resolver->setAllowedTypes('button_classes', 'string[]');
        $resolver->setAllowedTypes('toggle_container_classes', 'string[]');
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        if (!$options['toggle']) {
            return;
        }

        array_splice($view->vars['block_prefixes'], -1, 0, 'toggle_password');

        $view->vars['attr']['data-controller'] = trim(sprintf(
            '%s toggle-password',
            $view->vars['attr']['data-controller'] ?? ''
        ));
        $view->vars['attr']['data-toggle-password-visible-label-value'] = $options['visible_label'];
        $view->vars['attr']['data-toggle-password-hidden-label-value'] = $options['hidden_label'];
        $view->vars['attr']['data-toggle-password-button-classes-value'] = json_encode($options['button_classes'], JSON_THROW_ON_ERROR);
        $view->vars['toggle_container_classes'] = $options['toggle_container_classes'];
    }
}
