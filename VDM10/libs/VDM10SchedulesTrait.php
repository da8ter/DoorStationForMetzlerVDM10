<?php

declare(strict_types=1);

/**
 * Zugangszeitpläne der Türstation. Zeitplan Nr. n = Wochenplan n + Vorlage n
 * (Grundbelegung der Türstation), Feiertage werden nicht genutzt. Eine Person
 * bekommt die Vorlage für alle Türen, für die sie berechtigt ist.
 *
 * Zeitfenster im Modul: ['days' => 'weekdays'|'weekend'|'daily'|'mon'..'sun', 'from' => 'HH:MM', 'to' => 'HH:MM'].
 * Geprüft auf Firmware V3.7.1: UserRightWeekPlanCfg/{n} und UserRightPlanTemplate/{n} per PUT,
 * Lesen nur über die Suche (Einzelabruf liefert deviceError), RightPlan per UserInfo/Modify.
 */
trait VDM10SchedulesTrait
{
    private const WEEKDAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
    private const DAY_GROUPS = ['daily' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
        'weekdays' => ['mon', 'tue', 'wed', 'thu', 'fri'], 'weekend' => ['sat', 'sun']];
    private const SEGMENTS_PER_DAY = 8;
    private const SCHEDULE_SLOTS = 255;

    /** @return array<int, array{no:int,name:string,windows:array<int,array{days:string,from:string,to:string}>}> */
    public function GetSchedules(): array
    {
        $templates = $this->scheduleSearch('UserRightPlanTemplate');
        $plans = [];
        foreach ($this->scheduleSearch('UserRightWeekPlanCfg') as $p) {
            $plans[(int) $p['weekPlanID']] = $p;
        }
        $out = [];
        foreach ($templates as $t) {
            $plan = $plans[(int) $t['weekPlanNo']] ?? ['WeekPlanCfg' => []];
            $out[] = ['no' => (int) $t['planTemplateID'], 'name' => (string) $t['templateName'],
                'windows' => $this->windowsFromPlan($plan['WeekPlanCfg'] ?? [])];
        }
        usort($out, static fn (array $a, array $b): int => $a['no'] <=> $b['no']);
        return $out;
    }

    /** Zeitplan anlegen (No = 0) oder ändern; Windows als JSON-Liste. Liefert die Nummer. */
    public function SetSchedule(int $No, string $Name, string $Windows): int
    {
        $name = trim($Name);
        if ($name === '' || mb_strlen($name) > 32) {
            throw new InvalidArgumentException($this->Translate('Name must have 1 to 32 characters'));
        }
        $windows = json_decode($Windows, true);
        if (!is_array($windows) || $windows === []) {
            throw new InvalidArgumentException($this->Translate('Add at least one time window'));
        }
        $no = $No > 0 ? $No : $this->freeScheduleSlot();
        $this->acs('PUT', '/ISAPI/AccessControl/UserRightWeekPlanCfg/' . $no . '?format=json',
            ['UserRightWeekPlanCfg' => ['enable' => true, 'WeekPlanCfg' => $this->planFromWindows($windows)]]);
        $this->acs('PUT', '/ISAPI/AccessControl/UserRightPlanTemplate/' . $no . '?format=json',
            ['UserRightPlanTemplate' => ['enable' => true, 'templateName' => $name, 'weekPlanNo' => $no, 'holidayGroupNo' => '']]);
        $this->SendDebug('Schedules', 'saved ' . $no, 0);
        return $no;
    }

    /** Löscht einen Zeitplan; verweigert, solange Personen ihn nutzen. */
    public function DeleteSchedule(int $No): bool
    {
        $users = array_filter($this->GetPersons(), fn (array $u): bool => $this->personSchedule($u) === $No);
        if ($users !== []) {
            throw new RuntimeException(sprintf($this->Translate('Schedule is still assigned to: %s'),
                implode(', ', array_map(static fn (array $u): string => (string) $u['name'], $users))));
        }
        $this->acs('PUT', '/ISAPI/AccessControl/UserRightPlanTemplate/' . $No . '?format=json',
            ['UserRightPlanTemplate' => ['enable' => false, 'templateName' => '', 'weekPlanNo' => $No, 'holidayGroupNo' => '']]);
        $this->acs('PUT', '/ISAPI/AccessControl/UserRightWeekPlanCfg/' . $No . '?format=json',
            ['UserRightWeekPlanCfg' => ['enable' => false, 'WeekPlanCfg' => $this->planFromWindows([])]]);
        return true;
    }

    /** Zeitplan einer Person setzen; 0 = rund um die Uhr. Gilt für alle Türen der Person. */
    public function SetPersonSchedule(string $EmployeeNo, int $ScheduleNo): bool
    {
        $person = $this->person($EmployeeNo);
        $doors = (string) ($person['doorRight'] ?? '1');
        $plan = [];
        if ($ScheduleNo > 0) {
            if (!in_array($ScheduleNo, array_column($this->GetSchedules(), 'no'), true)) {
                throw new InvalidArgumentException(sprintf($this->Translate('Schedule %d not found'), $ScheduleNo));
            }
            foreach (array_filter(explode(',', $doors)) as $door) {
                $plan[] = ['doorNo' => (int) $door, 'planTemplateNo' => (string) $ScheduleNo];
            }
        }
        // Die Türstation nimmt RightPlan nur zusammen mit doorRight an (sonst badJsonContent).
        return $this->modifyPerson($EmployeeNo, ['doorRight' => $doors, 'RightPlan' => $plan]);
    }

