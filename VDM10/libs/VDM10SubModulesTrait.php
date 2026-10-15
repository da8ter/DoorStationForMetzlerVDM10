<?php

declare(strict_types=1);

/**
 * Erweiterungsmodule am RS-485-Bus der Türstation: Status je Modul (Variable + Log), Helligkeit
 * der Display-Module als Variable, Klingeltasten (keyCfg) und welche Taste geklingelt hat.
 * Getestet mit dem Finger-/Kartenleser DS-KD-MFB; Display-, Tasten- und Info-Module sind nach
 * der Weboberfläche der Türstation umgesetzt (ohne Hardware-Test).
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10SubModulesTrait
{
    private const PATH_SUBMODULES = '/ISAPI/VideoIntercom/SubModules?format=json';
    private const PATH_SUBMODULE_CFG = '/ISAPI/VideoIntercom/SubModules/%d/Configurations%s?format=json';
    private const PATH_KEYS = '/ISAPI/VideoIntercom/keyCfg';
    private const PATH_VIRTUAL_KEYS = '/ISAPI/VideoIntercom/keyCfg?type=virtualKey';
    private const PATH_KEY = '/ISAPI/VideoIntercom/keyCfg/%d';
    private const PATH_CALLER_INFO = '/ISAPI/VideoIntercom/callerInfo?format=json';
    private const POSITION_SUBMODULE = 300;
    private const POSITION_DISPLAY = 340;
    private const SUBMODULE_IDENTS = '/^(SUBMODULE_\d+|DISPLAY\d+_BRIGHTNESS|RING_BUTTON|DISPLAY_TEXT)$/';

    private function registerSubModuleProperties(): void
    {
        $this->registerNotifyProperties(); // „Nicht stören“, Push, Uhr (VDM10NotifyTrait)
        $this->RegisterPropertyBoolean('SubModuleVariables', true);
        $this->RegisterAttributeString('SubModules', ''); // '' = noch nie gelesen
        $this->RegisterAttributeString('Keys', '[]');
    }

    /** In ApplyChanges(): Variablen anlegen bzw. (Option aus) entfernen; $query = Türstation erreichbar. */
    private function maintainSubModules(bool $query): void
    {
        $this->maintainNotify();
        if (!$this->ReadPropertyBoolean('SubModuleVariables')) {
            foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
                $ident = IPS_GetObject($child)['ObjectIdent'];
                if (IPS_VariableExists($child) && preg_match(self::SUBMODULE_IDENTS, $ident)) {
                    $this->UnregisterVariable($ident);
                }
            }
        }
        if ($query) {
            $this->refreshSubModules();
            $this->refreshKeys();
        }
    }

    /** @return array<int, array{id:int,type:string,status:string,version:string}> zuletzt gelesene Modulliste */
    private function subModules(): array
    {
        $list = json_decode($this->ReadAttributeString('SubModules'), true);
        return is_array($list) ? $list : [];
    }

    /**
     * Welche Zugangsarten die Türstation hat (Fingerabdruck, Karte, PIN). Grundlage ist die
     * zuletzt gelesene Modulliste; wurde sie noch nie gelesen, wird alles angeboten.
     *
     * @return array{finger:bool,card:bool,pin:bool}
     */
    private function credentials(): array
    {
        if ($this->ReadAttributeString('SubModules') === '' && $this->ReadPropertyString('Host') !== '' && $this->GetStatus() === IS_ACTIVE) {
            $this->refreshSubModules();
        }
        $list = json_decode($this->ReadAttributeString('SubModules'), true);
        return VDM10SubModules::credentials(is_array($list) ? $list : null, $this->ReadPropertyBoolean('SecurityModule'));
    }

    /** Modulliste lesen, Statusvariablen und Display-Helligkeit aktualisieren (aus RefreshStatus). */
    private function refreshSubModules(): void
    {
        $r = $this->isapi()->request('GET', self::PATH_SUBMODULES, '', 5);
        $modules = $r['code'] === 200 ? VDM10SubModules::parseList($r['body']) : null;
        if ($modules === null) {
            return; // Türstation ohne Modulbus oder nicht erreichbar: letzten Stand behalten
        }
        if ((string) json_encode($modules) !== $this->ReadAttributeString('SubModules')) {
            $this->WriteAttributeString('SubModules', (string) json_encode($modules));
            $this->tileChanged(); // Leser hinzugekommen oder entfernt: Kachel anpassen
        }
        if (!$this->ReadPropertyBoolean('SubModuleVariables')) {
            return;
        }
        $present = [];
        foreach ($modules as $m) {
            $ident = 'SUBMODULE_' . $m['id'];
            $present[] = $ident;
            if (!@$this->GetIDForIdent($ident)) {
                $this->MaintainVariable($ident, $this->moduleTitle($m), VARIABLETYPE_BOOLEAN,
                    $this->boolPresentation('Network', 'Offline', 'Online', 0xFF0000, 0x00C000), self::POSITION_SUBMODULE + $m['id'], true);
            }
            $this->SetValue($ident, $m['status'] === 'online');
            if (VDM10SubModules::isDisplay($m['type']) && $m['status'] === 'online') {
                $this->refreshDisplay($m);
            }
        }
        $this->refreshDisplayText();
        // Vom Bus verschwundene Module als offline melden; die Variable bleibt (Name gehört dem Nutzer).
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $ident = IPS_GetObject($child)['ObjectIdent'];
            if (str_starts_with($ident, 'SUBMODULE_') && !in_array($ident, $present, true) && IPS_VariableExists($child)) {
                $this->SetValue($ident, false);
            }
        }
    }

    /** Helligkeit eines Display-Moduls als Schieberegler (Bereich aus den Fähigkeiten). */
    private function refreshDisplay(array $module): void
    {
        $caps = $this->displayCaps($module['id']);
        if ($caps === null || $caps['brightness'] === null) {
            return;
        }
        $ident = 'DISPLAY' . $module['id'] . '_BRIGHTNESS';
        if (!@$this->GetIDForIdent($ident)) {
            $this->MaintainVariable($ident, sprintf($this->Translate('%s brightness'), $this->moduleTitle($module)), VARIABLETYPE_INTEGER, [
                'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'ICON' => 'Sun',
                'MIN' => $caps['brightness'][0], 'MAX' => $caps['brightness'][1], 'STEP' => 1,
            ], self::POSITION_DISPLAY + $module['id'], true);
            $this->EnableAction($ident);
        }
        $cfg = $this->displayConfig($module['id']);
        if ($cfg !== []) {
            $this->SetValue($ident, $cfg['brightness']);
        }
    }

    private function moduleTitle(array $module): string
    {
        return sprintf($this->Translate('%s (module %d)'), $this->Translate(VDM10SubModules::name($module['type'])), $module['id']);
    }

    /** Fähigkeiten eines Display-Moduls, eine Stunde im Puffer; null = keine Einstellungen. */
    private function displayCaps(int $id): ?array
    {
        $cached = json_decode($this->GetBuffer('Caps' . $id), true);
        if (is_array($cached) && time() - (int) $cached['t'] < 3600) {
            return $cached['caps'];
        }
        $r = $this->isapi()->request('GET', sprintf(self::PATH_SUBMODULE_CFG, $id, '/capabilities'), '', 5);
        $caps = $r['code'] === 200 && str_contains($r['body'], 'SubModulesCfg') ? VDM10SubModules::parseCaps($r['body']) : null;
        $this->SetBuffer('Caps' . $id, (string) json_encode(['t' => time(), 'caps' => $caps]));
        return $caps;
    }

    private function displayConfig(int $id): array
    {
        $r = $this->isapi()->request('GET', sprintf(self::PATH_SUBMODULE_CFG, $id, ''), '', 5);
        return $r['code'] === 200 ? VDM10SubModules::parseConfig($r['body']) : [];
    }

    /** Einstellungen eines Display-Moduls ändern: aktuellen Stand lesen, Änderungen einsetzen, schreiben. */
    private function writeDisplay(int $id, array $changes): void
    {
        $caps = $this->displayCaps($id);
        $current = $this->displayConfig($id);
        if ($caps === null || $current === []) {
            throw new RuntimeException($this->Translate('This module has no settings'));
        }
        $body = VDM10SubModules::payload($caps, array_merge($current, $changes));
        $this->writeChecked('PUT', sprintf(self::PATH_SUBMODULE_CFG, $id, ''), (string) json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    /** RequestAction für die Modul-Variablen; false = Ident gehört nicht hierher. */
    private function subModuleAction(string $Ident, mixed $Value): bool
    {
        if (!preg_match('/^DISPLAY(\d+)_BRIGHTNESS$/', $Ident, $m)) {
            return false;
        }
        $this->writeDisplay((int) $m[1], ['brightness' => (int) $Value]);
        $this->SetValue($Ident, (int) $Value);
        return true;
    }

    /** @return array<int, array{id:int,module:string,method:string,room:int,label:string,virtual:bool}> */
    private function keys(): array
    {
        $keys = json_decode($this->ReadAttributeString('Keys'), true);
        return is_array($keys) ? $keys : [];
    }

    /** Klingeltasten lesen; ab zwei Tasten zeigt RING_BUTTON, welche zuletzt geklingelt hat. */
    private function refreshKeys(): void
    {
        $api = $this->isapi();
        $physical = $api->request('GET', self::PATH_KEYS, '', 5);
        if ($physical['code'] !== 200) {
            return;
        }
        $virtual = $api->request('GET', self::PATH_VIRTUAL_KEYS, '', 5);
        $keys = VDM10SubModules::mergeKeys(VDM10SubModules::parseKeys($physical['body']),
            $virtual['code'] === 200 ? VDM10SubModules::parseKeys($virtual['body']) : []);
        $this->WriteAttributeString('Keys', (string) json_encode($keys, JSON_UNESCAPED_UNICODE));
        if (!$this->ReadPropertyBoolean('SubModuleVariables') || count($keys) < 2) {
            return; // eine Taste: „Klingel“ sagt schon alles
        }
        $interval = fn (int $value, string $text): array => [
            'IntervalMinValue' => $value, 'IntervalMaxValue' => $value, 'ConversionFactor' => 1,
            'ConstantActive' => true, 'ConstantValue' => $text, 'PrefixActive' => false, 'PrefixValue' => '',
            'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0,
            'IconActive' => false, 'IconValue' => '', 'ColorActive' => false, 'ColorValue' => -1,
            'ContentColorActive' => false, 'ContentColorValue' => -1,
        ];
        $intervals = [$interval(0, '-')];
        foreach ($keys as $k) {
            $intervals[] = $interval($k['id'], $this->keyName($k));
        }
        $this->MaintainVariable('RING_BUTTON', $this->Translate('Bell button'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Bell', 'DIGITS' => 0,
            'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($intervals, JSON_UNESCAPED_UNICODE),
        ], 25, true);
    }

    private function keyName(array $key): string
    {
        return VDM10SubModules::keyLabel($key, $this->Translate('Button %d'), $this->Translate('room %d'));
    }

    /** Beim Klingeln: Taste bestimmen (falls gemeldet), Variable setzen; liefert den Namen fürs Log. */
    private function ringButton(int $key, int $room): string
    {
        $keys = $this->keys();
        $id = VDM10SubModules::keyForEvent($keys, $key, $room);
        if ($key > 0 || $room > 0) {
            $this->SendDebug('Ring', sprintf('key %d room %d -> button %d', $key, $room, $id), 0);
        }
        if ($id === 0 || count($keys) < 2) {
            return '';
        }
        if (@$this->GetIDForIdent('RING_BUTTON')) {
            $this->SetValue('RING_BUTTON', $id);
        }
        foreach ($keys as $k) {
            if ($k['id'] === $id) {
                return $this->keyName($k);
            }
        }
        return '';
    }

    /**
     * Beim Klingeln callerInfo der Türstation lesen (wie Hikvision-Addons und node-red-contrib-
     * hikvision-ultimate): devNo bzw. buttonNo/keyNo/callButtonNo benennen dort die gedrückte
     * Taste. Ob die VDM10 sie füllt, ist offen; die Antwort steht deshalb immer im Debug.
     */
    private function callerButton(string $when): void
    {
        $r = $this->isapi()->request('GET', self::PATH_CALLER_INFO, '', 3);
        $this->SendDebug('CallerInfo', $when . ': ' . preg_replace('/\s+/', ' ', $r['body']), 0);
        $info = json_decode($r['body'], true)['CallerInfo'] ?? null;
        if (!is_array($info)) {
            return;
        }
        $found = VDM10Events::buttonInfo($info);
        $dev = (int) ($info['devNo'] ?? 0);
        $label = $this->ringButton($found['key'] ?: $dev, $found['room'] ?: $dev);
        if ($label !== '') {
            $this->annotateLastRing($label);
        }
    }

    /** Für die Kachel: Anzahl Module und davon offline. */
    private function tileModules(): array
    {
        $modules = $this->subModules();
        return ['total' => count($modules), 'offline' => count(array_filter($modules, static fn (array $m): bool => $m['status'] !== 'online'))];
    }
}
