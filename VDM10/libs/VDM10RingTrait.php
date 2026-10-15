<?php

declare(strict_types=1);

/**
 * Klingeln und Rufstatus: Die Türstation meldet das Klingeln nicht im Alert-Stream, sondern nur
 * über den Rufstatus (callStatus), den der Timer „Poll“ abfragt. Dazu Entprellung, Zurücksetzen
 * und Fehlerbehandlung der Abfrage (falsches Passwort → keine Dauerabfragen, Wiederholung).
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10RingTrait
{
    public function UpdateCallStatus(): string
    {
        $r = $this->isapi()->request('GET', VDM10Isapi::PATH_CALL_STATUS, '', 5);
        // Nur eine echte Ablehnung zählt: 401 ohne Verbindungsfehler, dreimal hintereinander. Ein Timeout
        // nach der Digest-Aufforderung meldet curl ebenfalls als 401 (beobachtet, als die Türstation beim
        // Klingeln beschäftigt war) – das darf die Klingel-Erkennung nicht pausieren.
        if ($r['code'] === 401 && $r['error'] === '') {
            $fails = (int) $this->GetBuffer('AuthFails') + 1;
            $this->SetBuffer('AuthFails', (string) $fails);
            if ($fails >= 3) {
                $this->authFailed();
            }
            return '';
        }
        $this->SetBuffer('AuthFails', '');
        $status = $r['code'] === 200 ? (string) (json_decode($r['body'], true)['CallStatus']['status'] ?? '') : '';
        if ($status === '') {
            if (!$this->GetValue('STREAM_CONNECTED')) {
                $this->SetValue('ONLINE', false);
            }
            return '';
        }
        $this->SetValue('ONLINE', true);
        $map = ['idle' => self::CALL_IDLE, 'ring' => self::CALL_RING, 'onCall' => self::CALL_ON_CALL];
        $new = $map[$status] ?? self::CALL_IDLE;
        $old = $this->GetValue('CALL_STATUS');
        if ($new === self::CALL_RING && $old !== self::CALL_RING) {
            $this->ring('poll');
        }
        if ($new === self::CALL_RING && $this->GetBuffer('DeviceRing') !== '1') {
            $this->callerButton('call'); // Ruf aufgebaut: jetzt steht der Anrufer fest
        }
        $this->SetValue('CALL_STATUS', $new);
        // Die Klingel erlischt vorzeitig nur, wenn die Türstation selbst einen Ruf gemeldet hat
        // und dieser beendet ist. Der Tastendruck (Alarmeingang) kommt im Stream einige Sekunden
        // vor dem Rufaufbau an; in dieser Lücke meldet callStatus noch "idle".
        if ($new === self::CALL_RING) {
            $this->SetBuffer('DeviceRing', '1');
        } elseif ($new === self::CALL_IDLE && $this->GetBuffer('DeviceRing') === '1') {
            $this->SetBuffer('DeviceRing', '');
            $this->ResetRing();
        }
        return $status;
    }

    /** Timer „Retry“: nach Fehler (nicht erreichbar, falsches Passwort) alle 5 min neu einrichten. */
    public function Retry(): void
    {
        $this->SetTimerInterval('Retry', 0);
        $this->ApplyChanges();
    }

    /** Anmeldung abgelehnt: Abfragen und Stream stoppen (Kontosperre der Türstation vermeiden). */
    private function authFailed(): void
    {
        $this->SetBuffer('AuthFails', '');
        if ($this->GetStatus() !== self::STATUS_AUTH_FAILED) {
            $this->LogMessage($this->Translate('The door station rejected the login; polling paused, next attempt in 5 minutes.'), KL_WARNING);
        }
        $this->SetStatus(self::STATUS_AUTH_FAILED);
        $this->SetTimerInterval('Poll', 0);
        $this->SetTimerInterval('Watchdog', 0);
        $this->SetTimerInterval('Retry', self::RETRY_MS);
        $this->streamDown();
    }

    public function Poll(): void
    {
        if ($this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $this->UpdateCallStatus();
        if (!$this->GetValue('STREAM_CONNECTED') && $this->GetValue('ONLINE')) {
            $this->pollAccessEvents();
        }
    }

    public function ResetRing(): void
    {
        $this->SetTimerInterval('RingReset', 0);
        $this->SetValue('DOORBELL', false);
        // Ruft die Türstation noch (bis ~60 s), bleibt der Rufstatus „Klingelt“ – sonst meldet die
        // nächste Abfrage einen Wechsel frei→klingelt und damit ein zweites Klingeln.
        if ($this->GetValue('CALL_STATUS') === self::CALL_RING && $this->GetBuffer('DeviceRing') !== '1') {
            $this->SetValue('CALL_STATUS', self::CALL_IDLE);
        }
    }

    private function ring(string $source, int $key = 0, int $room = 0): void
    {
        $now = time();
        $last = (int) $this->GetBuffer('RingTrigger');
        $this->SetBuffer('RingTrigger', (string) $now);
        if ($now - $last <= self::RING_DEDUPE_SECONDS) { // Folgemeldung desselben Besuchs: Klingel nur verlängern
            if ($this->GetValue('DOORBELL')) {
                $this->SetTimerInterval('RingReset', max(5, $this->ReadPropertyInteger('RingTimeout')) * 1000);
            }
            return;
        }
        $this->SendDebug('Ring', $source, 0);
        $this->SetValue('DOORBELL', true);
        $this->SetValue('LAST_RING', $now);
        if ($this->GetValue('CALL_STATUS') === self::CALL_IDLE) {
            $this->SetValue('CALL_STATUS', self::CALL_RING);
        }
        $this->SetTimerInterval('RingReset', max(5, $this->ReadPropertyInteger('RingTimeout')) * 1000);
        if ($this->ReadPropertyBoolean('SnapshotOnRing')) {
            $this->TakeSnapshot();
        }
        $this->logRing($source, $this->ringButton($key, $room));
    }
}
