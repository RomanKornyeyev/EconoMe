<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\RecurringTransaction;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class RecurringTransactionType extends AbstractType
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => ['placeholder' => 'Ej: Netflix, Alquiler, Nómina...'],
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
            ->add('dayOfExecution', IntegerType::class, [
                'label' => 'Día de ejecución',
                'attr' => ['min' => 1, 'max' => 31, 'placeholder' => '1-31'],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Categoría',
                'class' => Category::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Sin categoría',
                'query_builder' => function (CategoryRepository $repo) use ($user) {
                    return $repo->createQueryBuilder('c')
                        ->where('c.user = :user')
                        ->setParameter('user', $user)
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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecurringTransaction::class,
            'currency' => 'EUR',
        ]);
    }
}
