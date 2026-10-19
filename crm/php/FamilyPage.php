<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// A family's own page: <office>/?entryPoint=familyPage&f=<family id>&t=<token>
// The link is the sign-in: the Family's "Family page link" field (32 random hex characters),
// made by the office robot on approval and put in its emails. Clearing that field kills the
// link at once; the robot makes a new one next time. Same idea as Pack Leader's Client.php.
// Shows: their Postpartum plan first (hours used and left, next shift, and a "Baby's here!" form while
// the plan is waiting for baby: SeasideBabyArrived.php), what's coming up (bookings), the shift notes
// Amanda has shared (never her private notes), and a button to book again. No login, so it shows
// nothing else about the family.
// Installed to custom/Espo/Custom/EntryPoints/FamilyPage.php by crm/install.py.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class FamilyPage implements EntryPoint
{
    use NoAuth;

    private const TZ = 'America/New_York';
    private const SERVICE = /*SERVICES*/[]/*END*/;   // generated from crm/model.py
    private const NOTES = ['feeds' => 'Feeds', 'diapers' => 'Diapers', 'sleep' => 'Sleep', 'parentRest' => 'Your rest',
        'soothing' => 'Soothing and settling', 'supplies' => 'Running low', 'meals' => 'Meals and activities'];
    private const MESSAGES = ['baby' => 'Congratulations! Amanda knows baby is here and will be in touch about your first shift.',
        'date' => 'Please choose baby\'s date of birth (today or the last few weeks), then send again.',
        'hours' => 'Thank you! Your invoice for the extra hours will be emailed to you in a few minutes.',
        'hoursbad' => 'Please choose a number of hours from 8 to 600, then send again.',
        'hourswait' => 'You already have extra hours waiting to be paid. Pay that invoice first, or reply to any email from Amanda to change it.',
        'hoursplan' => 'Extra hours can be added once your plan is ready to schedule.'];
    private const HOURLY = /*PP_HOURLY*/60/*END*/;
    private const EARNED_FROM = /*PP_TOPUP_EARNED_FROM*/100/*END*/;
    private const MIN_HOURS = /*PP_MIN_HOURS*/8/*END*/;

    public function __construct(private EntityManager $entityManager, private Config $config)
    {}

    public function run(Request $request, Response $response): void
    {
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->setHeader('Referrer-Policy', 'no-referrer');
        $site = rtrim((string) (((array) $this->config->get('seasideSiteUrls'))[0] ?? 'https://example.com'), '/');
        $fam = self::find($this->entityManager, (string) $request->getQueryParam('f'), (string) $request->getQueryParam('t'));
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (!$fam) {
            $response->writeBody($this->page('Seaside Baby Nurturer', '<h1>This link doesn\'t work anymore</h1>'
                . '<p>Your family page link may have been reset. Ask Amanda for a new one, or '
                . '<a href="' . $h($site) . '/book/">send a new request</a>.</p>'));
            return;
        }
        $tz = new \DateTimeZone(self::TZ);
        $nowUtc = gmdate('Y-m-d H:i:s');
        $local = fn ($utc) => (new \DateTime($utc, new \DateTimeZone('UTC')))->setTimezone($tz);
        $span = function (Entity $b) use ($local) {
            $a = $local($b->get('dateStart')); $z = $local($b->get('dateEnd'));
            return $a->format('D, M j · g:i A') . ' – ' . ($a->format('Ymd') === $z->format('Ymd') ? $z->format('g:i A') : $z->format('D, M j · g:i A'));
        };

        $name = (string) $fam->get('name');
        $body = '<h1>Hi, ' . $h($name . (stripos($name, 'family') === false ? ' family' : '')) . '</h1>'
            . '<p class="small">This page is private to your family. Please don\'t share the link.</p>';
        $m = (string) $request->getQueryParam('m');
        if (isset(self::MESSAGES[$m])) $body .= '<p class="msg">' . $h(self::MESSAGES[$m]) . '</p>';
        $body .= $this->plans($fam, $nowUtc, $span, $h, (string) $request->getQueryParam('f'), (string) $request->getQueryParam('t'));

        $books = $this->entityManager->getRDBRepository('CareBooking')
            ->where(['familyId' => $fam->getId(), 'dateEnd>=' => $nowUtc, 'status!=' => 'Cancelled'])
            ->order('dateStart', 'ASC')->limit(0, 20)->find();
        $rows = '';
        foreach ($books as $b) {
            $kids = [];
            foreach ($this->entityManager->getRDBRepository('CareBooking')->getRelation($b, 'children')->find() as $k) $kids[] = $k->get('name');
            $pay = ['Unpaid' => '<span class="tag due">To pay</span>', 'Invoiced' => '<span class="tag due">To pay</span>',
                    'In package' => '<span class="tag paid">In your package</span>'][$b->get('payment')] ?? '<span class="tag paid">Paid</span>';
            $rows .= '<li><b>' . $h(self::SERVICE[$b->get('service')] ?? '') . '</b><br>' . $h($span($b))
                . ($kids ? '<br><span class="small">' . $h(implode(', ', $kids)) . '</span>' : '')
                . ($b->get('total') ? '<br>' . $h('$' . number_format((float) $b->get('total'), fmod((float) $b->get('total'), 1) ? 2 : 0)) . ' ' . $pay
                   : ($b->get('payment') === 'In package' ? '<br>' . $pay : ''))
                . ($b->get('payLink') && $b->get('payment') === 'Invoiced' ? ' <a href="' . $h($b->get('payLink')) . '">Pay now</a>' : '')
                . '</li>';
        }
        $body .= '<h2>Coming up</h2>' . ($rows ? '<ul class="list">' . $rows . '</ul>' : '<p class="small">Nothing booked right now.</p>');

        $logs = $this->entityManager->getRDBRepository('ShiftLog')
            ->where(['familyId' => $fam->getId(), 'shared' => true])
            ->order('shiftStart', 'DESC')->limit(0, 30)->find();
        $notes = '';
        foreach ($logs as $l) {
            $when = $l->get('shiftStart') ? $local($l->get('shiftStart'))->format('l, M j') : $l->get('name');
            $notes .= '<article class="log"><h3>' . $h($when) . '</h3>';
            foreach (self::NOTES as $f => $label) {
                if (trim((string) $l->get($f)) !== '') $notes .= '<p><span>' . $label . '</span>' . nl2br($h(trim((string) $l->get($f)))) . '</p>';
            }
            if (trim((string) $l->get('notesForParents')) !== '') $notes .= '<p class="note">' . nl2br($h(trim((string) $l->get('notesForParents')))) . '</p>';
            $notes .= '</article>';
        }
        if ($notes) $body .= '<h2>Notes from Amanda</h2>' . $notes;

        $body .= '<p class="cta"><a class="btn" href="' . $h($site) . '/book/">Book again</a></p>'
            . '<p class="small">Questions? Reply to any email from Amanda.</p>';
        $response->writeBody($this->page('Your family page · Seaside Baby Nurturer', $body));
    }

    /** "Your Postpartum Doula care": each plan that isn't finished or cancelled. */
    private function plans(Entity $fam, string $nowUtc, callable $span, callable $h, string $f, string $t): string
    {
        $out = '';
        $plans = $this->entityManager->getRDBRepository('CarePlan')
            ->where(['familyId' => $fam->getId(), 'status' => ['WaitingForBaby', 'Scheduling', 'Active']])
            ->order('createdAt', 'DESC')->limit(0, 3)->find();
        foreach ($plans as $p) {
            $bought = (float) $p->get('hoursBought');
            $done = (float) $p->get('hoursDone');
            $hrs = fn ($x) => rtrim(rtrim(number_format((float) $x, 2), '0'), '.');
            $card = '<section class="plan"><h2>Your Postpartum Doula care</h2>'
                . '<p class="plan-name">' . $h(self::PLANS[$p->get('plan')] ?? 'Postpartum plan') . '</p>';
            if ($bought > 0) {
                $left = max(0, $bought - $done);   // to the family, "left" is hours not had yet (a booked shift is still theirs)
                $pct = $bought ? min(100, round($done / $bought * 100)) : 0;
                $card .= '<div class="bar" role="img" aria-label="' . $h($hrs($done) . ' of ' . $hrs($bought) . ' hours used') . '"><span style="width:' . $pct . '%"></span></div>'
                    . '<p><b>' . $h($hrs($left)) . ' of ' . $h($hrs($bought)) . ' hours left</b>'
                    . ((float) $p->get('hoursScheduled') > 0 ? ' · ' . $h($hrs($p->get('hoursScheduled'))) . ' hours on the calendar' : '') . '</p>';
            } elseif ($done > 0) {
                $card .= '<p>' . $h($hrs($done)) . ' hours of care so far</p>';
            }
            $next = $this->entityManager->getRDBRepository('CareBooking')
                ->where(['carePlanId' => $p->getId(), 'dateEnd>=' => $nowUtc, 'status' => ['Confirmed', 'InProgress']])
                ->order('dateStart', 'ASC')->findOne();
            if ($next) $card .= '<p>Next shift: <b>' . $h($span($next)) . '</b></p>';
            if ($p->get('status') === 'WaitingForBaby') {
                $today = (new \DateTime('now', new \DateTimeZone(self::TZ)))->format('Y-m-d');
                $card .= '<form class="baby" method="post" action="/api/v1/Seaside/babyArrived">'
                    . '<h3>Has baby arrived?</h3><p class="small">Tell Amanda right away, and she\'ll set up your first shift.</p>'
                    . '<input type="hidden" name="f" value="' . $h($f) . '"><input type="hidden" name="t" value="' . $h($t) . '">'
                    . '<label>Date of birth <input type="date" name="birthDate" value="' . $h($today) . '" max="' . $h($today) . '" required></label>'
                    . '<label>Baby\'s name (if you\'d like) <input type="text" name="name" maxlength="80"></label>'
                    . '<button class="btn" type="submit">Baby\'s here!</button></form>';
            } elseif ($p->get('status') === 'Scheduling' && !$next) {
                $card .= '<p class="small">Amanda will be in touch to set up your shifts.</p>';
            }
            $card .= $this->payButtons($h, 'Your invoice', $p->get('payment') === 'Invoiced', $p->get('bankPayLink'), $p->get('payLink'));
            $card .= $this->moreHours($p, $h, $hrs, $f, $t);
            $out .= $card . '</section>';
        }
        return $out;
    }

    private const PLANS = /*PP_PLANS*/[]/*END*/;   // generated from scripts/seaside_pricing.py

    /** Two buttons for an open bill: bank (no fee) and card (3% fee). */
    private function payButtons(callable $h, string $title, bool $open, $bank, $card): string
    {
        if (!$open || (!$bank && !$card)) return '';
        $out = '<p><b>' . $h($title) . ':</b> ';
        if ($bank) $out .= '<a class="btn small-btn" href="' . $h($bank) . '">Pay by bank (no fee)</a> ';
        if ($card) $out .= '<a class="btn small-btn alt" href="' . $h($card) . '">Pay by card (3% fee)</a>';
        return $out . '</p>';
    }

    /** "Add more hours": the request in progress, or the form, with the rate they earned. */
    private function moreHours(Entity $p, callable $h, callable $hrs, string $f, string $t): string
    {
        if ((float) $p->get('hoursBought') <= 0 || !in_array($p->get('status'), ['Scheduling', 'Active'], true)) return '';
        $st = (string) $p->get('topupStatus');
        if ($st === 'Requested') {
            return '<p class="small">Your invoice for ' . $h($hrs($p->get('topupHours'))) . ' more hours is on its way by email.</p>';
        }
        if ($st === 'Invoiced') {
            return $this->payButtons($h, $hrs($p->get('topupHours')) . ' more hours', true, $p->get('topupBankPayLink'), $p->get('topupPayLink'));
        }
        $rate = (float) $p->get('rate');
        $money = fn ($x) => '$' . rtrim(rtrim(number_format((float) $x, 2), '0'), '.');
        $rates = $rate > 0 && $rate < self::HOURLY
            ? 'Your rate: ' . $money($rate) . ' an hour when you add ' . self::EARNED_FROM . ' hours or more (you earned it with your first package); '
              . $money(self::HOURLY) . ' an hour for fewer.'
            : $money(self::HOURLY) . ' an hour; 100, 200 or 300 hours get package rates.';
        return '<form class="baby" method="post" action="/api/v1/Seaside/moreHours">'
            . '<h3>Add more hours</h3><p class="small">' . $h($rates) . ' You can pay from your bank account with no fee, or by card.</p>'
            . '<input type="hidden" name="f" value="' . $h($f) . '"><input type="hidden" name="t" value="' . $h($t) . '">'
            . '<input type="hidden" name="plan" value="' . $h($p->getId()) . '">'
            . '<label>How many hours? <input type="number" name="hours" min="' . self::MIN_HOURS . '" max="600" step="1" value="'
            . self::EARNED_FROM . '" required></label>'
            . '<button class="btn" type="submit">Send me the invoice</button></form>';
    }

    /** The family this link belongs to, or null (bad or reset link). */
    public static function find(EntityManager $em, string $id, string $token): ?Entity
    {
        if (!preg_match('/^[a-f0-9]{17,24}$/', $id) || !preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $f = $em->getEntityById('Account', $id);
        if (!$f) return null;
        parse_str((string) parse_url((string) $f->get('familyLink'), PHP_URL_QUERY), $q);
        return ($q['f'] ?? '') === $id && is_string($q['t'] ?? null) && hash_equals($q['t'], $token) ? $f : null;
    }

    private function page(string $title, string $body): string
    {
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . htmlspecialchars($title) . '</title>'
            . '<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600&family=Nunito:wght@400;700&display=swap" rel="stylesheet">'
            . '<style>' . self::CSS . '</style></head><body><header><div class="brand">Seaside Baby Nurturer</div>'
            . '<div class="tag-line">Compassionate care · Peace of mind</div></header><main>' . $body . '</main>'
            . '<footer>Seaside Baby Nurturer · Tampa Bay, Florida</footer></body></html>';
    }

    private const CSS = <<<'CSS'
*{box-sizing:border-box}
body{margin:0;background:#FBF6EE;color:#4A4440;font:17px/1.6 Nunito,system-ui,sans-serif}
a{color:#2F6F86}
header{background:#E3F2EE;padding:18px 16px;text-align:center}
.brand{font:600 26px "Cormorant Garamond",Georgia,serif}
.tag-line{font-size:11px;letter-spacing:.25em;text-transform:uppercase;color:#2F6F86;font-weight:700}
main{max-width:640px;margin:0 auto;padding:8px 16px 32px}
h1{font:600 32px/1.15 "Cormorant Garamond",Georgia,serif;margin:22px 0 4px}
h2{font:600 24px "Cormorant Garamond",Georgia,serif;margin:26px 0 8px}
h3{font:600 20px "Cormorant Garamond",Georgia,serif;margin:0 0 6px;color:#2F6F86}
.small{color:#6B625C;font-size:14px}
.list{list-style:none;margin:0;padding:0}
.list li{background:#fff;border:1px solid #EADFCF;border-radius:14px;padding:12px 14px;margin-bottom:10px}
.tag{font-size:12px;font-weight:700;border-radius:999px;padding:2px 9px;margin-left:4px}
.due{background:#FBE7B5}.paid{background:#E3F2EE;color:#2F6F86}
.log{background:#fff;border:1px solid #EADFCF;border-radius:14px;padding:12px 14px;margin-bottom:10px}
.log p{margin:4px 0}
.log span{display:block;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6B625C;font-weight:700}
.log .note{background:#E3F2EE;border-radius:10px;padding:8px 10px;margin-top:8px}
.cta{text-align:center;margin:28px 0 8px}
.msg{background:#E3F2EE;border-radius:12px;padding:10px 14px;font-weight:700;color:#2F6F86}
.plan{background:#fff;border:1px solid #EADFCF;border-radius:16px;padding:4px 16px 12px;margin:18px 0}
.plan h2{margin-top:14px}
.plan-name{margin:0 0 8px;color:#9A5752;font-weight:700}
.bar{height:12px;background:#E3F2EE;border-radius:999px;overflow:hidden}
.bar span{display:block;height:100%;background:#2F6F86}
.baby{background:#FBF6EE;border-radius:12px;padding:10px 14px;margin-top:10px}
.baby label{display:block;margin:8px 0;font-weight:700;font-size:15px}
.small-btn{padding:8px 16px;font-size:15px;margin:4px 4px 0 0}
.btn.alt{background:#fff;color:#2F6F86;border:2px solid #2F6F86}
.baby input[type=date],.baby input[type=text],.baby input[type=number]{display:block;width:100%;font:inherit;padding:8px 10px;border:1px solid #EADFCF;border-radius:10px;margin-top:4px}
.baby .btn{border:0;cursor:pointer;font:inherit;font-weight:700;margin-top:6px}
.btn{display:inline-block;background:#2F6F86;color:#fff;text-decoration:none;font-weight:700;border-radius:999px;padding:12px 30px}
footer{text-align:center;color:#6B625C;font-size:13px;padding:16px;background:#E3F2EE}
CSS;
}
