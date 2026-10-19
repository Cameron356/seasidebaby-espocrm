<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Tonight board: <office>/?entryPoint=tonightBoard ("Tonight" in the menu).
// One phone screen with every booking happening now or starting in the next 36 hours, and what
// Amanda needs on the job: the address and how to get in, each child's allergies (and where the
// EpiPen is) and medications in bold, bedtimes, feeding for babies, who to call, who may and may
// NOT pick up, pool and pets, and for away/travel bookings the insurance and alarm/Wi-Fi.
// A postpartum shift also shows its Postpartum plan: night feeds, feeding plan, recovery notes, hours
// left in the package, and the last shift's notes (with private notes: this page needs a login).
// Login required (it shows medications, codes and phone numbers) plus read access to Bookings,
// Families and Children. Refreshes every 5 minutes. Same idea as Pack Leader's KennelBoard.php.
// Installed to custom/Espo/Custom/EntryPoints/TonightBoard.php by crm/install.py.
namespace Espo\Custom\EntryPoints;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class TonightBoard implements EntryPoint
{
    private const TZ = 'America/New_York';
    private const AHEAD_HOURS = 36;
    private const SERVICE = /*SERVICES*/[]/*END*/;   // generated from crm/model.py
    private const PLANS = /*PP_PLANS*/[]/*END*/;
    private const AWAY = ['getaway', 'week', 'travel'];

    public function __construct(private EntityManager $entityManager, private Acl $acl)
    {}

    public function run(Request $request, Response $response): void
    {
        foreach (['CareBooking', 'Account', 'Child'] as $scope) {
            if (!$this->acl->check($scope, 'read')) throw new Forbidden();
        }
        $tz = new \DateTimeZone(self::TZ);
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $tz);
        $nowUtc = (clone $now)->setTimezone($utc)->format('Y-m-d H:i:s');
        $until = (clone $now)->modify('+' . self::AHEAD_HOURS . ' hours')->setTimezone($utc)->format('Y-m-d H:i:s');