    /** Zeitplan-Nummer einer Person (aus GetPersons); 0 = rund um die Uhr. */
    private function personSchedule(array $person): int
    {
        return (int) explode(',', (string) ($person['RightPlan'][0]['planTemplateNo'] ?? '0'))[0];
    }

    /** @return array<int, array<string,mixed>> aktive Einträge einer Zeitplan-Suche */
    private function scheduleSearch(string $type): array
    {
        $out = [];
        $pos = 1;
        do {
            $r = $this->acs('POST', '/ISAPI/AccessControl/' . $type . '/Search?format=json',
                ['searchID' => 'vdm10', 'searchResultPosition' => $pos, 'maxResults' => 100, 'enable' => true]);
            $page = $r['matchResults'] ?? [];
            array_push($out, ...$page);
            $pos += count($page);
        } while ($page !== [] && ($r['responseStatus'] ?? '') === 'MORE');
        return $out;
    }

    private function freeScheduleSlot(): int
    {
        $used = array_column($this->GetSchedules(), 'no');
        for ($i = 1; $i <= self::SCHEDULE_SLOTS; $i++) {
            if (!in_array($i, $used, true)) {
                return $i;
            }
        }
        throw new RuntimeException($this->Translate('All schedule slots are used'));
    }

    /** @return array<int, array<string,mixed>> alle 56 Segmente (7 Tage × 8) */
    private function planFromWindows(array $windows): array
    {
        $segments = array_fill_keys(array_keys(self::WEEKDAYS), []);
        foreach ($windows as $w) {
            $from = $this->clock((string) ($w['from'] ?? ''));
            $to = $this->clock((string) ($w['to'] ?? ''), true);
            if ($to <= $from) {
                throw new InvalidArgumentException(sprintf($this->Translate('Time window %s – %s: end must be after start'), substr($from, 0, 5), substr($to, 0, 5)));
            }
            foreach (self::DAY_GROUPS[$w['days'] ?? ''] ?? [$w['days'] ?? ''] as $day) {
                if (!isset($segments[$day])) {
                    throw new InvalidArgumentException('Unknown day ' . $day);
                }
                $segments[$day][] = [$from, $to];
            }
        }
        $cfg = [];
        foreach (self::WEEKDAYS as $key => $week) {
            if (count($segments[$key]) > self::SEGMENTS_PER_DAY) {
                throw new InvalidArgumentException(sprintf($this->Translate('At most %d time windows per day'), self::SEGMENTS_PER_DAY));
            }
            sort($segments[$key]);
            for ($i = 1; $i <= self::SEGMENTS_PER_DAY; $i++) {
                $seg = $segments[$key][$i - 1] ?? null;
                $cfg[] = ['week' => $week, 'id' => $i, 'enable' => $seg !== null,
                    'TimeSegment' => ['beginTime' => $seg[0] ?? '00:00:00', 'endTime' => $seg[1] ?? '00:00:00']];
            }
        }
        return $cfg;
    }

    /** Segmente der Türstation → Zeitfenster, gleiche Zeiten an mehreren Tagen zusammengefasst. */
    private function windowsFromPlan(array $cfg): array
    {
        $byTime = [];
        $week = array_flip(self::WEEKDAYS);
        foreach ($cfg as $seg) {
            if (empty($seg['enable']) || !isset($week[$seg['week'] ?? ''])) {
                continue;
            }
            $key = substr((string) $seg['TimeSegment']['beginTime'], 0, 5) . '-' . substr((string) $seg['TimeSegment']['endTime'], 0, 5);
            $byTime[$key][] = $week[$seg['week']];
        }
        $windows = [];
        foreach ($byTime as $key => $days) {
            [$from, $to] = explode('-', $key);
            $to = $to === '24:00' ? '23:59' : $to;
            $days = array_values(array_intersect(array_keys(self::WEEKDAYS), $days));
            $groups = [];
            foreach (self::DAY_GROUPS as $group => $members) {
                if (array_diff($members, $days) === []) {
                    $groups[] = $group;
                    $days = array_values(array_diff($days, $members));
                }
            }
            foreach (array_merge($groups, $days) as $d) {
                $windows[] = ['days' => $d, 'from' => $from, 'to' => $to];
            }
        }
        return $windows;
    }

    /** 'HH:MM' → 'HH:MM:SS'; 23:59 als Ende bedeutet bis Mitternacht. */
    private function clock(string $hhmm, bool $end = false): string
    {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm)) {
            throw new InvalidArgumentException(sprintf($this->Translate('Invalid time %s'), $hhmm));
        }
        return $end && $hhmm === '23:59' ? '24:00:00' : $hhmm . ':00';
    }

    /** Kurztext für Listen, z. B. "Mo–Fr 08:00–12:00, Sa 09:00–13:00". */
    private function scheduleSummary(array $windows): string
    {
        $parts = array_map(fn (array $w): string => $this->Translate('day_' . $w['days']) . ' ' . $w['from'] . '–' . $w['to'], $windows);
        return implode(', ', $parts);
    }
}
