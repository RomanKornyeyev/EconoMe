<?php

namespace App\Form;

use App\Entity\Account;
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

class TransactionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Atributos Stimulus para la sugerencia de categoría (solo al insertar).
        // El data-controller y el url-value van en el <form> desde la plantilla;
        // aquí se marcan los campos que el controlador observa y actualiza.
        $suggest = $options['suggest'];
        $nameAttr = ['class' => 'form-control'];
        $typeAttr = [];
        $categoryAttr = [];
        if ($suggest) {
            $nameAttr += [
                'data-category-suggest-target' => 'name',
                'data-action' => 'input->category-suggest#request',
            ];
            $typeAttr += [
                'data-category-suggest-target' => 'type',
                'data-action' => 'change->category-suggest#request',
            ];
            $categoryAttr += [
                'data-category-suggest-target' => 'category',
                'data-action' => 'change->category-suggest#onCategoryChange',
            ];
        }

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => [
                    'Gasto' => Transaction::TYPE_EXPENSE,
                    'Ingreso' => Transaction::TYPE_INCOME,
                ],
                'attr' => $typeAttr,
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Importe',
                'currency' => $options['currency'] ?? 'EUR',
                // Sin placeholder: la etiqueta flotante ocupa su sitio cuando el
                // campo está vacío, y el tema le pone el que necesita para que
                // :placeholder-shown funcione.
                'attr' => [
                    'pattern' => '[0-9]+([.,][0-9]{1,2})?',
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'attr' => $nameAttr,
            ])
            ->add('category', EntityType::class, [
                'label' => 'Categoría',
                'class' => Category::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Sin categoría',
                'attr' => $categoryAttr,
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
            ->add('date', DateType::class, [
                'label' => 'Fecha',
                'widget' => 'single_text',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Descripción',
                'required' => false,
                'attr' => ['rows' => 2, 'placeholder' => 'Descripción o notas adicionales...'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transaction::class,
            'currency' => 'EUR',
            // Activa la sugerencia automática de categoría (solo en inserción).
            'suggest' => false,
        ]);
        $resolver->setAllowedTypes('suggest', 'bool');
        $resolver->setRequired('account');
        $resolver->setAllowedTypes('account', Account::class);
    }
}
