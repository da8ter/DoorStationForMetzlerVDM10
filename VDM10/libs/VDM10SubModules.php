<?php

declare(strict_types=1);

/**
 * Reine Hilfsfunktionen für die Erweiterungsmodule der Türstation (RS-485-Bus): Modulnamen,
 * Klingeltasten (keyCfg-XML) und die Einstellungen der Display-Module. Ohne Symcon testbar
 * (tests/SubModulesTest.php).
 *
 * Quellen: Antworten der VDM10 (Firmware V3.7.1) und die Weboberfläche der Türstation
 * (SubModules/<id>/Configurations, Felder wie im Dialog „Submodul-Konfiguration“).
 */
final class VDM10SubModules
{
    /** Modultyp => englischer Name (Übersetzung über locale.json). */
    public const NAMES = [
        'DS-KD-KK'   => 'Name plate module',
        'DS-KD-AL8'  => 'Button module',
        'DS-KD-ALJ5' => 'Button module',
        'DS-KD-K12'  => 'Button module',
        'DS-KD-KP'   => 'Keypad module',
        'DS-KD-M'    => 'Card reader module',
        'DS-KD-E'    => 'Card reader module',
        'DS-KD-SG'   => 'Indicator module',
        'DS-KD-DIS'  => 'Display module',
        'DS-KD-INFO' => 'Info module',
        'DS-KD-TD'   => 'Touch display module',
        'DS-KD-TDM'  => 'Touch display module',
        'DS-KD-TDE'  => 'Touch display module',
        'DS-KD-MFB'  => 'Fingerprint and card reader module',
        'DS-KD-MFB/S' => 'Fingerprint and card reader module',
        'DS-KD-PMR'  => 'Extension module',
    ];

    /** Module mit eigenen Einstellungen (Helligkeit, Tastenton, Oberfläche). */
    public const DISPLAY_TYPES = ['DS-KD-TD', 'DS-KD-TDM', 'DS-KD-TDE', 'DS-KD-DIS', 'DS-KD-INFO'];

    /** Startbildschirm der Touch-Module und Darstellung. */
    public const START_SCREENS = ['dial' => 'Dial pad', 'contacts' => 'Contacts', 'callButton' => 'Call buttons', 'card' => 'Card'];
    public const THEMES = ['dark' => 'Dark', 'light' => 'Light'];

    public static function name(string $type): string
    {
        return self::NAMES[strtoupper($type)] ?? 'Extension module';
    }

    public static function isDisplay(string $type): bool
    {
        return in_array(strtoupper($type), self::DISPLAY_TYPES, true);
    }

