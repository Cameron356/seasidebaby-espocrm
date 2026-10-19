<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// "Add more hours" from a family's own page (FamilyPage.php).
//   POST /api/v1/Seaside/moreHours   form fields f, t (the family page link), plan (its id), hours
// No login: the family page link is the sign-in, checked exactly like the page itself.
// Records the request on the Postpartum plan (Hours to add, More hours = Requested). The office robot then
// prices it (scripts/seaside_pricing.py pp_topup_rate), sends bank and card invoices, and adds the hours to
// Hours bought when one is paid. 303s back to the family page.
// Installed to custom/Espo/Custom/Api/SeasideMoreHours.php by crm/install.py.
namespace Espo\Custom\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\EntryPoints\FamilyPage;
use Espo\ORM\EntityManager;

class SeasideMoreHours implements Action
{
    private const MIN = /*PP_MIN_HOURS*/8/*END*/;
    private const MAX = 600;

    public function __construct(private EntityManager $entityManager)
    {}

    public function process(Request $request): Response
    {
        $f = (string) ($_POST['f'] ?? '');
        $t = (string) ($_POST['t'] ?? '');
        $fam = FamilyPage::find($this->entityManager, $f, $t);
        if (!$fam) return $this->back('', '', 'link');
        $back = fn (string $msg) => $this->back($f, $t, $msg);

        $plan = $this->entityManager->getEntityById('CarePlan', (string) ($_POST['plan'] ?? ''));
        if (!$plan || $plan->get('familyId') !== $fam->getId()
            || !in_array($plan->get('status'), ['Scheduling', 'Active'], true) || (float) $plan->get('hoursBought') <= 0) {
            return $back('hoursplan');
        }
        if (in_array($plan->get('topupStatus'), ['Requested', 'Invoiced'], true)) return $back('hourswait');
        $raw = trim((string) ($_POST['hours'] ?? ''));
        if (!preg_match('/^\d{1,3}$/', $raw) || (int) $raw < self::MIN || (int) $raw > self::MAX) return $back('hoursbad');

        $plan->set(['topupHours' => (int) $raw, 'topupStatus' => 'Requested', 'topupRequestedAt' => gmdate('Y-m-d H:i:s'),
                    'topupRate' => null, 'topupTotal' => null, 'topupPayLink' => null, 'topupBankPayLink' => null,
                    'topupInvoiceId' => null, 'topupBankInvoiceId' => null]);
        $this->entityManager->saveEntity($plan);
        return $back('hours');
    }

    private function back(string $f, string $t, string $msg): Response
    {
        $url = '/?entryPoint=familyPage&f=' . rawurlencode($f) . '&t=' . rawurlencode($t) . '&m=' . $msg;
        $r = ResponseComposer::empty();
        $r->setStatus(303);
        $r->setHeader('Location', $url);
        return $r;
    }
}
