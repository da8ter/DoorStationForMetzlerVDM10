<?php

declare(strict_types=1);

/**
 * Rufzeiten der Klingeltasten („Bitte nicht stören“): Nur in den eingetragenen Zeitfenstern ruft
 * ein Tastendruck ins Haus (Innenstation bzw. per SIP die FRITZ!Box). Umsetzung wie in der
 * Weboberfläche der Türstation („Anrufplaneinstellungen“): Wochenplan 1 + Vorlage 1, jede Taste
 * verweist mit templateNo 1 darauf; templateNo 0 = „ganzer Tag“ (Grundeinstellung).
 *
 * „Nicht stören“ nutzt einen eigenen, leeren Plan (Wochenplan 2 + Vorlage 2): Die Tasten verweisen
 * dann auf Vorlage 2 und rufen nie ins Haus; ausgeschaltet zurück auf 1 (Rufzeiten an) bzw. 0.
 *
 * Zeitfenster im Modul wie bei den Zugangszeitplänen: ['days' => 'weekdays'|..|'mon'.., 'from', 'to'].
 * Wird von VDM10 (module.php) als Trait eingebunden; nutzt clock()/windowsFromPlan() aus
 * VDM10SchedulesTrait.
 */
trait VDM10CallTimesTrait
{
    private const CALL_PLAN_NO = 1;
    private const DND_PLAN_NO = 2;
    private const CALL_WINDOWS_PER_DAY = 5;
    private const PATH_KEY_WEEK_PLAN = '/ISAPI/VideoIntercom/keyCfg/weekPlan/%d?format=json';
    private const PATH_KEY_TEMPLATE = '/ISAPI/VideoIntercom/keyCfg/planTemplate/%d?format=json';

    /** @return array{enabled:bool, windows:array<int,array{days:string,from:string,to:string}>} */
    public function GetCallTimes(): array
    {
        $api = $this->isapi();
        $template = $this->json($api->request('GET', sprintf(self::PATH_KEY_TEMPLATE, self::CALL_PLAN_NO), '', 5))['KeyCfgPlanTemplate'] ?? [];
        $plan = $this->json($api->request('GET', sprintf(self::PATH_KEY_WEEK_PLAN, self::CALL_PLAN_NO), '', 5))['KeyCfgWeekPlan'] ?? [];
        return ['enabled' => !empty($template['enable']), 'windows' => $this->windowsFromPlan($plan['WeekPlanCfg'] ?? [])];
    }

    /** „Nicht stören“: Tasten rufen nie ins Haus (unabhängig von den Rufzeiten). */
    public function SetDoNotDisturb(bool $Active): bool
    {
        if ($Active) {
            $this->writeChecked('PUT', sprintf(self::PATH_KEY_WEEK_PLAN, self::DND_PLAN_NO),
                (string) json_encode(['KeyCfgWeekPlan' => ['enable' => true, 'WeekPlanCfg' => []]]));
            $this->writeChecked('PUT', sprintf(self::PATH_KEY_TEMPLATE, self::DND_PLAN_NO), (string) json_encode(['KeyCfgPlanTemplate' => [
                'enable' => true, 'templateName' => 'Symcon DND', 'weekPlanNo' => self::DND_PLAN_NO, 'holidayGroupNo' => (string) self::DND_PLAN_NO,
            ]]));
        }
        $this->linkKeys($Active ? self::DND_PLAN_NO : ($this->GetCallTimes()['enabled'] ? self::CALL_PLAN_NO : 0));
        if (@$this->GetIDForIdent('DND')) {
            $this->SetValue('DND', $Active);
        }
        $this->tileChanged();
        return true;
    }

    /** Ist „Nicht stören“ an der Türstation aktiv? null = nicht lesbar. */
    private function readDoNotDisturb(): ?bool
    {
        $r = $this->isapi()->request('GET', self::PATH_KEYS, '', 5);
        $keys = $r['code'] === 200 ? VDM10SubModules::parseKeys($r['body']) : [];
        return $keys === [] ? null : array_filter($keys, static fn (array $k): bool => $k['template'] === self::DND_PLAN_NO) !== [];
    }

    /** Alle Tasten auf eine Vorlage stellen (nur geänderte schreiben). */
    private function linkKeys(int $template): void
    {
        foreach (VDM10SubModules::parseKeys($this->isapi()->request('GET', self::PATH_KEYS, '', 5)['body']) as $key) {
            if ($key['template'] !== $template) {
                $path = sprintf(self::PATH_KEY, $key['id']);
                $xml = $this->isapi()->request('GET', $path)['body'];
                $this->writeChecked('PUT', $path, VDM10SubModules::xmlSet($xml, 'KeyCfg', 'templateNo', (string) $template));
            }
        }
    }