    /**
     * Antwort von GET /ISAPI/VideoIntercom/SubModules?format=json.
     *
     * @return array<int, array{id:int,type:string,status:string,version:string}>|null null = keine gültige Antwort
     */
    public static function parseList(string $body): ?array
    {
        $list = json_decode($body, true)['SubModules'] ?? null;
        if (!is_array($list)) {
            return null;
        }
        $modules = [];
        foreach ($list as $m) {
            $id = (int) ($m['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $modules[] = ['id' => $id, 'type' => (string) ($m['moduleType'] ?? ''), 'status' => (string) ($m['status'] ?? 'offline'),
                'version' => (string) ($m['version'] ?? '')];
        }
        usort($modules, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $modules;
    }

    /**
     * KeyCfgList (GET keyCfg bzw. keyCfg?type=virtualKey).
     *
     * @return array<int, array{id:int,module:string,method:string,room:int,label:string}>
     */
    public static function parseKeys(string $xml): array
    {
        $keys = [];
        if (!preg_match_all('#<KeyCfg\b[^>]*>(.*?)</KeyCfg>#s', $xml, $blocks)) {
            return [];
        }
        foreach ($blocks[1] as $block) {
            $get = static fn (string $tag): string => preg_match('#<' . $tag . '>([^<]*)</' . $tag . '>#', $block, $m) ? html_entity_decode($m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8') : '';
            $id = (int) $get('id');
            if ($id < 1) {
                continue;
            }
            $keys[] = ['id' => $id, 'module' => $get('module'), 'method' => $get('callMethod'), 'room' => (int) $get('callNumber'), 'label' => $get('customInfo')];
        }
        return $keys;
    }

    /**
     * Physische und virtuelle Tasten zusammenführen; virtuelle (Touch-Display) nur, wenn sie
     * nicht schon als physische Taste mit gleicher ID und gleichem Inhalt geliefert wurden.
     *
     * @return array<int, array{id:int,module:string,method:string,room:int,label:string,virtual:bool}>
     */
    public static function mergeKeys(array $physical, array $virtual): array
    {
        $out = [];
        foreach ($physical as $k) {
            $out[$k['id']] = $k + ['virtual' => false];
        }
        foreach ($virtual as $k) {
            if (!isset($out[$k['id']])) {
                $out[$k['id']] = $k + ['virtual' => true];
            }
        }
        ksort($out);
        return array_values($out);
    }

    /** Anzeigename einer Taste: Beschriftung, sonst „Taste n“ (+ Zimmernummer). */
    public static function keyLabel(array $key, string $buttonText, string $roomText): string
    {
        if (trim((string) $key['label']) !== '') {
            return trim((string) $key['label']);
        }
        $text = sprintf($buttonText, $key['id']);
        return (int) $key['room'] > 0 ? $text . ' · ' . sprintf($roomText, $key['room']) : $text;
    }

    /** Taste zu einem Ereignis: zuerst Tastennummer, sonst Zimmernummer. 0 = unbekannt. */
    public static function keyForEvent(array $keys, int $key, int $room): int
    {
        $ids = array_column($keys, 'id');
        if ($key > 0 && in_array($key, $ids, true)) {
            return $key;
        }
        if ($room > 0) {
            foreach ($keys as $k) {
                if ((int) $k['room'] === $room) {
                    return (int) $k['id'];
                }
            }
        }
        return 0;
    }

    /** Element im XML ersetzen oder (fehlt es) vor dem schließenden Wurzel-Tag ergänzen. */
    public static function xmlSet(string $xml, string $root, string $tag, string $value): string
    {
        $text = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $count = 0;
        $xml = (string) preg_replace('#<' . $tag . '>[^<]*</' . $tag . '>|<' . $tag . '\s*/>#', '<' . $tag . '>' . $text . '</' . $tag . '>', $xml, 1, $count);
        if ($count === 0) {
            $pos = strrpos($xml, '</' . $root . '>');
            if ($pos === false) {
                throw new RuntimeException('Element ' . $root . ' not found');
            }
            $xml = substr($xml, 0, $pos) . '<' . $tag . '>' . $text . '</' . $tag . '>' . "\r\n" . substr($xml, $pos);
        }
        return $xml;
    }

    /**
     * Fähigkeiten eines Display-Moduls (GET SubModules/<id>/Configurations/capabilities).
     *
     * @return array{brightness:?array{0:int,1:int},tone:bool,callCenter:bool,callCenterName:?array{0:int,1:int},start:array<int,string>,themes:array<int,string>,ringDirectly:bool}
     */
    public static function parseCaps(string $body): array
    {
        $c = json_decode($body, true)['SubModulesCfg'] ?? [];
        $c = is_array($c) ? $c : [];
        $range = static fn ($node): ?array => is_array($node) && isset($node['@min'], $node['@max']) ? [(int) $node['@min'], (int) $node['@max']] : null;
        $opts = static fn ($node): array => is_array($node) && isset($node['@opt'])
            ? array_values(array_filter(array_map('strval', is_array($node['@opt']) ? $node['@opt'] : explode(',', (string) $node['@opt'])), 'strlen')) : [];
        return [
            'brightness'     => $range($c['Backlight']['brightness'] ?? null),
            'tone'           => isset($c['enableKeypadTone']),
            'callCenter'     => isset($c['enableCallCenter']),
            'callCenterName' => $range($c['customCallCenterName'] ?? null),
            'start'          => array_values(array_intersect($opts($c['customInterface'] ?? null), array_keys(self::START_SCREENS))),
            'themes'         => array_values(array_intersect($opts($c['themeMode'] ?? null), array_keys(self::THEMES))),
            'ringDirectly'   => isset($c['ringDirectlyEnabled']),
        ];
    }

    /** Aktuelle Werte (GET SubModules/<id>/Configurations). */
    public static function parseConfig(string $body): array
    {
        $c = json_decode($body, true)['SubModulesCfg'] ?? null;
        if (!is_array($c)) {
            return [];
        }
        return [
            'brightness'     => (int) ($c['Backlight']['brightness'] ?? 0),
            'tone'           => (bool) ($c['enableKeypadTone'] ?? false),
            'callCenter'     => (bool) ($c['enableCallCenter'] ?? false),
            'callCenterName' => (string) ($c['customCallCenterName'] ?? ''),
            'start'          => (string) ($c['customInterface'] ?? ''),
            'theme'          => (string) ($c['themeMode'] ?? ''),
            'ringDirectly'   => (bool) ($c['ringDirectlyEnabled'] ?? false),
        ];
    }

    /**
     * Nutzlast für PUT SubModules/<id>/Configurations wie die Weboberfläche: nur Felder, die das
     * Modul laut Fähigkeiten kennt; Werte auf die erlaubten Bereiche begrenzt.
     */
    public static function payload(array $caps, array $values): array
    {
        $cfg = [];
        if ($caps['brightness'] !== null) {
            $cfg['Backlight'] = ['brightness' => max($caps['brightness'][0], min($caps['brightness'][1], (int) $values['brightness']))];
        }
        if ($caps['tone']) {
            $cfg['enableKeypadTone'] = (bool) $values['tone'];
        }
        if ($caps['themes'] !== [] && in_array($values['theme'], $caps['themes'], true)) {
            $cfg['themeMode'] = $values['theme'];
        }
        if ($caps['start'] !== [] && in_array($values['start'], $caps['start'], true)) {
            $cfg['customInterface'] = $values['start'];
        }
        if ($caps['callCenter']) {
            $cfg['enableCallCenter'] = (bool) $values['callCenter'];
        }
        if ($caps['callCenterName'] !== null) {
            $name = mb_substr(trim((string) $values['callCenterName']), 0, max(0, $caps['callCenterName'][1]));
            if (mb_strlen($name) >= $caps['callCenterName'][0]) {
                $cfg['customCallCenterName'] = $name;
            }
        }
        if ($caps['ringDirectly']) {
            $cfg['ringDirectlyEnabled'] = (bool) $values['ringDirectly'];
        }
        return ['SubModulesCfg' => $cfg];
    }
}
