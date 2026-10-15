<?php

declare(strict_types=1);

/**
 * „Nicht stören“ als Variable, Push-Nachrichten über eine Kachel-Visualisierung und die Uhr der
 * Türstation (Abweichung messen, bei Bedarf stellen).
 *
 * Push: VISU_PostNotificationEx kann kein Bild senden; der Tipp auf die Nachricht öffnet die Kachel
 * dieser Instanz (TargetID), dort ist das Bild. Die Instanz muss in der Visualisierung erreichbar sein.
 *
 * Uhr: Die Türstation synchronisiert per NTP über einen Hostnamen; ohne funktionierende Namens-
 * auflösung driftet sie. Stellen = kurz auf „manuell“ mit der Symcon-Zeit, danach den gesicherten
 * Ist-Stand (NTP) zurückschreiben – die Konfiguration bleibt unverändert.
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10NotifyTrait
{
    private const TILE_VISU_GUID = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const PATH_TIME = '/ISAPI/System/time';
    private const CLOCK_TOLERANCE = 30;      // s Abweichung, ab der gestellt wird
    private const CLOCK_MIN_INTERVAL = 3600; // s zwischen zwei automatischen Korrekturen
    private const PUSH_REPEAT = 300;         // s, in denen dieselbe Störung nicht erneut gemeldet wird

    /** Push-Arten je Log-Art: [Option, Ton, Symbol] */
    private const PUSH_KINDS = [
        'ring'           => ['NotifyRing', 'bell', 'Bell'],
        'access_denied'  => ['NotifyAccessDenied', 'buzzer', 'Lock'],
        'tamper'         => ['NotifyProblems', 'alarm', 'Warning'],
        'door_alarm'     => ['NotifyProblems', 'alarm', 'Warning'],
        'offline'        => ['NotifyProblems', 'alarm', 'Warning'],
        'sip_off'        => ['NotifyProblems', 'alarm', 'Warning'],
        'sm_offline'     => ['NotifyProblems', 'alarm', 'Warning'],
        'module_offline' => ['NotifyProblems', 'alarm', 'Warning'],
    ];

    /** In Create(): Optionen und Variablen. */
    private function registerNotifyProperties(): void
    {
        $this->RegisterPropertyInteger('NotifyVisu', 0);
        $this->RegisterPropertyBoolean('NotifyRing', true);
        $this->RegisterPropertyBoolean('NotifyRingDnd', false);
        $this->RegisterPropertyBoolean('NotifyAccessDenied', false);
        $this->RegisterPropertyBoolean('NotifyProblems', true);
        $this->RegisterPropertyBoolean('ClockSync', true);
        $this->RegisterAttributeInteger('ClockSynced', 0);
        $this->RegisterVariableBoolean('DND', $this->Translate('Do not disturb'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON_TRUE' => 'Sleep', 'ICON_FALSE' => 'Bell', 'USE_ICON_FALSE' => true, 'USAGE_TYPE' => 0,
        ], 26);
        $this->EnableAction('DND');
        $this->RegisterVariableInteger('CLOCK_DRIFT', $this->Translate('Clock deviation of the door station'), [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Clock', 'SUFFIX' => ' s', 'DIGITS' => 0,
        ], 196);
    }

    /** In ApplyChanges(): Referenz auf die gewählte Visualisierung. */
    private function maintainNotify(): void
    {
        foreach ($this->GetReferenceList() as $ref) {
            if (IPS_InstanceExists($ref) && IPS_GetInstance($ref)['ModuleInfo']['ModuleID'] === self::TILE_VISU_GUID) {
                $this->UnregisterReference($ref);
            }
        }
        if ($this->notifyVisu() > 0) {
            $this->RegisterReference($this->notifyVisu());
        }
    }

    private function notifyVisu(): int
    {
        $id = $this->ReadPropertyInteger('NotifyVisu');
        return $id > 0 && IPS_InstanceExists($id) && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::TILE_VISU_GUID ? $id : 0;
    }

    /** Aus logEvent(): Push zur Log-Art, falls gewünscht. */
    private function notifyEvent(string $kind, string $text, array $extra): void
    {
        $cfg = self::PUSH_KINDS[$kind] ?? null;
        if ($cfg === null || $this->notifyVisu() === 0 || !$this->ReadPropertyBoolean($cfg[0])) {
            return;
        }
        if ($kind === 'ring' && !$this->ReadPropertyBoolean('NotifyRingDnd') && @$this->GetIDForIdent('DND') && $this->GetValue('DND')) {
            return; // „Nicht stören“: auch keine Klingel-Push, sofern nicht ausdrücklich gewünscht
        }
        if ($cfg[0] === 'NotifyProblems') {
            $sent = json_decode($this->GetBuffer('PushSent'), true) ?: [];
            if (time() - (int) ($sent[$kind] ?? 0) < self::PUSH_REPEAT) {
                return; // flatternde Verbindung nicht im Minutentakt melden
            }
            $sent[$kind] = time();
            $this->SetBuffer('PushSent', (string) json_encode($sent));
        }
        $name = (string) ($extra['name'] ?? '');
        $this->push($kind === 'ring' ? $this->Translate('Someone is ringing') : $text . ($name !== '' ? ': ' . $name : ''), $cfg[1], $cfg[2]);
    }

    private function push(string $text, string $sound, string $icon): bool
    {
        $title = mb_substr(IPS_GetName($this->InstanceID), 0, 32);
        try {
            $ok = @VISU_PostNotificationEx($this->notifyVisu(), $title, mb_substr($text, 0, 256), $icon, $sound, $this->InstanceID);
        } catch (Throwable $e) {
            $ok = false;
            $this->SendDebug('Push', $e->getMessage(), 0);
        }
        $this->SendDebug('Push', ($ok === false ? 'failed: ' : 'sent: ') . $text, 0);
        return $ok !== false;
    }

    /** Formular-Button: Test-Nachricht. */
    public function UINotifyTest(): string
    {
        if ($this->notifyVisu() === 0) {
            return $this->Translate('Please select a tile visualization and apply the changes first.');
        }
        return $this->push($this->Translate('Test message from the door station'), 'bell', 'Bell')
            ? $this->Translate('Test message sent.')
            : $this->Translate('The visualization did not accept the message (subscription, registered devices, is the instance reachable in the visualization?).');
    }

    /** Abweichung der Türstation in Sekunden (Türstation − Symcon); null = nicht lesbar. */
    private function clockDrift(): ?int
    {
        $t0 = microtime(true);
        $r = $this->isapi()->request('GET', self::PATH_TIME, '', 5);
        $t1 = microtime(true);
        if ($r['code'] !== 200 || !preg_match('#<localTime>([^<]+)</localTime>#', $r['body'], $m) || ($device = strtotime($m[1])) === false) {
            return null;
        }
        return (int) round($device - ($t0 + $t1) / 2);
    }

    /** Aus RefreshStatus(): messen, anzeigen, bei Bedarf stellen. */
    private function refreshClock(): void
    {
        $drift = $this->clockDrift();
        if ($drift === null) {
            return;
        }
        $this->SetValue('CLOCK_DRIFT', $drift);
        if ($this->ReadPropertyBoolean('ClockSync') && abs($drift) >= self::CLOCK_TOLERANCE
            && time() - $this->ReadAttributeInteger('ClockSynced') >= self::CLOCK_MIN_INTERVAL) {
            $this->SyncClock();
        }
    }

    /** Uhr der Türstation auf die Symcon-Zeit stellen; Zeitmodus (NTP) bleibt erhalten. */
    public function SyncClock(): int
    {
        $before = $this->clockDrift();
        $r = $this->isapi()->request('GET', self::PATH_TIME);
        if ($r['code'] !== 200 || !str_contains($r['body'], '<timeMode>')) {
            throw new RuntimeException(VDM10Isapi::errorText($r));
        }
        $manual = VDM10SubModules::xmlSet(VDM10SubModules::xmlSet($r['body'], 'Time', 'timeMode', 'manual'), 'Time', 'localTime', date('Y-m-d\TH:i:sP'));
        $this->writeChecked('PUT', self::PATH_TIME, $manual);
        $this->writeChecked('PUT', self::PATH_TIME, $r['body']); // gesicherten Ist-Stand (z. B. NTP) zurück
        $this->WriteAttributeInteger('ClockSynced', time());
        $after = $this->clockDrift();
        if ($after !== null) {
            $this->SetValue('CLOCK_DRIFT', $after);
        }
        $this->logEvent('clock_set', ['name' => sprintf($this->Translate('was off by %d s'), (int) $before)]);
        return (int) $after;
    }

    /** RequestAction für „Nicht stören“; false = Ident gehört nicht hierher. */
    private function notifyAction(string $Ident, mixed $Value): bool
    {
        if ($Ident !== 'DND') {
            return false;
        }
        $this->SetDoNotDisturb((bool) $Value);
        return true;
    }

    /** Aus RefreshStatus(): Zustand „Nicht stören“ von der Türstation. */
    private function refreshDoNotDisturb(): void
    {
        $dnd = $this->readDoNotDisturb();
        if ($dnd !== null) {
            $this->SetValue('DND', $dnd);
        }
    }
}
