<?php

declare(strict_types=1);

/**
 * Formularbereich „Türstation“: Öffnungsdauer je Tür, Funktion von Ausgang 2 und der
 * Eingänge 1–4, Sicherheitsstufe des Fingerabdrucklesers. Werte werden beim Öffnen von der
 * Türstation gelesen und mit „Speichern“ sofort geschrieben (nur geänderte Werte).
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10DeviceFormTrait
{
    private const PATH_DOOR_PARAM = '/ISAPI/AccessControl/Door/param/';
    private const PATH_OUTPUT = '/ISAPI/System/IO/outputs/';
    private const PATH_INPUT = '/ISAPI/System/IO/inputs/';
    private const PATH_READER = '/ISAPI/AccessControl/CardReaderCfg/1?format=json';

    public function UIDeviceSave(float $Open1, float $Open2, string $Output2, string $Input1, string $Input2, string $Input3, string $Input4, int $FpLevel, bool $SmTamper,
        string $LightMode = '', string $LightBegin = '', string $LightEnd = ''): string
    {
        try {
            $now = $this->deviceSettings();
            $changed = [];
            $reboot = false;
            foreach ([1 => $Open1, 2 => $Open2] as $door => $seconds) {
                $ms = (int) round(max(0.1, min(600, $seconds)) * 1000);
                if ($ms !== $now['open'][$door]) {
                    $reboot = $this->xmlEdit(self::PATH_DOOR_PARAM . $door, ['openDuration' => (string) $ms]) || $reboot;
                    $changed[] = sprintf($this->Translate('Opening time door %d'), $door);
                }
            }
            if ($Output2 !== $now['output2']) {
                $reboot = $this->xmlEdit(self::PATH_OUTPUT . '2', ['IOUseType' => $Output2]) || $reboot;
                $changed[] = $this->Translate('Output 2');
            }
            foreach ([1 => $Input1, 2 => $Input2, 3 => $Input3, 4 => $Input4] as $no => $type) {
                if ($type !== $now['inputs'][$no]) {
                    $reboot = $this->xmlEdit(self::PATH_INPUT . $no, ['IOUseType' => $type]) || $reboot;
                    $changed[] = sprintf($this->Translate('Input %d'), $no);
                }
            }
            if ($FpLevel !== $now['fpLevel']) {
                $cfg = $this->json($this->isapi()->request('GET', self::PATH_READER));
                $cfg['CardReaderCfg']['fingerPrintCheckLevel'] = $FpLevel;
                $reboot = $this->writeChecked('PUT', self::PATH_READER, (string) json_encode($cfg)) || $reboot;
                $changed[] = $this->Translate('Fingerprint security level');
            }
            if ($this->ReadPropertyBoolean('SecurityModule') && $SmTamper !== $now['smTamper']) {
                $reboot = $this->securityModuleTamperAlarm($SmTamper) || $reboot;
                $changed[] = $this->Translate('Security module tamper alarm');
            }
            if ($LightMode !== '' && $this->saveBacklight($now['light'], $LightMode, $LightBegin, $LightEnd)) {
                $changed[] = $this->Translate('Button lighting');
            }
            if ($changed === []) {
                return $this->Translate('No changes');
            }
            $msg = $this->Translate('Saved') . ': ' . implode(', ', $changed);
            return $reboot ? $msg . "\n" . $this->Translate('The door station must be restarted for the change to take effect (button “Reboot door station”).') : $msg;
        } catch (Throwable $e) {
            return $this->Translate('Error') . ': ' . $e->getMessage();
        }
    }

    /** Tastenbeleuchtung (Modus, eigene Zeiten) schreiben; true = geändert. */
    private function saveBacklight(array $now, string $mode, string $begin, string $end): bool
    {
        if (!in_array($mode, self::BACKLIGHT_MODES, true)) {
            throw new InvalidArgumentException('Invalid mode ' . $mode);
        }
        $from = $this->timeValue($begin, $now['begin']);
        $until = $this->timeValue($end, $now['end']);
        if ($mode === $now['mode'] && ($mode !== 'custom' || ($from === $now['begin'] && $until === $now['end']))) {
            return false;
        }
        if ($mode === 'custom' && $from === $until) {
            throw new InvalidArgumentException($this->Translate('Button lighting: start and end must differ'));
        }
        $cfg = $this->json($this->isapi()->request('GET', self::PATH_BACKLIGHT));
        $cfg['backlightMode'] = $mode;
        if ($mode === 'custom') {
            $cfg['customBacklightTime'] = ['beginTime' => $from, 'endTime' => $until];
        }
        $this->writeChecked('PUT', self::PATH_BACKLIGHT, (string) json_encode($cfg));
        $this->SetValue('BACKLIGHT', $mode);
        return true;
    }

    /** SelectTime-JSON → „HH:MM:SS“; leer/ungültig = bisheriger Wert. */
    private function timeValue(string $json, string $fallback): string
    {
        $t = json_decode($json, true);
        if (!is_array($t) || !isset($t['hour'], $t['minute'])) {
            return $fallback;
        }
        return sprintf('%02d:%02d:%02d', max(0, min(23, (int) $t['hour'])), max(0, min(59, (int) $t['minute'])), max(0, min(59, (int) ($t['second'] ?? 0))));
    }

    /** „HH:MM:SS“ → SelectTime-JSON. */
    private function timeJson(string $time): string
    {
        [$h, $m, $s] = array_map('intval', explode(':', $time . ':0:0'));
        return (string) json_encode(['hour' => $h, 'minute' => $m, 'second' => $s]);
    }

    /** Auswahl des Modus: Zeitfelder nur bei „Eigene Zeiten“ aktiv. */
    public function UIDeviceLightMode(string $Mode): void
    {
        $this->UpdateFormField('DevLightBegin', 'enabled', $Mode === 'custom');
        $this->UpdateFormField('DevLightEnd', 'enabled', $Mode === 'custom');
    }

    /** Werte und Auswahlmöglichkeiten in den Formularbereich eintragen. */
    private function fillDeviceForm(array $form): array
    {
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') !== 'DevicePanel') {
                continue;
            }
            try {
                if ($this->ReadPropertyString('Host') === '' || $this->GetStatus() !== IS_ACTIVE) {
                    throw new RuntimeException($this->Translate('Door station not reachable'));
                }
                $now = $this->deviceSettings();
            } catch (Throwable $e) {
                $action['items'] = [['type' => 'Label', 'caption' => $e->getMessage()]];
                continue;
            }
            $values = ['DevOpen1' => $now['open'][1] / 1000, 'DevOpen2' => $now['open'][2] / 1000, 'DevOutput2' => $now['output2'],
                'DevFpLevel' => $now['fpLevel'], 'DevSmTamper' => $now['smTamper'], 'DevLightMode' => $now['light']['mode'],
                'DevLightBegin' => $this->timeJson($now['light']['begin']), 'DevLightEnd' => $this->timeJson($now['light']['end'])];
            foreach ([1, 2, 3, 4] as $no) {
                $values['DevInput' . $no] = $now['inputs'][$no];
            }
            $action['items'] = $this->applyDeviceValues($action['items'], $values, $now['inputOptions']);
        }
        return $form;
    }

    private function applyDeviceValues(array $items, array $values, array $inputOptions): array
    {
        foreach ($items as &$item) {
            if (isset($item['items'])) {
                $item['items'] = $this->applyDeviceValues($item['items'], $values, $inputOptions);
            }
            $name = $item['name'] ?? '';
            if (array_key_exists($name, $values)) {
                $item['value'] = $values[$name];
            }
            if ($name === 'DevSmTamper') {
                $item['visible'] = $this->ReadPropertyBoolean('SecurityModule');
            }
            if ($name === 'DevLightMode') {
                $item['options'] = array_map(fn (string $m): array => ['caption' => $this->Translate('backlight_' . $m), 'value' => $m], self::BACKLIGHT_MODES);
            }
            if ($name === 'DevLightBegin' || $name === 'DevLightEnd') {
                $item['enabled'] = ($values['DevLightMode'] ?? '') === 'custom';
            }
            if ($name === 'DevFpLevel') {
                $item['visible'] = $this->credentials()['finger']; // ohne Fingerleser keine Sicherheitsstufe
            }
            if (str_starts_with($name, 'DevInput')) {
                $item['options'] = array_map(fn (string $o): array => ['caption' => $this->Translate('input_' . $o), 'value' => $o],
                    $inputOptions[(int) substr($name, 8)] ?? ['custom']);
            }
        }
        return $items;
    }

    /** @return array{open: array<int,int>, output2: string, inputs: array<int,string>, inputOptions: array<int,array<int,string>>, fpLevel: int, smTamper: bool, light: array{mode:string,begin:string,end:string}} */
    private function deviceSettings(): array
    {
        $api = $this->isapi();
        $tag = static function (string $xml, string $name): string {
            return preg_match('#<' . $name . '(?:\s[^>]*)?>([^<]*)</' . $name . '>#', $xml, $m) ? $m[1] : '';
        };
        $open = [];
        foreach ([1, 2] as $door) {
            $open[$door] = (int) $tag($api->request('GET', self::PATH_DOOR_PARAM . $door, '', 5)['body'], 'openDuration');
        }
        $inputs = [];
        $inputOptions = [];
        foreach ([1, 2, 3, 4] as $no) {
            $inputs[$no] = $tag($api->request('GET', self::PATH_INPUT . $no, '', 5)['body'], 'IOUseType');
            $caps = $api->request('GET', self::PATH_INPUT . $no . '/capabilities', '', 5)['body'];
            $inputOptions[$no] = preg_match('#<IOUseType opt="([^"]+)"#', $caps, $m) ? explode(',', $m[1]) : [$inputs[$no]];
        }
        $reader = $this->json($api->request('GET', self::PATH_READER, '', 5));
        $light = $this->json($api->request('GET', self::PATH_BACKLIGHT, '', 5));
        return [
            'open'         => $open,
            'output2'      => $tag($api->request('GET', self::PATH_OUTPUT . '2', '', 5)['body'], 'IOUseType'),
            'inputs'       => $inputs,
            'inputOptions' => $inputOptions,
            'fpLevel'      => (int) ($reader['CardReaderCfg']['fingerPrintCheckLevel'] ?? 0),
            'smTamper'     => $this->securityModuleTamperAlarm(),
            'light'        => ['mode' => (string) ($light['backlightMode'] ?? 'auto'),
                'begin' => (string) ($light['customBacklightTime']['beginTime'] ?? '18:00:00'),
                'end'   => (string) ($light['customBacklightTime']['endTime'] ?? '06:00:00')],
        ];
    }
}
