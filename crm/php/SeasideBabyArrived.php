<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// "Baby's here!" from a family's own page (FamilyPage.php).
//   POST /api/v1/Seaside/babyArrived   form fields f, t (the family page link), birthDate, name (optional)
// No login: the family page link is the sign-in, checked exactly like the page itself.
// Finds the family's Postpartum plan that is Waiting for baby, sets the baby's date of birth (and name,
// if the baby was still "Baby"), moves the plan to Ready to schedule and stamps Family said baby arrived.
// The office robot then emails Amanda at once (babyAlertSentAt). 303s back to the family page.
// Installed to custom/Espo/Custom/Api/SeasideBabyArrived.php by crm/install.py.
namespace Espo\Custom\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\EntryPoints\FamilyPage;
use Espo\ORM\EntityManager;

class SeasideBabyArrived implements Action
{
    public function __construct(private EntityManager $entityManager)
    {}

    public function process(Request $request): Response
    {
        $f = (string) ($_POST['f'] ?? '');
        $t = (string) ($_POST['t'] ?? '');
        $fam = FamilyPage::find($this->entityManager, $f, $t);
        if (!$fam) return $this->back('', '', 'link');
        $back = fn (string $msg) => $this->back($f, $t, $msg);

        $date = (string) ($_POST['birthDate'] ?? '');
        $d = \DateTime::createFromFormat('!Y-m-d', $date, new \DateTimeZone('America/New_York'));
        $today = new \DateTime('today', new \DateTimeZone('America/New_York'));
        if (!$d || $d->format('Y-m-d') !== $date || $d > (clone $today)->modify('+1 day') || $d < (clone $today)->modify('-90 days')) {
            return $back('date');
        }
        $plan = $this->entityManager->getRDBRepository('CarePlan')
            ->where(['familyId' => $fam->getId(), 'status' => 'WaitingForBaby'])
            ->order('createdAt', 'DESC')->findOne();
        if (!$plan) return $back('baby');

        if ($plan->get('babyId') && ($baby = $this->entityManager->getEntityById('Child', $plan->get('babyId')))) {
            $baby->set(['birthDate' => $date, 'dueDate' => null]);
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
            if ($name !== '' && in_array(strtolower((string) $baby->get('name')), ['baby', ''], true)) $baby->set('name', $name);
            $this->entityManager->saveEntity($baby);
        }
        $plan->set(['status' => 'Scheduling', 'babyArrivedAt' => gmdate('Y-m-d H:i:s')]);
        $this->entityManager->saveEntity($plan);
        return $back('baby');
    }

    private function back(string $f, string $t, string $msg): Response
    {
        // The office is served from the root of its own host (staging :8220, office.example.com).
        $url = '/?entryPoint=familyPage&f=' . rawurlencode($f) . '&t=' . rawurlencode($t) . '&m=' . $msg;
        $r = ResponseComposer::empty();
        $r->setStatus(303);
        $r->setHeader('Location', $url);
        return $r;
    }
}
