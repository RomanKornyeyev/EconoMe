<?php

namespace App\Form;

use App\Entity\Category;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CategoryType extends AbstractType
{
    /** Paleta de 12 colores para el selector de categorías (JSON para el controlador Stimulus). */
    public const COLOR_PALETTE = '["#94a3b8","#f87171","#fb923c","#fbbf24","#a3e635","#34d399","#2dd4bf","#38bdf8","#60a5fa","#818cf8","#a78bfa","#e879f9","#f472b6"]';

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
                    'Gasto' => Category::TYPE_EXPENSE,
                    'Ingreso' => Category::TYPE_INCOME,
                ],
            ])
            ->add('color', ColorType::class, [
                'label' => 'Color',
                'required' => false,
                'row_attr' => [
                    'data-controller' => 'color-picker',
                    'data-color-picker-palette-value' => self::COLOR_PALETTE,
                ],
                'attr' => ['data-color-picker-target' => 'input'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Category::class,
        ]);
    }
}
