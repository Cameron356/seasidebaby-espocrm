<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Receives the public booking form (site/book.html).
//   POST /api/v1/Seaside/request   multipart, no login (route noAuth)
// Creates one Lead ("Request", status New) and one RequestChild per child, with the insurance
// card photos attached. Then 303s back to the site: /book/?done=1, or /book/?err=<what's missing>.
// The office robot sends the "we got your request" email and takes it from there.
//
// Same approach as Pack Leader's BookingSubmit.php. Differences: the form is on another site
// (example.com, a Worker), so it posts across origins as a plain form (no CORS needed)
// and comes back to whichever allowed site it came from (config seasideSiteUrls).
//
// The allowed fields, their lengths and their options are generated from crm/model.py by
// crm/install.py (FIELDS / CHILD_FIELDS below), so the form, the office and this check agree.
// Bots: hidden "website" field and a 4-second minimum fill time; either one = fake success.
namespace Espo\Custom\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class SeasideRequest implements Action
{
    protected const MIN_SECONDS = 4;
    protected const MAX_KIDS = 10;
    protected const MAX_FILE = 10 * 1024 * 1024;
    protected const TZ = 'America/New_York';
    protected const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
        'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];
    protected const AWAY = ['getaway', 'week'];
    protected const BABY_COUNT = ['1' => 'One baby', '2' => 'Twins', '3' => 'Three or more'];

    /** name => [type, maxLength, options|null]. GENERATED from crm/model.py. */
    protected const FIELDS = /*FIELDS*/[]/*END*/;
    protected const CHILD_FIELDS = /*CHILD_FIELDS*/[]/*END*/;

    public function __construct(protected EntityManager $entityManager, protected Config $config)
    {}

    public function process(Request $request): Response
    {
        $p = $_POST;
        $site = $this->siteUrl($request);

        $t = (int) ($p['startedAt'] ?? 0) / 1000;
        if (trim((string) ($p['website'] ?? '')) !== '' || $t <= 0 || time() - $t < self::MIN_SECONDS) {
            return $this->go($site . '/book/?done=1');
        }

        $s = fn ($k, $max = 150) => mb_substr(trim((string) ($p[$k] ?? '')), 0, $max);
        $svc = $s('service');
        $kids = is_array($p['kids'] ?? null) ? array_slice(array_values(array_filter($p['kids'],
            fn ($k) => is_array($k) && trim((string) ($k['firstName'] ?? '')) !== '')), 0, self::MAX_KIDS) : [];

        $missing = $this->problems($p, $s, $svc, $kids);
        if ($missing) {
            return $this->go($site . '/book/?err=' . rawurlencode('Please add ' . implode(', ', $missing) . ', then send again.'));
        }

        $lead = $this->entityManager->getNewEntity('Lead');
        $lead->set('status', 'New');
        $lead->set('source', 'Web Site');
        $lead->set('firstName', $s('p1First', 100));
        $lead->set('lastName', $s('p1Last', 100));
        $lead->set('emailAddress', $s('p1Email', 150));
        $lead->set('phoneNumber', self::phone($s('p1Phone', 40)));
        $lead->set('addressStreet', $s('address', 255));
        if ($s('message', 5000) !== '') $lead->set('description', $s('message', 5000));
        // Everything else whose name matches a Request field. baby[x] -> babyX.
        $in = $p;
        foreach ((array) ($p['baby'] ?? []) as $k => $v) $in['baby' . ucfirst((string) $k)] = $v;
        if (isset($in['babyCount'])) $in['babyCount'] = self::BABY_COUNT[$in['babyCount']] ?? '';
        foreach (self::FIELDS as $name => [$type, $max, $options]) {
            if (in_array($name, ['status', 'family', 'officeRobot', 'returning', 'agreedRate', 'priceOverride',
                    'discount', 'noteToClient', 'ppPlan', 'ppHours'], true) || !array_key_exists($name, $in)) continue;
            $v = $this->value($name, $in[$name], $type, $max, $options);
            if ($v !== null) $lead->set($name, $v);
        }
        $this->entityManager->saveEntity($lead);

        foreach (['insCardFront', 'insCardBack'] as $field) {
            $id = $this->file($field, $lead);
            if ($id !== null) $lead->set($field . 'Id', $id);
        }
        if ($lead->isAttributeChanged('insCardFrontId') || $lead->isAttributeChanged('insCardBackId')) {
            $this->entityManager->saveEntity($lead);
        }

        foreach ($kids as $k) {
            $c = $this->entityManager->getNewEntity('RequestChild');
            $c->set('name', mb_substr(trim((string) $k['firstName']), 0, 80));
            $c->set('requestId', $lead->getId());
            foreach (self::CHILD_FIELDS as $name => [$type, $max, $options]) {
                if ($name === 'name' || !array_key_exists($name, $k)) continue;
                $v = $this->value($name, $k[$name], $type, $max, $options);
                if ($v !== null) $c->set($name, $v);
            }
            $this->entityManager->saveEntity($c);
        }

        return $this->go($site . '/book/?done=1');
    }