    /**
     * Rufzeiten setzen. $Enabled = false: Tasten rufen wieder rund um die Uhr (Zeitfenster bleiben
     * gespeichert). Keine Zeitfenster bei $Enabled = true: Tasten rufen gar nicht ins Haus.
     */
    public function SetCallTimes(bool $Enabled, string $Windows): bool
    {
        $windows = json_decode($Windows, true);
        if (!is_array($windows)) {
            throw new InvalidArgumentException('Windows must be a JSON list');
        }
        if ($Enabled) {
            $this->writeChecked('PUT', sprintf(self::PATH_KEY_WEEK_PLAN, self::CALL_PLAN_NO),
                (string) json_encode(['KeyCfgWeekPlan' => ['enable' => true, 'WeekPlanCfg' => $this->callPlan($windows)]]));
        }
        $this->writeChecked('PUT', sprintf(self::PATH_KEY_TEMPLATE, self::CALL_PLAN_NO), (string) json_encode(['KeyCfgPlanTemplate' => [
            // holidayGroupNo als Text: als Zahl antwortet die Türstation (V3.7.1) mit badJsonContent
            'enable' => $Enabled, 'templateName' => 'Symcon', 'weekPlanNo' => self::CALL_PLAN_NO, 'holidayGroupNo' => '1',
        ]]));
        if (!$this->readDoNotDisturb()) { // bei „Nicht stören“ bleiben die Tasten auf dem leeren Plan
            $this->linkKeys($Enabled ? self::CALL_PLAN_NO : 0);
        }
        $this->SendDebug('CallTimes', ($Enabled ? 'on ' : 'off ') . $Windows, 0);
        return true;
    }

    /** Zeitfenster → WeekPlanCfg (nur belegte Segmente, je Tag id 1..5, Rufart „Nummer anrufen“). */
    private function callPlan(array $windows): array
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
            if (count($segments[$key]) > self::CALL_WINDOWS_PER_DAY) {
                throw new InvalidArgumentException(sprintf($this->Translate('At most %d time windows per day'), self::CALL_WINDOWS_PER_DAY));
            }
            sort($segments[$key]);
            foreach (array_values($segments[$key]) as $i => [$from, $to]) {
                $cfg[] = ['id' => $i + 1, 'week' => $week, 'enable' => true,
                    'TimeSegment' => ['beginTime' => $from, 'endTime' => $to], 'callMethod' => 'callNumber'];
            }
        }
        return $cfg;
    }

    /** Formular: „In die Türstation schreiben“. $Windows: Zeilen der Tabelle (JSON). */
    public function UICallTimesSave(bool $Enabled, string $Windows): string
    {
        try {
            $windows = [];
            foreach (json_decode($Windows, true) ?: [] as $row) {
                $windows[] = ['days' => (string) ($row['Days'] ?? ''), 'from' => $this->timeFromForm($row['From'] ?? ''),
                    'to' => $this->timeFromForm($row['To'] ?? '')];
            }
            $this->SetCallTimes($Enabled, (string) json_encode($windows));
            return $this->Translate($Enabled ? ($windows === [] ? 'Saved: the bell buttons no longer call into the house.'
                : 'Saved: the bell buttons call into the house only within these times.') : 'Saved: the bell buttons call around the clock.');
        } catch (Throwable $e) {
            return $this->Translate('Error') . ': ' . $e->getMessage();
        }
    }

    /** Formularbereich „CallTimesPanel“ mit dem Stand der Türstation füllen. */
    private function fillCallTimesForm(array $form): array
    {
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') !== 'CallTimesPanel') {
                continue;
            }
            if ($this->ReadPropertyString('Host') === '' || $this->GetStatus() !== IS_ACTIVE) {
                $action['items'] = [['type' => 'Label', 'caption' => $this->Translate('Door station not reachable')]];
                continue;
            }
            try {
                $now = $this->GetCallTimes();
            } catch (Throwable $e) {
                $action['items'] = [['type' => 'Label', 'caption' => $e->getMessage()]];
                continue;
            }
            foreach ($action['items'] as &$item) {
                if (($item['name'] ?? '') === 'CallTimesEnabled') {
                    $item['value'] = $now['enabled'];
                } elseif (($item['name'] ?? '') === 'CallTimesWindows') {
                    $item['values'] = array_map(fn (array $w): array => ['Days' => $w['days'],
                        'From' => $this->timeToForm($w['from']), 'To' => $this->timeToForm($w['to'])], $now['windows']);
                }
            }
        }
        return $form;
    }
}