        $list = $this->entityManager->getRDBRepository('CareBooking')
            ->where(['status' => ['Confirmed', 'InProgress'], 'dateEnd>' => $nowUtc, 'dateStart<' => $until])
            ->order('dateStart', 'ASC')->limit(0, 50)->find();
        $nowCards = $nextCards = '';
        $n = 0;
        foreach ($list as $b) {
            $card = $this->card($b, $tz, $nowUtc);
            if ((string) $b->get('dateStart') <= $nowUtc) $nowCards .= $card; else $nextCards .= $card;
            $n++;
        }
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<meta http-equiv="refresh" content="300"><title>Tonight · Seaside</title><style>' . self::CSS . '</style></head><body>'
            . '<header><h1>Tonight</h1><p>' . $h($now->format('l, M j · g:i A')) . ' · updates every 5 minutes · '
            . '<a href="/#CareBooking">All bookings</a></p></header><main>'
            . ($nowCards ? '<h2>Happening now</h2>' . $nowCards : '')
            . ($nextCards ? '<h2>Coming up (next ' . self::AHEAD_HOURS . ' hours)</h2>' . $nextCards : '')
            . ($n ? '' : '<p class="empty">Nothing booked for the next ' . self::AHEAD_HOURS . ' hours.</p>')
            . '</main></body></html>';
        $response->setHeader('Content-Type', 'text/html; charset=utf-8');
        $response->setHeader('Cache-Control', 'no-store');
        $response->writeBody($html);
    }

    private function card(Entity $b, \DateTimeZone $tz, string $nowUtc): string
    {
        $h = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $g = fn (?Entity $e, string $k) => $e ? trim((string) $e->get($k)) : '';
        $fam = $b->get('familyId') ? $this->entityManager->getEntityById('Account', $b->get('familyId')) : null;
        $parent = $b->get('contactId') ? $this->entityManager->getEntityById('Contact', $b->get('contactId')) : null;
        $svc = (string) $b->get('service');
        $away = in_array($svc, self::AWAY, true);
        $a = (new \DateTime($b->get('dateStart'), new \DateTimeZone('UTC')))->setTimezone($tz);
        $z = (new \DateTime($b->get('dateEnd'), new \DateTimeZone('UTC')))->setTimezone($tz);
        $when = $a->format('D g:i A') . ' – ' . ($a->format('Ymd') === $z->format('Ymd') ? $z->format('g:i A') : $z->format('D g:i A'));
        $tel = function (string $label, string $num) use ($h) {
            if ($num === '') return '';
            $d = substr(preg_replace('/\D+/', '', $num), -10);
            $show = strlen($d) === 10 ? sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6)) : $num;
            return '<li><span>' . $h($label) . '</span> <a href="tel:+1' . $h($d) . '">' . $h($show) . '</a></li>';
        };
        $none = fn (string $v) => $v === '' || in_array(strtolower(trim($v, " .")), ['none', 'no', 'n/a', 'na', 'nope'], true);

        $out = '<article class="card' . ((string) $b->get('dateStart') <= $nowUtc ? ' now' : '') . '">'
            . '<div class="top"><div><h3>' . $h($fam ? $fam->get('name') : $b->get('name')) . '</h3>'
            . '<p class="svc">' . $h(self::SERVICE[$svc] ?? $svc) . ' · <b>' . $h($when) . '</b></p></div>'
            . '<a class="open" href="/#CareBooking/view/' . $h($b->getId()) . '">Open</a></div>';

        $addr = trim(implode(', ', array_filter([$g($fam, 'billingAddressStreet'), $g($fam, 'billingAddressCity'),
            trim($g($fam, 'billingAddressState') . ' ' . $g($fam, 'billingAddressPostalCode'))])), ', ');
        if ($addr !== '') {
            $out .= '<p class="addr"><a href="https://maps.google.com/?q=' . rawurlencode($addr) . '">' . $h($addr) . '</a></p>';
        }
        if ($g($fam, 'arrival') !== '') $out .= '<p class="note">Getting in: ' . $h($g($fam, 'arrival')) . '</p>';
        if ($g($fam, 'pickupNo') !== '') $out .= '<p class="alert">May NOT pick up: ' . $h($g($fam, 'pickupNo')) . '</p>';
        if ($g($fam, 'pool') === 'open') $out .= '<p class="alert">Pool is NOT fenced. Eyes on the kids near the water.</p>';
        if ($b->get('description')) $out .= '<p class="note">' . $h($b->get('description')) . '</p>';
        if ($b->get('carePlanId')) $out .= $this->plan($b, $h, $g);

        // Children
        $kids = $this->entityManager->getRDBRepository('CareBooking')->getRelation($b, 'children')->find();
        $out .= '<div class="kids">';
        foreach ($kids as $k) {
            $age = '';
            if ($k->get('birthDate')) {
                $d = (new \DateTime($k->get('birthDate')))->diff(new \DateTime('today'));
                $age = $d->y >= 2 ? $d->y . ' yrs' : ($d->y * 12 + $d->m >= 3 ? ($d->y * 12 + $d->m) . ' months' : intdiv($d->days, 7) . ' weeks');
            } elseif ($k->get('dueDate')) {
                $age = 'due ' . (new \DateTime($k->get('dueDate')))->format('M j');
            }
            $out .= '<section class="kid"><h4>' . $h($k->get('name')) . ($age ? ' <small>' . $h($age) . '</small>' : '') . '</h4><ul>';
            $al = $g($k, 'allergies');
            $out .= $none($al) ? '<li><span>Allergies</span> none</li>' : '<li class="alert"><span>Allergies</span> <b>' . $h($al) . '</b></li>';
            if ($g($k, 'epipen') !== '') $out .= '<li class="alert"><span>EpiPen / inhaler</span> <b>' . $h($g($k, 'epipen')) . '</b></li>';
            $md = $g($k, 'medications');
            if (!$none($md)) $out .= '<li class="med"><span>Medications</span> <b>' . $h($md) . '</b></li>';
            foreach (['conditions' => 'Needs', 'sleep' => 'Sleep', 'feeding' => 'Feeding', 'sleepWhere' => 'Sleeps in',
                         'food' => 'Food', 'comfort' => 'Comfort'] as $f => $l) {
                if ($g($k, $f) !== '') $out .= '<li><span>' . $l . '</span> ' . $h($g($k, $f)) . '</li>';
            }
            if ($away && $g($k, 'school') !== '') $out .= '<li><span>School</span> ' . $h($g($k, 'school')) . '</li>';
            $out .= '</ul></section>';
        }
        $out .= '</div>';

        // Who to call
        $out .= '<h4 class="sub">Who to call</h4><ul class="calls">'
            . $tel(trim(($parent ? $parent->get('firstName') : 'Parent') . ' (parent)'), $parent ? (string) $parent->get('phoneNumber') : $g($fam, 'phoneNumber'))
            . ($g($fam, 'p2Name') !== '' ? $tel($g($fam, 'p2Name') . ' (parent)', $g($fam, 'p2Phone')) : '')
            . $tel($g($fam, 'ec1Name') . ($g($fam, 'ec1Rel') ? ' (' . $g($fam, 'ec1Rel') . ')' : ''), $g($fam, 'ec1Phone'))
            . $tel($g($fam, 'ec2Name') . ($g($fam, 'ec2Rel') ? ' (' . $g($fam, 'ec2Rel') . ')' : ''), $g($fam, 'ec2Phone'))
            . $tel('Pediatrician: ' . $g($fam, 'pedName'), $g($fam, 'pedPhone'))
            . '</ul>';
        $more = [];
        if ($g($fam, 'hospital') !== '') $more[] = ['Urgent care / hospital', $g($fam, 'hospital')];
        if ($g($fam, 'pickupOk') !== '') $more[] = ['Also may pick up', $g($fam, 'pickupOk')];
        if ($g($fam, 'codeWord') !== '') $more[] = ['Code word', $g($fam, 'codeWord')];
        if ($g($fam, 'pets') === 'yes') $more[] = ['Pets', $g($fam, 'petNotes') ?: 'yes'];
        if ($g($fam, 'houseRules') !== '') $more[] = ['House rules', $g($fam, 'houseRules')];
        if ($away) {
            if ($g($fam, 'insCompany') !== '') $more[] = ['Insurance', $g($fam, 'insCompany') . ' · member ' . $g($fam, 'insMember')
                . ($g($fam, 'insGroup') ? ' · group ' . $g($fam, 'insGroup') : '') . ' · ' . $g($fam, 'insHolder')];
            if ($g($fam, 'alarmWifi') !== '') $more[] = ['Alarm / Wi-Fi', $g($fam, 'alarmWifi')];
            if ($g($fam, 'mayDrive') === 'yes') $more[] = ['Driving', trim($g($fam, 'whoseCar') . ' · ' . $g($fam, 'carSeats'), ' ·')];
        }
        if ($more) {
            $out .= '<ul class="more">';
            foreach ($more as [$l, $v]) $out .= '<li><span>' . $h($l) . '</span> ' . $h($v) . '</li>';
            $out .= '</ul>';
        }
        return $out . '</article>';
    }

    /** The Postpartum plan box on a postpartum shift. */
    private function plan(Entity $b, callable $h, callable $g): string
    {
        $p = $this->entityManager->getEntityById('CarePlan', $b->get('carePlanId'));
        if (!$p) return '';
        $hrs = fn ($x) => rtrim(rtrim(number_format((float) $x, 2), '0'), '.');
        $rows = [];
        $label = self::PLANS[$p->get('plan')] ?? 'Postpartum plan';
        if ((float) $p->get('hoursBought') > 0) {
            $label .= ' · ' . $hrs($p->get('hoursDone')) . ' of ' . $hrs($p->get('hoursBought')) . ' h done · ' . $hrs($p->get('hoursLeft')) . ' h left to schedule';
        }
        foreach (['nightFeeds' => 'Night feeds', 'feedingPlan' => 'Feeding plan', 'birthType' => 'Birth', 'supportNotes' => 'Recovery'] as $f => $l) {
            if ($g($p, $f) !== '') $rows[] = '<li><span>' . $l . '</span> ' . $h($g($p, $f)) . '</li>';
        }
        $ids = [];
        foreach ($this->entityManager->getRDBRepository('CareBooking')->select(['id'])
                     ->where(['carePlanId' => $p->getId(), 'id!=' => $b->getId()])->find() as $x) $ids[] = $x->getId();
        $last = $ids ? $this->entityManager->getRDBRepository('ShiftLog')
            ->where(['careBookingId' => $ids])->order('shiftStart', 'DESC')->findOne() : null;
        if ($last) {
            $bits = [];
            foreach (['feeds' => 'Feeds', 'sleep' => 'Sleep', 'soothing' => 'Soothing', 'supplies' => 'Running low', 'notesForParents' => 'Notes',
                         'privateNotes' => 'Private'] as $f => $l) {
                if ($g($last, $f) !== '') $bits[] = '<b>' . $l . ':</b> ' . nl2br($h($g($last, $f)));
            }
            if ($bits) $rows[] = '<li class="last"><span>Last shift</span> ' . implode('<br>', $bits) . '</li>';
        }
        return '<div class="pp"><h4 class="sub">' . $h($label) . '</h4>' . ($rows ? '<ul>' . implode('', $rows) . '</ul>' : '') . '</div>';
    }

    private const CSS = <<<'CSS'
