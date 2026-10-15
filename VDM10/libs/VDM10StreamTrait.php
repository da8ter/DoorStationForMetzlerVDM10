<?php

declare(strict_types=1);

/**
 * Alert-Stream der VDM10 über einen Client Socket (Parent): Socket anlegen und
 * konfigurieren, Digest-Anfrage senden, Antwort zerlegen, Verbindung überwachen.
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10StreamTrait
{
    /** Bytes des Alert-Streams vom Client Socket. */
    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::SOCKET_RX) {
            return '';
        }
        $raw = (string) ($data['Buffer'] ?? '');
        $bytes = ($raw !== '' && strlen($raw) % 2 === 0 && ctype_xdigit($raw)) ? (string) hex2bin($raw) : mb_convert_encoding($raw, 'ISO-8859-1', 'UTF-8');
        $buffer = $this->GetBuffer('RX') . $bytes;
        $this->SetBuffer('LastRx', (string) time());
        if ($this->GetBuffer('Handshake') === 'head') {
            $end = strpos($buffer, "\r\n\r\n");
            if ($end === false) {
                $this->SetBuffer('RX', $buffer);
                return '';
            }
            $head = substr($buffer, 0, $end);
            $code = preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $head, $m) ? (int) $m[1] : 0;
            $this->SendDebug('Stream', 'HTTP ' . $code, 0);
            if ($code !== 200) {
                $this->SetBuffer('Handshake', 'error');
                $this->SetBuffer('RX', '');
                $this->SetValue('STREAM_CONNECTED', false);
                return '';
            }
            $this->SetBuffer('Handshake', 'stream');
            $this->SetValue('STREAM_CONNECTED', true);
            $this->SetValue('ONLINE', true);
            $buffer = substr($buffer, $end + 4);
        }
        foreach (VDM10Events::extractParts($buffer) as $part) {
            if (str_starts_with($part['ctype'], 'image/')) {
                if ($this->ReadPropertyBoolean('SnapshotOnRing') && time() - $this->GetValue('LAST_RING') <= 10) {
                    $this->storeSnapshot($part['body']);
                }
                continue;
            }
            $event = VDM10Events::parseNotification($part['body']);
            if ($event !== null) {
                $this->handleEvent($event, 'stream');
            }
        }
        $this->SetBuffer('RX', $buffer);
        return '';
    }

    public function Watchdog(): void
    {
        if (!$this->ReadPropertyBoolean('StreamEnabled') || $this->ReadPropertyString('Host') === '') {
            return;
        }
        $io = $this->ioId();
        if ($io === 0) {
            return;
        }
        if (IPS_GetInstance($io)['InstanceStatus'] !== IS_ACTIVE) {
            $this->SendDebug('Watchdog', 'socket not active, reconnecting', 0);
            $this->reconnectSocket();
            return;
        }
        $silent = time() - (int) $this->GetBuffer('LastRx');
        $state = $this->GetBuffer('Handshake');
        if ($state === 'error' || $state === '' || $silent > VDM10Events::HEARTBEAT_SECONDS * 3 + 5) {
            $this->SendDebug('Watchdog', sprintf('state=%s silent=%ds, reconnecting', $state, $silent), 0);
            $this->reconnectSocket();
        }
    }

    public function ReconnectStream(): void
    {
        $this->reconnectSocket();
    }

    private function ioId(): int
    {
        return (int) (@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    /** Legt den Client Socket an, falls die Instanz (z. B. per API erstellt) noch keinen Parent hat. */
    private function ensureSocket(): void
    {
        if ($this->ioId() > 0 || !$this->ReadPropertyBoolean('StreamEnabled')) {
            return;
        }
        $io = IPS_CreateInstance(self::CLIENT_SOCKET_GUID);
        IPS_SetName($io, 'VDM10 Client Socket');
        IPS_SetParent($io, IPS_GetParent($this->InstanceID));
        IPS_SetProperty($io, 'Open', false);
        IPS_ApplyChanges($io);
        IPS_ConnectInstance($this->InstanceID, $io);
        $this->SendDebug('Socket', 'created #' . $io, 0);
    }

    private function configureSocket(): void
    {
        $io = $this->ioId();
        if ($io === 0) {
            return;
        }
        $wanted = json_decode($this->GetConfigurationForParent(), true);
        $changed = false;
        foreach ($wanted as $key => $value) {
            if (IPS_GetProperty($io, $key) !== $value) {
                IPS_SetProperty($io, $key, $value);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($io);
        }
    }

    private function reconnectSocket(): void
    {
        $io = $this->ioId();
        if ($io === 0) {
            return;
        }
        $this->streamDown();
        IPS_SetProperty($io, 'Open', false);
        IPS_ApplyChanges($io);
        IPS_SetProperty($io, 'Open', true);
        IPS_ApplyChanges($io);
    }

    private function startStream(): void
    {
        if (!$this->ReadPropertyBoolean('StreamEnabled') || $this->ReadPropertyString('Host') === '') {
            return;
        }
        $io = $this->ioId();
        if ($io === 0 || !function_exists('CSCK_SendText')) {
            return;
        }
        $state = $this->GetBuffer('Handshake');
        if (($state === 'stream' || $state === 'head') && time() - (int) $this->GetBuffer('LastRx') < VDM10Events::HEARTBEAT_SECONDS * 3) {
            return; // Stream läuft bereits auf dieser Verbindung
        }
        $challenge = $this->isapi()->challenge();
        if ($challenge === []) {
            $this->SendDebug('Stream', 'no digest challenge', 0);
            $this->SetBuffer('Handshake', 'error');
            return;
        }
        $this->SetBuffer('RX', '');
        $this->SetBuffer('Handshake', 'head');
        $this->SetBuffer('LastRx', (string) time());
        $this->SendDebug('Stream', 'request sent', 0);
        @CSCK_SendText($io, $this->isapi()->alertStreamRequest($this->ReadPropertyString('Host'), $challenge));
    }

    private function streamDown(): void
    {
        $this->SetBuffer('Handshake', '');
        $this->SetBuffer('RX', '');
        if ($this->GetValue('STREAM_CONNECTED')) {
            $this->SetValue('STREAM_CONNECTED', false);
        }
    }
}
