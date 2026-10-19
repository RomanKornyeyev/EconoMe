<?php

namespace App\Form;

use App\Entity\CategoryTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Se pinta solo en el modal de categorías (category/_form.html.twig): no hay
 * vista de página completa. Si añades o quitas un campo, tócalo ahí.
 */
class CategoryTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'Ej: Alimentación, Nómina...'],
                // Vacío llega como '' y no como null: así lo frena el NotBlank de
                // la entidad con su mensaje, en vez de reventar en setName(string).
                'empty_data' => '',
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
