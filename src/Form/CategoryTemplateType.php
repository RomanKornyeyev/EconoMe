<?php

namespace App\Form;

use App\Entity\CategoryTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'Ej: Alimentación, Nómina...'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => [
                    'Gasto' => CategoryTemplate::TYPE_EXPENSE,
                    'Ingreso' => CategoryTemplate::TYPE_INCOME,
                ],
            ])
            ->add('color', ColorType::class, [
                'label' => 'Color',
                'required' => false,
                'row_attr' => [
                    'data-controller' => 'color-picker',
                    'data-color-picker-palette-value' => CategoryType::COLOR_PALETTE,
                ],
                'attr' => ['data-color-picker-target' => 'input'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategoryTemplate::class,
        ]);
    }
}
