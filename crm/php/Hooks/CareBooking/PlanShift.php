<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Espo\Custom\Hooks\CareBooking;

use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * A postpartum shift that belongs to a Postpartum plan:
 *   before save  fills what Amanda would otherwise type: Hours from the start and end, the plan's
 *                Rate, and the money: inside a package it's "In package" (nothing to pay, no total);
 *                (a plan with no hours bought, which the office no longer makes, prices each shift).
 *   after save   a shift added, moved, re-timed, changed status or moved plan saves its plan (and the
 *                plan it left), so the plan recounts its hours (Hooks/CarePlan/Hours.php).
 */
class PlanShift implements BeforeSave, AfterSave, AfterRemove
{
    private const PP_MIN_HOURS = /*PP_MIN_HOURS*/8/*END*/;

    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $planId = $entity->get('carePlanId');
        if (!$planId) return;
        $plan = $this->entityManager->getEntityById('CarePlan', $planId);
        if (!$plan) return;
        if ($entity->get('dateStart') && $entity->get('dateEnd')
            && ($entity->isNew() || $entity->isAttributeChanged('dateStart') || $entity->isAttributeChanged('dateEnd') || !$entity->get('hours'))) {
            $h = (strtotime($entity->get('dateEnd') . ' UTC') - strtotime($entity->get('dateStart') . ' UTC')) / 3600;
            if ($h > 0) $entity->set('hours', round($h * 4) / 4);
        }
        if (!$entity->get('service')) $entity->set('service', 'pp');
        $entity->set(['rate' => $plan->get('rate'), 'rateUnit' => 'hour']);
        if ((float) $plan->get('hoursBought') > 0) {
            if (in_array($entity->get('payment'), [null, '', 'Unpaid'], true)) $entity->set('payment', 'In package');
            if ($entity->get('payment') === 'In package') $entity->set('total', null);
        } elseif ($entity->get('payment') !== 'Paid' && $entity->get('payment') !== 'Paid another way' && !$entity->get('stripeInvoiceId')) {
            if ($entity->get('payment') === 'In package') $entity->set('payment', 'Unpaid');
            $entity->set('total', max((float) $entity->get('hours'), self::PP_MIN_HOURS) * (float) $plan->get('rate'));
        }
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        $watch = ['carePlanId', 'status', 'hours', 'dateStart', 'dateEnd'];
        $changed = $entity->isNew();
        foreach ($watch as $a) $changed = $changed || $entity->isAttributeChanged($a);
        if ($changed) $this->recount([$entity->get('carePlanId'), $entity->getFetched('carePlanId')]);
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->recount([$entity->get('carePlanId')]);
    }

    /** @param array<?string> $ids */
    private function recount(array $ids): void
    {
        foreach (array_unique(array_filter($ids)) as $id) {
            $plan = $this->entityManager->getEntityById('CarePlan', $id);
            if ($plan) $this->entityManager->saveEntity($plan);
        }
    }
}