    /** What the form still needs, in words. Mirrors the required fields in site/book.html. */
    protected function problems(array $p, callable $s, string $svc, array $kids): array
    {
        $out = [];
        $need = function (string $k, string $label) use ($s, &$out) { if ($s($k) === '') $out[] = $label; };
        $away = in_array($svc, self::AWAY, true);
        $options = array_keys(self::FIELDS['service'][2] ?? []);
        if (!in_array($svc, $options, true)) return ['the kind of care you need'];

        $need('p1First', 'your first name'); $need('p1Last', 'your last name');
        if (!filter_var($s('p1Email'), FILTER_VALIDATE_EMAIL)) $out[] = 'a valid email';
        if (!self::isUsPhone($s('p1Phone'))) $out[] = 'a 10-digit US mobile number';
        $need('contactPref', 'the best way to reach you'); $need('address', 'your home address');
        $need('ec1Name', 'an emergency contact'); $need('ec1Rel', 'their relationship');
        if (!self::isUsPhone($s('ec1Phone'))) $out[] = 'the emergency contact\'s phone';
        $need('pedName', 'your pediatrician');
        if (!self::isUsPhone($s('pedPhone'))) $out[] = 'the pediatrician\'s phone';
        $need('pets', 'whether you have pets'); $need('smokeFree', 'whether your home is smoke-free');
        $need('pool', 'whether there\'s a pool');
        if ($s('pets') === 'yes') $need('petNotes', 'a word about your pets');
        if (empty($p['agreePolicies'])) $out[] = 'agreeing to the care policies';
        if (empty($p['agreeAccurate'])) $out[] = 'confirming the information is accurate';

        if (in_array($svc, ['pp', 'nanny', 'travel', 'unsure'], true)) {
            $need('careStart', 'when you need care to start'); $need('careLength', 'about how long you need care');
            if ($s('careStart') === 'date' && $this->date($s('careStartDate')) === null) $out[] = 'the start date';
        }
        if ($svc === 'sit' || $away) {
            $a = $this->datetime($s('startAt')); $b = $this->datetime($s('endAt'));
            if ($a === null || $b === null) $out[] = 'the start and end times';
            elseif ($b <= $a) $out[] = 'an end time after the start';
        }
        if ($svc === 'pp') {
            $b = (array) ($p['baby'] ?? []);
            $born = (string) ($b['born'] ?? '');
            if (!in_array($born, ['yes', 'no'], true)) $out[] = 'whether baby has arrived';
            elseif ($this->date((string) ($b[$born === 'yes' ? 'birthDate' : 'dueDate'] ?? '')) === null)
                $out[] = $born === 'yes' ? 'baby\'s birth date' : 'the due date';
            foreach (['feeding' => 'the feeding plan', 'bringToMom' => 'how night feeds should go',
                         'sleepWhere' => 'where baby will sleep', 'health' => 'baby\'s allergies or health ("none" is fine)'] as $k => $l)
                if (trim((string) ($b[$k] ?? '')) === '') $out[] = $l;
            $need('ppNightsPerWeek', 'nights per week');
        }
        if ($svc === 'nanny') {
            if (empty($p['nannyDays'])) $out[] = 'the days you need care';
            $need('meetTimes', 'good times to talk');
        }
        if ($svc === 'travel') {
            $need('tripDestination', 'where you\'re going'); $need('meetTimes', 'good times to talk');
            if ($this->date($s('tripStart')) === null || $this->date($s('tripEnd')) === null) $out[] = 'the trip dates';
        }
        if ($away || $svc === 'travel') {
            $need('ec2Name', 'a second emergency contact');
            if (!self::isUsPhone($s('ec2Phone'))) $out[] = 'the second emergency contact\'s phone';
            $need('insCompany', 'your health insurance company'); $need('insMember', 'the member ID');
            $need('insHolder', 'the policyholder\'s name'); $need('medAuthName', 'your signature on the medical authorization');
            if (!$this->hasFile('insCardFront')) $out[] = 'a photo of the insurance card (front)';
        }
        if ($away) $need('awaySchedule', 'the daily schedule');
        if (in_array($svc, ['nanny', 'travel'], true) || $away) {
            $need('mayDrive', 'whether Amanda may drive the kids');
            if ($s('mayDrive') === 'yes') { $need('whoseCar', 'whose car'); $need('carSeats', 'car seats'); }
        }

        if ($svc !== 'pp' && !$kids) $out[] = 'at least one child';
        foreach ($kids as $i => $k) {
            $name = mb_substr(trim((string) $k['firstName']), 0, 40);
            $g = fn ($x) => trim((string) ($k[$x] ?? ''));
            $d = $this->date($g('birthDate'));
            if ($d === null || $d > date('Y-m-d') || $d < date('Y-m-d', strtotime('-19 years'))) $out[] = $name . '\'s date of birth';
            foreach (['allergies' => 'allergies ("none" is fine)', 'medications' => 'medications ("none" is fine)',
                         'sleep' => 'bedtime and naps'] as $x => $l) if ($g($x) === '') $out[] = $name . '\'s ' . $l;
            if (($away || $svc === 'nanny') && $g('school') === '') $out[] = $name . '\'s school or daycare';
        }
        return $out;
    }

