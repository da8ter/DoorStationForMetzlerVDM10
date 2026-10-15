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

    public function UIDeviceSave(float $Open1, float $Open2, string $Output2, string $Input1, string $Input2, string $Input3, string $Input4, int $FpLevel): string
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
            if ($changed === []) {
                return $this->Translate('No changes');
            }
            $msg = $this->Translate('Saved') . ': ' . implode(', ', $changed);
            return $reboot ? $msg . "\n" . $this->Translate('The door station must be restarted for the change to take effect (button “Reboot door station”).') : $msg;
        } catch (Throwable $e) {
            return $this->Translate('Error') . ': ' . $e->getMessage();
        }
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
                'DevFpLevel' => $now['fpLevel']];
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
            if (str_starts_with($name, 'DevInput')) {
                $item['options'] = array_map(fn (string $o): array => ['caption' => $this->Translate('input_' . $o), 'value' => $o],
                    $inputOptions[(int) substr($name, 8)] ?? ['custom']);
            }
        }
        return $items;
    }

    /** @return array{open: array<int,int>, output2: string, inputs: array<int,string>, inputOptions: array<int,array<int,string>>, fpLevel: int} */
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
        return [
            'open'         => $open,
            'output2'      => $tag($api->request('GET', self::PATH_OUTPUT . '2', '', 5)['body'], 'IOUseType'),
            'inputs'       => $inputs,
            'inputOptions' => $inputOptions,
            'fpLevel'      => (int) ($reader['CardReaderCfg']['fingerPrintCheckLevel'] ?? 0),
        ];
    }
}
