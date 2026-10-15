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
            if ($code === 0 && (str_starts_with(ltrim($buffer), '--') || stripos($head, 'Content-Length') !== false)) {
                // Kein Statuskopf, sondern Stream-Teile: die Verbindung streamt bereits
                // (z. B. nach einem Modul-Reload, der die Puffer geleert hat).
                $this->SendDebug('Stream', 'already streaming', 0);
                $code = 200;
                $end = -4;
            } else {
                $this->SendDebug('Stream', 'HTTP ' . $code, 0);
            }
            if ($code !== 200) {
                $this->SetBuffer('Handshake', 'error');
                $this->SetBuffer('RX', '');
                $this->SetValue('STREAM_CONNECTED', false);
                return '';
            }
            $this->SetBuffer('Handshake', 'stream');
            $this->SetBuffer('PollPrimed', ''); // Rückfallebene beginnt beim nächsten Ausfall neu
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

    /**
     * Überwacht den Alert-Stream. Den Socket selbst schaltet das Modul nicht (Store-Regel: keine
     * Eigenschaften anderer Instanzen setzen); nach einem Verbindungsabbruch verbindet der Client
     * Socket von allein neu, und IM_CHANGESTATUS startet den Stream wieder. Bleibt der Stream auf
     * einer offenen Verbindung stumm oder wurde abgewiesen, wird die Anfrage erneut gesendet.
     */
    public function Watchdog(): void
    {
        if (!$this->ReadPropertyBoolean('StreamEnabled') || $this->ReadPropertyString('Host') === '' || !$this->HasActiveParent()) {
            return;
        }
        $silent = time() - (int) $this->GetBuffer('LastRx');
        $state = $this->GetBuffer('Handshake');
        if ($state === 'error' || $state === '' || $silent > VDM10Events::HEARTBEAT_SECONDS * 3 + 5) {
            $this->SendDebug('Watchdog', sprintf('state=%s silent=%ds, request again', $state, $silent), 0);
            $this->streamDown(); // halboffene Verbindung: als getrennt melden, damit „Online“/Log/Push stimmen
            $this->startStream();
        }
    }

    public function ReconnectStream(): void
    {
        $this->SetBuffer('Handshake', '');
        $this->startStream();
    }

    private function ioId(): int
    {
        return IPS_InstanceExists($this->InstanceID) ? (int) IPS_GetInstance($this->InstanceID)['ConnectionID'] : 0;
    }

    private function startStream(): void
    {
        if (!$this->ReadPropertyBoolean('StreamEnabled') || $this->ReadPropertyString('Host') === '') {
            return;
        }
        if (!$this->HasActiveParent()) {
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
        $this->SendDataToParent((string) json_encode(['DataID' => self::SOCKET_TX,
            'Buffer' => bin2hex($this->isapi()->alertStreamRequest($this->ReadPropertyString('Host'), $challenge))]));
    }

    private function streamDown(): void
    {
        $this->SetBuffer('Handshake', '');
        $this->SetBuffer('RX', '');
        if ($this->GetValue('STREAM_CONNECTED')) {
            $this->SetValue('STREAM_CONNECTED', false);
        }
    }

    /** Rückfallebene ohne Stream: neue Zutrittsereignisse aus dem Gerätespeicher holen. */
    private function pollAccessEvents(): void
    {
        $tick = (int) $this->GetBuffer('EventTick') + 1;
        $this->SetBuffer('EventTick', (string) $tick);
        if ($tick % 3 !== 0) {
            return;
        }
        $last = $this->ReadAttributeString('LastEventTime');
        $events = $this->isapi()->recentEvents(10);
        // Erster Abruf nach dem Ausfall des Streams: nur den Stand merken. Sonst gelten Ereignisse,
        // die der Stream schon gemeldet hat, als neu (z. B. ein altes Klingeln).
        $primed = $this->GetBuffer('PollPrimed') === '1';
        $this->SetBuffer('PollPrimed', '1');
        $newest = $last;
        foreach ($events as $info) {
            $time = (string) ($info['time'] ?? '');
            if ($time === '' || $time <= $last) {
                continue;
            }
            $newest = max($newest, $time);
            if ($primed && $last !== '') {
                $this->handleEvent(VDM10Events::fromAcsEvent($info), 'poll');
            }
        }
        if ($newest !== $last) {
            $this->WriteAttributeString('LastEventTime', $newest);
        }
    }
}
