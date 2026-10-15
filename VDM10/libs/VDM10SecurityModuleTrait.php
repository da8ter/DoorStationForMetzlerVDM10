<?php

declare(strict_types=1);

/**
 * Metzler-Sicherheitsmodul (Metzler-SM, Hikvision Secure Door Control Unit) und Türkontakt.
 * Das Sicherheitsmodul hängt per RS-485 an der Türstation und schaltet den Türöffner im
 * geschützten Innenbereich; Türöffnen läuft unverändert über die Türstation. Neu sind Online-Status,
 * Türkontakt (am Sicherheitsmodul oder an einem Eingang der Türstation) und „Tür zu lange offen“.
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10SecurityModuleTrait
{
    private const PATH_SM_STATUS = '/ISAPI/AccessControl/DoorSecurityModule/moduleStatus';
    private const PATH_ACS_CFG = '/ISAPI/AccessControl/AcsCfg?format=json';
    private const EVENT_SM_ONLINE = 1040;
    private const EVENT_SM_OFFLINE = 1041;

    /** In Create() aufrufen. */
    private function registerSecurityModuleProperties(): void
    {
        $this->RegisterPropertyBoolean('SecurityModule', false);
        $this->RegisterPropertyBoolean('DoorContact', false);
    }

    /** In ApplyChanges() aufrufen. */
    private function maintainSecurityModuleVariables(): void
    {
        $sm = $this->ReadPropertyBoolean('SecurityModule');
        $contact = $this->ReadPropertyBoolean('DoorContact');
        $doors = $this->ReadPropertyInteger('DoorCount');
        $this->MaintainVariable('SECURITY_MODULE', $this->Translate('Security module'), VARIABLETYPE_BOOLEAN,
            $this->boolPresentation('Lock', 'Offline', 'Online', 0xFF0000, 0x00C000), 125, $sm);
        foreach ([1, 2] as $door) {
            $keep = $contact && $door <= $doors;
            $this->MaintainVariable('DOOR' . $door . '_CONTACT', sprintf($this->Translate('Door %d'), $door), VARIABLETYPE_BOOLEAN,
                $this->boolPresentation('Door', 'Closed', 'Open', 0x00C000, 0xFF8000), 41 + $door * 10, $keep);
            $this->MaintainVariable('DOOR' . $door . '_ALARM', sprintf($this->Translate('Door %d alarm'), $door), VARIABLETYPE_BOOLEAN,
                $this->boolPresentation('Warning', 'OK', 'Alarm', 0x00C000, 0xFF0000), 42 + $door * 10, $keep);
        }
    }

    /** Online-Status des Sicherheitsmoduls lesen (aus RefreshStatus). */
    private function refreshSecurityModule(): void
    {
        if (!$this->ReadPropertyBoolean('SecurityModule')) {
            return;
        }
        $xml = $this->isapi()->request('GET', self::PATH_SM_STATUS, '', 5)['body'];
        if (preg_match('#<onlineStatus>(\d)</onlineStatus>#', $xml, $m)) {
            $this->SetValue('SECURITY_MODULE', $m[1] === '1');
        }
        if (preg_match('#<offlineReason>([^<]*)</offlineReason>#', $xml, $r) && $m[1] !== '1') {
            $this->SendDebug('SecurityModule', 'offline: ' . $r[1], 0);
        }
    }

    /** Türkontakt aus dem Türstatus der Türstation (falls sie ihn meldet). */
    private function applyMagneticStatus(array $workStatus): void
    {
        if (!$this->ReadPropertyBoolean('DoorContact') || !isset($workStatus['magneticStatus']) || !is_array($workStatus['magneticStatus'])) {
            return;
        }
        foreach ([1, 2] as $door) {
            if (@$this->GetIDForIdent('DOOR' . $door . '_CONTACT') && isset($workStatus['magneticStatus'][$door - 1])) {
                $this->SetValue('DOOR' . $door . '_CONTACT', (int) $workStatus['magneticStatus'][$door - 1] === 1);
            }
        }
    }

    /**
     * Ereignisse des Türkontakts und des Sicherheitsmoduls. Liefert true, wenn behandelt.
     *
     * @param array{kind:string,major:int,minor:int,door:int} $e
     */
    private function securityModuleEvent(array $e): bool
    {
        $door = $e['door'] >= 1 && $e['door'] <= 2 ? $e['door'] : 1;
        $set = function (string $ident, bool $value): void {
            if (@$this->GetIDForIdent($ident)) {
                $this->SetValue($ident, $value);
            }
        };
        switch ($e['kind']) {
            case VDM10Events::KIND_DOOR_OPEN:
                $set('DOOR' . $door . '_CONTACT', true);
                return true;
            case VDM10Events::KIND_DOOR_CLOSED:
                $set('DOOR' . $door . '_CONTACT', false);
                $set('DOOR' . $door . '_ALARM', false);
                return true;
            case VDM10Events::KIND_DOOR_ALARM:
                $set('DOOR' . $door . '_ALARM', true);
                return true;
        }
        if ($e['major'] === 1 && $e['minor'] === 0x1049) {
            $set('DOOR' . $door . '_ALARM', false);
            return true;
        }
        if ($e['major'] === 2 && in_array($e['minor'], [self::EVENT_SM_ONLINE, self::EVENT_SM_OFFLINE], true)) {
            $set('SECURITY_MODULE', $e['minor'] === self::EVENT_SM_ONLINE);
            return true;
        }
        return false;
    }

    /** Sabotagealarm des Sicherheitsmoduls (AcsCfg) lesen bzw. setzen; liefert true bei Neustartbedarf. */
    private function securityModuleTamperAlarm(?bool $enable = null): bool
    {
        $cfg = $this->json($this->isapi()->request('GET', self::PATH_ACS_CFG, '', 5));
        $now = (bool) ($cfg['AcsCfg']['securityModuleTamperAlarmEnabled'] ?? false);
        if ($enable === null) {
            return $now;
        }
        if ($enable === $now) {
            return false;
        }
        $cfg['AcsCfg']['securityModuleTamperAlarmEnabled'] = $enable;
        return $this->writeChecked('PUT', self::PATH_ACS_CFG, (string) json_encode($cfg));
    }
}
