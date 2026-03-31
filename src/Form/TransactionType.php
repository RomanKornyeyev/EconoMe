<?php

namespace App\Form;

use App\Entity\Category;
use App\Entity\Transaction;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class TransactionType extends AbstractType
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => [
                    'Gasto' => Transaction::TYPE_EXPENSE,
                    'Ingreso' => Transaction::TYPE_INCOME,
                ],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Importe',
                'currency' => $options['currency'] ?? 'EUR',
                'attr' => ['placeholder' => '0,00'],
            ])
            ->add('description', TextType::class, [
                'label' => 'Descripción',
                'attr' => ['placeholder' => 'Ej: Supermercado, Nómina...'],
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
            ->add('date', DateType::class, [
                'label' => 'Fecha',
                'widget' => 'single_text',
                'data' => new \DateTime(),
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Notas adicionales...'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
            'currency' => 'EUR',
        ]);
    }
}
