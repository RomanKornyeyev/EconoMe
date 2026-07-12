<?php

namespace App\Form;

use App\Entity\Account;
use App\Entity\Category;
use App\Entity\RecurringTransaction;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RecurringTransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'Ej: Netflix, Alquiler, Nómina...'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Descripción o notas adicionales...'],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => [
                    'Gasto' => RecurringTransaction::TYPE_EXPENSE,
                    'Ingreso' => RecurringTransaction::TYPE_INCOME,
                ],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Importe',
                'currency' => $options['currency'] ?? 'EUR',
                'attr' => [
                    'placeholder' => '0,00',
                    'pattern' => '[0-9]+([.,][0-9]{1,2})?',
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('frequency', ChoiceType::class, [
                'label' => 'Frecuencia',
                'choices' => [
                    'Diario' => RecurringTransaction::FREQ_DAILY,
                    'Semanal' => RecurringTransaction::FREQ_WEEKLY,
                    'Mensual' => RecurringTransaction::FREQ_MONTHLY,
                    'Anual' => RecurringTransaction::FREQ_YEARLY,
                ],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Categoría',
                'class' => Category::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Sin categoría',
                'query_builder' => function (CategoryRepository $repo) use ($options) {
                    return $repo->createQueryBuilder('c')
                        ->where('c.account = :account')
                        ->setParameter('account', $options['account'])
                        ->orderBy('c.type', 'ASC')
                        ->addOrderBy('c.name', 'ASC');
                },
                'group_by' => function (Category $category) {
                    return $category->getType() === 'expense' ? 'Gastos' : 'Ingresos';
                },
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Fecha de inicio',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Fecha de fin',
                'widget' => 'single_text',
                'required' => false,
            ])
        ;

        if ($options['is_edit']) {
            $builder->add('applyToGenerated', CheckboxType::class, [
                'label' => 'Aplicar los cambios de importe, nombre, descripción, tipo y categoría a los movimientos anteriores ya generados',
                'help' => 'Si lo marcas, se sobrescribirán también las ediciones manuales que hayas hecho en esos movimientos.',
                'mapped' => false,
                'required' => false,
                'label_attr' => ['class' => 'checkbox-switch'],
                'attr' => ['role' => 'switch'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecurringTransaction::class,
            'currency' => 'EUR',
            'is_edit' => false,
        ]);
        $resolver->setRequired('account');
        $resolver->setAllowedTypes('account', Account::class);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
