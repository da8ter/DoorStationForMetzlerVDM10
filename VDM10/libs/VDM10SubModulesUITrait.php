<?php

declare(strict_types=1);

/**
 * Konfigurationsformular der Erweiterungsmodule: Liste der Module mit Einstellungsdialog für
 * Display-Module (Helligkeit, Tastenton, Darstellung, Startbildschirm, Zentrale, direktes
 * Klingeln) und Liste der Klingeltasten (Zimmernummer, Beschriftung). Änderungen gehen sofort
 * an die Türstation. Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10SubModulesUITrait
{
    /** Listen „SubModules“ und „Keys“ im Panel „ModulesPanel“ füllen. */
    private function fillSubModulesForm(array $form): array
    {
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') !== 'ModulesPanel') {
                continue;
            }
            $online = $this->ReadPropertyString('Host') !== '' && $this->GetStatus() === IS_ACTIVE;
            if ($online) {
                $this->refreshSubModules();
                $this->refreshKeys();
            }
            foreach ($action['items'] as &$item) {
                if (($item['name'] ?? '') === 'SubModules') {
                    $item['values'] = $this->subModuleRows($online);
                } elseif (($item['name'] ?? '') === 'Keys') {
                    $item['values'] = $this->keyRows();
                }
            }
            unset($item);
            $action['items'] = $this->fillDisplayTextForm($action['items'], $online);
        }
        return $form;
    }

    private function subModuleRows(bool $online): array
    {
        $status = ['online' => 'Online', 'offline' => 'Offline', 'fault' => 'Fault'];
        $rows = [];
        foreach ($this->subModules() as $m) {
            $cfg = $online && VDM10SubModules::isDisplay($m['type']) ? $this->displayConfig($m['id']) : [];
            $rows[] = [
                'no' => $m['id'], 'name' => $this->Translate(VDM10SubModules::name($m['type'])), 'model' => $m['type'],
                'status' => $this->Translate($status[$m['status']] ?? $m['status']), 'version' => $m['version'],
                'ModId' => $m['id'], 'ModBrightness' => (int) ($cfg['brightness'] ?? 0), 'ModTone' => (bool) ($cfg['tone'] ?? false),
                'ModTheme' => (string) ($cfg['theme'] ?? ''), 'ModStart' => (string) ($cfg['start'] ?? ''),
                'ModCallCenter' => (bool) ($cfg['callCenter'] ?? false), 'ModCallCenterName' => (string) ($cfg['callCenterName'] ?? ''),
                'ModRingDirectly' => (bool) ($cfg['ringDirectly'] ?? false),
            ];
        }
        return $rows;
    }

    private function keyRows(): array
    {
        $rows = [];
        foreach ($this->keys() as $k) {
            $where = $k['virtual'] ? 'Display' : ($k['module'] === 'main' ? 'Main module' : 'Extension module');
            $rows[] = ['no' => $k['id'], 'where' => $this->Translate($where), 'room' => $k['room'] > 0 ? (string) $k['room'] : '–',
                'label' => $this->keyName($k), 'KeyId' => $k['id'], 'KeyRoom' => max(1, $k['room']), 'KeyLabel' => $k['label']];
        }
        return $rows;
    }

    /** Dialog eines Moduls: nur die Felder, die das Modul laut Fähigkeiten kennt. */
    public function UISubModuleForm(int $Id): string
    {
        $module = null;
        foreach ($this->subModules() as $m) {
            $module = $m['id'] === $Id ? $m : $module;
        }
        $form = [['type' => 'NumberSpinner', 'name' => 'ModId', 'caption' => 'ID', 'visible' => false]];
        $caps = $module !== null && VDM10SubModules::isDisplay($module['type']) && $module['status'] === 'online' ? $this->displayCaps($Id) : null;
        if ($module === null || $caps === null) {
            $form[] = ['type' => 'Label', 'caption' => $this->Translate($module !== null && VDM10SubModules::isDisplay($module['type']) && $module['status'] !== 'online'
                ? 'The module is offline.' : 'This module has no settings')];
            return (string) json_encode($form);
        }
        $options = fn (array $values, array $names): array => array_map(fn (string $v): array => ['caption' => $this->Translate($names[$v] ?? $v), 'value' => $v], $values);
        $form[] = ['type' => 'Label', 'caption' => $this->moduleTitle($module), 'bold' => true];
        if ($caps['brightness'] !== null) {
            $form[] = ['type' => 'HorizontalSlider', 'name' => 'ModBrightness', 'caption' => $this->Translate('Brightness'),
                'minimum' => $caps['brightness'][0], 'maximum' => $caps['brightness'][1], 'stepSize' => 1, 'width' => '320px'];
        }
        if ($caps['tone']) {
            $form[] = ['type' => 'CheckBox', 'name' => 'ModTone', 'caption' => $this->Translate('Key tone')];
        }
        if ($caps['themes'] !== []) {
            $form[] = ['type' => 'Select', 'name' => 'ModTheme', 'caption' => $this->Translate('Design'), 'width' => '320px',
                'options' => $options($caps['themes'], VDM10SubModules::THEMES)];
        }
        if ($caps['start'] !== []) {
            $form[] = ['type' => 'Select', 'name' => 'ModStart', 'caption' => $this->Translate('Start screen'), 'width' => '320px',
                'options' => $options($caps['start'], VDM10SubModules::START_SCREENS)];
        }
        if ($caps['ringDirectly']) {
            $form[] = ['type' => 'CheckBox', 'name' => 'ModRingDirectly', 'caption' => $this->Translate('Ring directly (without confirmation)')];
        }
        if ($caps['callCenter']) {
            $form[] = ['type' => 'CheckBox', 'name' => 'ModCallCenter', 'caption' => $this->Translate('Button “Call management center”')];
        }
        if ($caps['callCenterName'] !== null) {
            $form[] = ['type' => 'ValidationTextBox', 'name' => 'ModCallCenterName', 'caption' => $this->Translate('Label of the management center button'),
                'width' => '320px', 'validate' => sprintf('^.{%d,%d}$', $caps['callCenterName'][0], $caps['callCenterName'][1])];
        }
        $form[] = ['type' => 'Label', 'caption' => $this->Translate('Virtual call buttons of the display are listed under “Bell buttons”.')];
        return (string) json_encode($form);
    }

    /** onEdit der Modulliste: Display-Einstellungen schreiben. */
    public function UISubModuleSave(int $Id, int $Brightness, bool $Tone, string $Theme, string $Start, bool $CallCenter, string $CallCenterName, bool $RingDirectly): string
    {
        $module = null;
        foreach ($this->subModules() as $m) {
            $module = $m['id'] === $Id ? $m : $module;
        }
        if ($module === null || !VDM10SubModules::isDisplay($module['type'])) {
            return '';
        }
        try {
            $this->writeDisplay($Id, ['brightness' => $Brightness, 'tone' => $Tone, 'theme' => $Theme, 'start' => $Start,
                'callCenter' => $CallCenter, 'callCenterName' => $CallCenterName, 'ringDirectly' => $RingDirectly]);
            $this->refreshSubModules();
            $msg = $this->Translate('Saved');
        } catch (Throwable $e) {
            $msg = $this->Translate('Error') . ': ' . $e->getMessage();
        }
        $this->UpdateFormField('SubModules', 'values', (string) json_encode($this->subModuleRows(true)));
        return $msg;
    }

    /** onEdit der Tastenliste: Zimmernummer und Beschriftung einer Klingeltaste schreiben. */
    public function UIKeySave(int $Id, int $Room, string $Label): string
    {
        try {
            if ($Room < 1 || $Room > 9999) {
                throw new InvalidArgumentException($this->Translate('Room number 1–9999'));
            }
            $Label = mb_substr(trim($Label), 0, 30);
            $path = sprintf(self::PATH_KEY, $Id);
            $r = $this->isapi()->request('GET', $path);
            if ($r['code'] !== 200 || !str_contains($r['body'], '<KeyCfg')) {
                throw new RuntimeException(VDM10Isapi::errorText($r));
            }
            $xml = VDM10SubModules::xmlSet($r['body'], 'KeyCfg', 'callNumber', (string) $Room);
            if ($Label !== '' || str_contains($xml, '<customInfo')) {
                $xml = VDM10SubModules::xmlSet($xml, 'KeyCfg', 'customInfo', $Label);
            }
            $this->writeChecked('PUT', $path, $xml);
            $msg = $this->Translate('Saved');
            // Hauptmodul-Taster der VDM10 (V3.7.1) nehmen die Beschriftung an, speichern sie aber nicht.
            $stored = VDM10SubModules::parseKeys($this->isapi()->request('GET', $path, '', 5)['body'])[0]['label'] ?? '';
            if ($Label !== $stored) {
                $msg .= "\n" . $this->Translate('The door station keeps labels only for buttons of a touch display.');
            }
        } catch (Throwable $e) {
            $msg = $this->Translate('Error') . ': ' . $e->getMessage();
        }
        $this->refreshKeys();
        $this->UpdateFormField('Keys', 'values', (string) json_encode($this->keyRows()));
        return $msg;
    }
}
