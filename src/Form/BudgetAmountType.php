<?php

namespace App\Form;

use App\Budget\BudgetPeriod;
use App\Entity\Budget;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cambiar el importe de un presupuesto (modal de edición), con sus tres
 * alcances: solo un periodo, desde un periodo o todo el historial.
 *
 * «Solo» y «desde» ofrecen el periodo en curso y los siguientes dentro de la
 * vigencia: lo cerrado no se toca. El importe empieza vacío, con el vigente como
 * placeholder: relleno, el resumen anunciaría cambios antes de tocar nada.
 */
class BudgetAmountType extends AbstractType
{
    public const SCOPE_ONCE = 'once';
    public const SCOPE_FROM = 'from';
    public const SCOPE_ALL = 'all';

    /** Periodos que se ofrecen, contando desde el en curso (o el inicial, si aún no ha empezado). */
    public const PERIOD_CHOICES = 12;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Budget $budget */
        $budget = $options['budget'];

        $builder
            ->add('amount', MoneyType::class, [
                'label' => 'Nuevo importe',
                'input' => 'string',
                'currency' => $budget->getAccount()->getCurrency(),
                'constraints' => [new Assert\NotBlank(message: 'Indica el importe nuevo.'), new Assert\Positive(message: 'El importe tiene que ser mayor que 0.')],
                'attr' => [
                    'pattern' => '[0-9]+([.,][0-9]{1,2})?',
                    'inputmode' => 'decimal',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('scope', ChoiceType::class, [
                'label' => '¿A qué periodos se aplica?',
                'expanded' => true,
                'choices' => [
                    'Solo un periodo' => self::SCOPE_ONCE,
                    'Desde un periodo en adelante' => self::SCOPE_FROM,
                    'Todo el historial' => self::SCOPE_ALL,
                ],
            ])
            ->add('period', ChoiceType::class, [
                'label' => 'Periodo',
                'choices' => self::periodChoices($budget, $options['today']),
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'today' => new \DateTimeImmutable('today'),
        ]);
        $resolver->setRequired('budget');
        $resolver->setAllowedTypes('budget', Budget::class);
        $resolver->setAllowedTypes('today', \DateTimeImmutable::class);
    }

    /** Primer periodo editable con «solo» o «desde»: el en curso, o el inicial si aún no ha empezado. */
    public static function firstEditable(Budget $budget, \DateTimeImmutable $today): BudgetPeriod
    {
        $current = $budget->periodAt($today);

        return $current->compare($budget->firstPeriod()) >= 0 ? $current : $budget->firstPeriod();
    }

    /**
     * @return array<string, string> etiqueta => clave del periodo ('Y-m-d')
     */
    public static function periodChoices(Budget $budget, \DateTimeImmutable $today): array
    {
        $current = $budget->periodAt($today);
        $last = $budget->lastPeriod();
        $choices = [];
        $period = self::firstEditable($budget, $today);
        for ($i = 0; $i < self::PERIOD_CHOICES; $i++, $period = $period->next()) {
            if ($last !== null && $period->compare($last) > 0) {
                break;
            }
            $label = ucfirst($period->label()) . ($period->equals($current) ? ' (en curso)' : '');
            $choices[$label] = $period->key();
        }

        return $choices;
    }
}