    /** A posted value as the field's type, or null to leave it unset. */
    protected function value(string $name, $v, string $type, int $max, ?array $options)
    {
        if ($type === 'multiEnum') {
            $v = is_array($v) ? $v : [$v];
            return array_values(array_intersect(array_keys($options ?? []), array_map('strval', $v)));
        }
        if (is_array($v)) return null;
        $v = trim((string) $v);
        switch ($type) {
            case 'bool': return $v !== '' && $v !== '0';
            case 'int': return is_numeric($v) ? (int) $v : null;
            case 'float': return is_numeric($v) ? (float) $v : null;
            case 'date': return $this->date($v);
            case 'datetime': return $this->datetime($v);
            case 'enum': return $v !== '' && array_key_exists($v, $options ?? []) ? $v : null;
            case 'varchar': case 'text':
                if ($v === '') return null;
                if (str_ends_with($name, 'Phone')) $v = self::phoneText($v);
                return mb_substr($v, 0, $type === 'text' ? 5000 : $max);
        }
        return null;
    }

    /** One uploaded file for a `file` field on the request; returns the Attachment id. */
    protected function file(string $field, Entity $lead): ?string
    {
        if (!$this->hasFile($field)) return null;
        $f = $_FILES[$field];
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $name = mb_substr(preg_replace('/[^\w.\- ]+/u', '_', basename((string) $f['name'])) ?: $field, 0, 100);
        if (!preg_match('/\.[A-Za-z0-9]{2,5}$/', $name)) $name .= '.' . self::TYPES[$mime];
        $a = $this->entityManager->getNewEntity('Attachment');
        $a->set(['name' => $name, 'type' => $mime, 'role' => 'Attachment', 'field' => $field,
                 'relatedType' => 'Lead', 'relatedId' => $lead->getId(), 'size' => filesize($f['tmp_name'])]);
        $a->set('contents', (string) file_get_contents($f['tmp_name']));
        $this->entityManager->saveEntity($a);
        return $a->getId();
    }

    protected function hasFile(string $field): bool
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return false;
        $size = filesize($f['tmp_name']);
        return $size > 0 && $size <= self::MAX_FILE && isset(self::TYPES[(new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name'])]);
    }

    /** Where to send the family back: the allowed site they came from, else the first one. */
    protected function siteUrl(Request $request): string
    {
        $sites = (array) ($this->config->get('seasideSiteUrls') ?: ['https://example.com']);
        $from = $request->getHeader('Origin') ?: $request->getHeader('Referer') ?: '';
        foreach ($sites as $u) {
            if ($from !== '' && str_starts_with($from, rtrim($u, '/'))) return rtrim($u, '/');
        }
        return rtrim($sites[0], '/');
    }

    /** "2026-10-17T18:00" in Clearwater time -> "2026-10-17 22:00:00" UTC, as EspoCRM stores it. */
    protected function datetime(string $v): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v)) return null;
        try {
            $d = new \DateTimeImmutable($v, new \DateTimeZone(self::TZ));
        } catch (\Exception $e) {
            return null;
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    protected function date(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
    }

    /** +17275551234: the only form EspoCRM's built-in phone field accepts. */
    protected static function phone(string $v): string
    {
        return self::isUsPhone($v) ? '+1' . substr(preg_replace('/\D+/', '', $v), -10) : $v;
    }

    /** (727) 555-1234 for phone numbers kept in plain text fields. */
    protected static function phoneText(string $v): string
    {
        if (!self::isUsPhone($v)) return $v;
        $d = substr(preg_replace('/\D+/', '', $v), -10);
        return sprintf('(%s) %s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6));
    }

    protected static function isUsPhone(string $v): bool
    {
        $d = preg_replace('/\D+/', '', $v);
        return strlen($d) === 10 || (strlen($d) === 11 && $d[0] === '1');
    }

    protected function go(string $to): Response
    {
        $r = ResponseComposer::empty();
        $r->setStatus(303);
        $r->setHeader('Location', $to);
        return $r;
    }
}