*{box-sizing:border-box}
body{margin:0;background:#FBF6EE;color:#4A4440;font:16px/1.5 Nunito,system-ui,sans-serif}
a{color:#2F6F86}
header{background:#E3F2EE;padding:14px 16px}
header h1{margin:0;font:600 28px Georgia,serif}
header p{margin:2px 0 0;font-size:14px;color:#6B625C}
main{max-width:760px;margin:0 auto;padding:4px 12px 40px}
h2{font:600 20px Georgia,serif;margin:20px 4px 8px}
.empty{background:#fff;border-radius:14px;padding:18px;text-align:center;color:#6B625C;margin-top:20px}
.card{background:#fff;border:1px solid #EADFCF;border-radius:16px;padding:14px 14px 10px;margin-bottom:14px}
.card.now{border:2px solid #2F6F86}
.top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
h3{margin:0;font:600 21px Georgia,serif}
.svc{margin:2px 0 6px;font-size:14px}
.open{flex:none;background:#2F6F86;color:#fff;text-decoration:none;border-radius:999px;padding:6px 14px;font-weight:700;font-size:14px}
.addr{margin:4px 0;font-weight:700}
.note{margin:6px 0;background:#FBF6EE;border-radius:10px;padding:6px 10px;font-size:14px}
.alert{color:#7A2F29}
p.alert{background:#FBE1DE;border-radius:10px;padding:6px 10px;font-weight:700;margin:6px 0}
.kids{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));margin-top:8px}
.kid{background:#E3F2EE;border-radius:12px;padding:8px 12px}
h4{margin:0 0 4px;font-size:17px}
h4 small{font-weight:400;color:#6B625C}
.sub{margin:12px 0 2px;font-size:15px;color:#2F6F86}
ul{list-style:none;margin:0;padding:0}
li{padding:2px 0;font-size:15px}
li span{color:#6B625C;font-size:13px;display:inline-block;min-width:6.5rem}
li.alert b,li.med b{color:#7A2F29}
.calls a{font-weight:700;text-decoration:none}
.more{margin-top:8px;border-top:1px dashed #EADFCF;padding-top:6px}
.pp{background:#FBEDEB;border-radius:12px;padding:6px 12px 8px;margin-top:8px}
.pp .sub{color:#9A5752;margin-top:4px}
.pp li.last{font-size:14px;border-top:1px dashed #EADFCF;margin-top:4px;padding-top:6px}
CSS;
}
