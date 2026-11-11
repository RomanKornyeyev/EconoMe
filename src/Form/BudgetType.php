<?php

namespace App\Form;

use App\Budget\BudgetPeriod;
use App\Entity\Account;
use App\Entity\Budget;
use App\Entity\Category;
use App\Enum\Frequency;
use App\Repository\CategoryRepository;
use App\Service\CategoryUsage;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Alta de un presupuesto (modal de la página Presupuestos).
 *
 * Sin data_class: el presupuesto no tiene campo de importe (son tramos) y su
 * vigencia se elige en periodos, así que lo crea BudgetController a partir de
 * estos datos.
 *
 * Inicio y fin se eligen como desplazamientos desde el periodo en curso (0 = el
 * en curso). Sus etiquetas dependen de la frecuencia: se pintan las de
 * `frequency` (la elegida) y las de todas van en `data-budget-labels`, para que
 * budget-modal las cambie sin pedir nada al servidor.
 */
class BudgetType extends AbstractType
{
    /** Periodos donde se puede empezar: el en curso y los dos siguientes. */
    public const START_CHOICES = 3;

    /** Periodos donde se puede terminar, contando desde el en curso. */
    public const END_CHOICES = 12;

    public function __construct(
        private CategoryUsage $categoryUsage,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Frequency $frequency */
        $frequency = $options['frequency'];
        $labels = self::periodLabels($options['today']);

        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Tipo',
                'choices' => [
                    'Límite de gasto' => Budget::TYPE_EXPENSE,
                    'Meta de ingreso' => Budget::TYPE_INCOME,
                ],
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Importe',
                'input' => 'string',
                'currency' => $options['currency'],
                'constraints' => [new Assert\NotBlank(message: 'Indica un importe.'), new Assert\Positive(message: 'El importe tiene que ser mayor que 0.')],
                // Sin placeholder: lo pone el tema para el flotante (ver TransactionType).
                'attr' => [
                    'pattern' => '[0-9]+([.,][0-9]{1,2})?',
                    'inputmode' => 'decimal',
                ],
            ])
            ->add('scope', ChoiceType::class, [
                'label' => '¿Sobre qué?',
                'expanded' => true,
                'choices' => [
                    'Una categoría' => 'category',
                    'Toda la cuenta' => 'account',
                ],
                'choice_attr' => fn () => ['data-action' => 'budget-modal#toggleScope'],
            ])
            ->add('category', EntityType::class, [
                'label' => 'Categoría',
                'class' => Category::class,
                'choice_label' => 'name',
                'required' => false,
                'placeholder' => 'Elige una categoría',
                // Agrupa las categorías según el tipo elegido (ver TransactionType).
                'attr' => ['data-controller' => 'category-order'],
                'query_builder' => fn (CategoryRepository $repo) => $repo->createQueryBuilder('c')
                    ->where('c.account = :account')
                    ->andWhere('c.parent IS NULL')
                    ->setParameter('account', $options['account'])
                    ->orderBy('c.name', 'ASC'),
                'choice_attr' => fn (Category $category) => [
                    'data-used-as' => $this->categoryUsage->usedAs($options['account'], $category->getId()),
                ],
            ])
            ->add('frequency', EnumType::class, [
                'label' => 'Frecuencia',
                'class' => Frequency::class,
                'choices' => Frequency::forBudgets(),
                'choice_label' => fn (Frequency $f) => $f->label(),
                'attr' => ['data-action' => 'budget-modal#relabel'],
            ])
            ->add('start', ChoiceType::class, [
                'label' => 'Empieza en',
                'choices' => range(0, self::START_CHOICES - 1),
                'choice_label' => fn (int $offset) => $labels[$frequency->value]['start'][$offset],
                'help' => 'Cuenta el periodo entero, también lo gastado antes de hoy. Los anteriores no se evalúan.',
                'attr' => ['data-budget-labels' => json_encode(array_map(fn ($l) => $l['start'], $labels), JSON_UNESCAPED_UNICODE)],
            ])
            ->add('end', ChoiceType::class, [
                'label' => 'Termina en',
                'required' => false,
                'placeholder' => 'Sin fecha de fin',
                'choices' => range(0, self::END_CHOICES - 1),
                'choice_label' => fn (int $offset) => $labels[$frequency->value]['end'][$offset],
                'help' => 'Opcional. Para algo puntual, como «Regalos» solo en diciembre.',
                'attr' => ['data-budget-labels' => json_encode(array_map(fn ($l) => $l['end'], $labels), JSON_UNESCAPED_UNICODE)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'currency' => 'EUR',
            'frequency' => Frequency::Monthly,
            'today' => new \DateTimeImmutable('today'),
            'constraints' => [new Assert\Callback(self::validate(...))],
        ]);
        $resolver->setRequired('account');
        $resolver->setAllowedTypes('account', Account::class);
        $resolver->setAllowedTypes('frequency', Frequency::class);
        $resolver->setAllowedTypes('today', \DateTimeImmutable::class);
    }

    /**
     * Etiquetas de inicio y fin para cada frecuencia, por desplazamiento desde el
     * periodo en curso.
     *
     * @return array<string, array{start: array<int, string>, end: array<int, string>}>
     */
    public static function periodLabels(\DateTimeImmutable $today): array
    {
        $labels = [];
        foreach (Frequency::forBudgets() as $frequency) {
            $current = BudgetPeriod::containing($frequency, $today);
            $start = $end = [];
            for ($i = 0; $i < self::END_CHOICES; $i++) {
                $label = $current->offset($i)->label();
                $end[$i] = $label;
                if ($i < self::START_CHOICES) {
                    $start[$i] = $label . ($i === 0 ? ' (en curso)' : '');
                }
            }
            $labels[$frequency->value] = ['start' => $start, 'end' => $end];
        }

        return $labels;
    }

    /** @param array<string, mixed>|null $data */
    public static function validate(?array $data, ExecutionContextInterface $context): void
    {
        if ($data === null) {
            return;
        }
        if (($data['scope'] ?? null) === 'category' && ($data['category'] ?? null) === null) {
            $context->buildViolation('Elige una categoría.')->atPath('[category]')->addViolation();
        }
        $end = $data['end'] ?? null;
        if ($end !== null && $end !== '' && (int) $end < (int) ($data['start'] ?? 0)) {
            $context->buildViolation('El fin no puede ser anterior al inicio.')->atPath('[end]')->addViolation();
        }
    }
}
