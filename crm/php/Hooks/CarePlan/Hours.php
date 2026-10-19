<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Espo\Custom\Hooks\CarePlan;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;

/**
 * Keeps a Postpartum plan's hours right. Every save recounts its shifts (Bookings linked to it):
 *   Hours done      = shifts marked Done
 *   Hours scheduled = shifts still to come (Requested, Confirmed, In progress)
 *   Hours left      = hours bought - scheduled - done   (every plan has hours bought up front)
 * and moves the status along: Ready to schedule / Waiting for baby -> Active once a shift exists,
 * Active -> Finished when a package's hours are all done (and back, if hours are added).
 * Cancelled is never changed. A shift that changes saves its plan (Hooks/CareBooking/PlanShift.php);
 * linking or unlinking a shift from the plan's panel lands here too. Same idea as Pack Leader's
 * Hooks/LessonPackage/Counts.php. Installed by crm/install.py (generated tree from crm/model.py).
 */
class Hours implements BeforeSave, AfterRelate, AfterUnrelate
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $done = $scheduled = 0.0;
        if (!$entity->isNew() && $entity->hasId()) {
            $shifts = $this->entityManager->getRDBRepository('CareBooking')
                ->where(['carePlanId' => $entity->getId()])->find();
            foreach ($shifts as $s) {
                $h = (float) $s->get('hours');
                $st = (string) $s->get('status');
                if ($st === 'Done') $done += $h;
                elseif (in_array($st, ['Requested', 'Confirmed', 'InProgress'], true)) $scheduled += $h;
            }
        }
        $bought = (float) $entity->get('hoursBought');
        $isPackage = $bought > 0;
        $entity->set([
            'hoursDone' => $done,
            'hoursScheduled' => $scheduled,
            'hoursLeft' => $isPackage ? $bought - $scheduled - $done : null,
        ]);

        $status = (string) $entity->get('status');
        if ($status === 'Cancelled') return;
        if ($isPackage && $done >= $bought) {
            $entity->set('status', 'Finished');
        } elseif ($status === 'Finished' || (in_array($status, ['Scheduling', 'WaitingForBaby'], true) && $done + $scheduled > 0)) {
            $entity->set('status', 'Active');
        }
    }

    public function afterRelate(Entity $entity, string $relationName, Entity $relatedEntity, array $columnData, RelateOptions $options): void
    {
        if ($relationName === 'shifts') $this->entityManager->saveEntity($entity);
    }

    public function afterUnrelate(Entity $entity, string $relationName, Entity $relatedEntity, UnrelateOptions $options): void
    {
        if ($relationName === 'shifts') $this->entityManager->saveEntity($entity);
    }
}
