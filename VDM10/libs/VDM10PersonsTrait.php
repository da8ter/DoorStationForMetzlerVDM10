<?php

declare(strict_types=1);

/**
 * Personenverwaltung der Türstation: Personen anlegen, umbenennen, PIN und
 * Gültigkeit setzen, löschen; Fingerabdrücke und Karten am Leser anlernen
 * und löschen. Wird von VDM10 (module.php) als Trait eingebunden.
 *
 * Grundlage (Firmware V3.7.1): UserInfo Record/Modify/Search/Delete,
 * CaptureFingerPrint + FingerPrint/SetUp, FingerPrintUpload (Suche),
 * CaptureCardInfo + CardInfo Record/Delete.
 */
trait VDM10PersonsTrait
{
    private const PERSON_CAPTURE_TIMEOUT = 45;

    /** @return array<int, array<string,mixed>> Personen der Türstation */
    public function GetPersons(): array
    {
        $persons = [];
        $pos = 0;
        do {
            $r = $this->acs('POST', '/ISAPI/AccessControl/UserInfo/Search?format=json',
                ['UserInfoSearchCond' => ['searchID' => 'vdm10', 'searchResultPosition' => $pos, 'maxResults' => 30]]);
            $page = $r['UserInfoSearch']['UserInfo'] ?? [];
            foreach ($page as $u) {
                $persons[] = $u;
            }
            $pos += count($page);
            $total = (int) ($r['UserInfoSearch']['totalMatches'] ?? 0);
        } while ($page !== [] && $pos < $total);
        return $persons;
    }

    /** Legt eine Person an und liefert ihre Personennummer. PIN optional (4-8 Ziffern). */
    public function AddPerson(string $Name, string $Pin): string
    {
        $name = $this->checkName($Name);
        $no = $this->nextEmployeeNo();
        $user = [
            'employeeNo' => $no, 'name' => $name, 'userType' => 'normal',
            'Valid' => $this->validity(0, 0), 'doorRight' => '1', 'RightPlan' => [], 'localUIRight' => false,
        ];
        if ($Pin !== '') {
            $user['password'] = $this->checkPin($Pin);
        }
        $this->acs('POST', '/ISAPI/AccessControl/UserInfo/Record?format=json', ['UserInfo' => $user]);
        $this->SendDebug('Persons', 'added ' . $no, 0);
        return $no;
    }

    public function RenamePerson(string $EmployeeNo, string $Name): bool
    {
        return $this->modifyPerson($EmployeeNo, ['name' => $this->checkName($Name)]);
    }

    /** PIN setzen (4-8 Ziffern); leerer Wert entfernt die PIN. */
    public function SetPersonPin(string $EmployeeNo, string $Pin): bool
    {
        return $this->modifyPerson($EmployeeNo, ['password' => $Pin === '' ? '' : $this->checkPin($Pin)]);
    }

    /** Gültigkeit als Unix-Zeitstempel; 0/0 = unbegrenzt. */
    public function SetPersonValidity(string $EmployeeNo, int $Begin, int $End): bool
    {
        if ($End !== 0 && $End <= $Begin) {
            throw new InvalidArgumentException($this->Translate('End must be after begin'));
        }
        return $this->modifyPerson($EmployeeNo, ['Valid' => $this->validity($Begin, $End)]);
    }

    public function DeletePerson(string $EmployeeNo): bool
    {
        $this->person($EmployeeNo);
        $this->acs('PUT', '/ISAPI/AccessControl/UserInfo/Delete?format=json',
            ['UserInfoDelCond' => ['EmployeeNoList' => [['employeeNo' => $EmployeeNo]]]]);
        $this->SendDebug('Persons', 'deleted ' . $EmployeeNo, 0);
        return true;
    }

    /**
     * Fingerabdruck am Leser der Türstation aufnehmen und der Person zuordnen.
     * Blockiert, bis ein Finger aufgelegt wurde (höchstens PERSON_CAPTURE_TIMEOUT s).
     */
    public function EnrollFingerprint(string $EmployeeNo, int $FingerNo): bool
    {
        $this->person($EmployeeNo);
        $finger = $FingerNo >= 1 && $FingerNo <= 10 ? $FingerNo : $this->nextFingerNo($EmployeeNo);
        $r = $this->isapi()->request('POST', '/ISAPI/AccessControl/CaptureFingerPrint',
            '<CaptureFingerPrintCond version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema"><fingerNo>' . $finger . '</fingerNo></CaptureFingerPrintCond>',
            self::PERSON_CAPTURE_TIMEOUT);
        if (!preg_match('#<fingerData>([^<]+)</fingerData>#', $r['body'], $m)) {
            throw new RuntimeException($this->Translate('No fingerprint captured') . ' (' . VDM10Isapi::errorText($r) . ')');
        }
        $quality = preg_match('#<fingerPrintQuality>(\d+)<#', $r['body'], $q) ? (int) $q[1] : 0;
        $this->SendDebug('Persons', sprintf('fingerprint %s/%d captured, quality %d', $EmployeeNo, $finger, $quality), 0);
        $this->acs('POST', '/ISAPI/AccessControl/FingerPrint/SetUp?format=json', ['FingerPrintCfg' => [
            'employeeNo' => $EmployeeNo, 'enableCardReader' => [1], 'fingerPrintID' => $finger,
            'fingerType' => 'normalFP', 'fingerData' => $m[1],
        ]]);
        return true;
    }

    public function DeleteFingerprints(string $EmployeeNo): bool
    {
        $this->person($EmployeeNo);
        $this->acs('PUT', '/ISAPI/AccessControl/FingerPrint/Delete?format=json', ['FingerPrintDelete' => [
            'mode' => 'byEmployeeNo', 'EmployeeNoDetail' => ['employeeNo' => $EmployeeNo, 'enableCardReader' => [1]],
        ]]);
        return true;
    }

    /** Karte am Leser einlesen und der Person zuordnen; liefert die Kartennummer. */
    public function EnrollCard(string $EmployeeNo): string
    {
        $this->person($EmployeeNo);
        $r = $this->isapi()->request('GET', '/ISAPI/AccessControl/CaptureCardInfo?format=json', '', self::PERSON_CAPTURE_TIMEOUT);
        $card = (string) (json_decode($r['body'], true)['CardInfo']['cardNo'] ?? '');
        if ($card === '') {
            throw new RuntimeException($this->Translate('No card read') . ' (' . VDM10Isapi::errorText($r) . ')');
        }
        $this->acs('POST', '/ISAPI/AccessControl/CardInfo/Record?format=json',
            ['CardInfo' => ['employeeNo' => $EmployeeNo, 'cardNo' => $card, 'cardType' => 'normalCard']]);
        return $card;
    }

    public function DeleteCards(string $EmployeeNo): bool
    {
        $this->person($EmployeeNo);
        $this->acs('PUT', '/ISAPI/AccessControl/CardInfo/Delete?format=json',
            ['CardInfoDelCond' => ['EmployeeNoList' => [['employeeNo' => $EmployeeNo]]]]);
        return true;
    }

    /** Formular-Aktionen der Personenliste (ein Einstieg, damit die Auswahl als JSON ankommt). */
    public function UIPersonAction(string $Action, string $Selected, string $Name, string $Pin): string
    {
        $row = json_decode($Selected, true);
        $no = is_array($row) ? (string) ($row['employeeNo'] ?? '') : '';
        try {
            if ($Action !== 'add' && $Action !== 'reload' && $no === '') {
                throw new InvalidArgumentException($this->Translate('Select a person in the list first'));
            }
            $msg = match ($Action) {
                'reload'      => '',
                'add'         => sprintf($this->Translate('Person %s created'), $this->AddPerson($Name, $Pin)),
                'rename'      => $this->RenamePerson($no, $Name) ? $this->Translate('Name changed') : '',
                'pin'         => $this->SetPersonPin($no, $Pin) ? ($Pin === '' ? $this->Translate('PIN removed') : $this->Translate('PIN set')) : '',
                'fingerprint' => $this->EnrollFingerprint($no, 0) ? $this->Translate('Fingerprint enrolled') : '',
                'card'        => sprintf($this->Translate('Card %s enrolled'), $this->EnrollCard($no)),
                'delfp'       => $this->DeleteFingerprints($no) ? $this->Translate('Fingerprints deleted') : '',
                'delcard'     => $this->DeleteCards($no) ? $this->Translate('Cards deleted') : '',
                'delete'      => $this->DeletePerson($no) ? $this->Translate('Person deleted') : '',
                default       => throw new InvalidArgumentException('Unknown action ' . $Action),
            };
        } catch (Throwable $e) {
            $msg = $this->Translate('Error') . ': ' . $e->getMessage();
        }
        $this->UpdateFormField('Persons', 'values', json_encode($this->personRows()));
        return $msg;
    }

    /** Name (oder Kartennummer) des jüngsten passenden Zutrittsereignisses im Geräteprotokoll. */
    private function accessName(int $major, int $minor): string
    {
        try {
            $from = date('Y-m-d\\TH:i:sP', time() - 60);
            $r = $this->isapi()->request('POST', VDM10Isapi::PATH_ACS_EVENT, (string) json_encode(['AcsEventCond' => [
                'searchID' => 'vdm10name', 'searchResultPosition' => 0, 'maxResults' => 30,
                'major' => $major, 'minor' => $minor, 'startTime' => $from, 'endTime' => date('Y-m-d\\TH:i:sP', time() + 60),
            ]]), 5);
            $list = json_decode($r['body'], true)['AcsEvent']['InfoList'] ?? [];
            $last = is_array($list) && $list !== [] ? end($list) : [];
            return (string) ($last['name'] ?? '') ?: (string) ($last['cardNo'] ?? '');
        } catch (Throwable $e) {
            $this->SendDebug('AccessName', $e->getMessage(), 0);
            return '';
        }
    }

    /** @return array<int, array<string,mixed>> Zeilen für die Formularliste */
    private function personRows(): array
    {
        if ($this->ReadPropertyString('Host') === '') {
            return [];
        }
        try {
            $persons = $this->GetPersons();
        } catch (Throwable $e) {
            $this->SendDebug('Persons', $e->getMessage(), 0);
            return [];
        }
        $rows = [];
        foreach ($persons as $u) {
            $valid = $u['Valid'] ?? [];
            $rows[] = [
                'employeeNo'   => (string) $u['employeeNo'],
                'name'         => (string) ($u['name'] ?? ''),
                'fingerprints' => (int) ($u['numOfFP'] ?? 0),
                'cards'        => (int) ($u['numOfCard'] ?? 0),
                'valid'        => !empty($valid['enable'])
                    ? substr((string) $valid['beginTime'], 0, 10) . ' – ' . substr((string) $valid['endTime'], 0, 10)
                    : $this->Translate('unlimited'),
            ];
        }
        return $rows;
    }

    private function modifyPerson(string $EmployeeNo, array $fields): bool
    {
        $this->person($EmployeeNo);
        $this->acs('PUT', '/ISAPI/AccessControl/UserInfo/Modify?format=json',
            ['UserInfo' => ['employeeNo' => $EmployeeNo] + $fields]);
        return true;
    }

    /** @return array<string,mixed> */
    private function person(string $EmployeeNo): array
    {
        foreach ($this->GetPersons() as $u) {
            if ((string) $u['employeeNo'] === $EmployeeNo) {
                return $u;
            }
        }
        throw new InvalidArgumentException(sprintf($this->Translate('Person %s not found'), $EmployeeNo));
    }

    private function nextEmployeeNo(): string
    {
        $max = 0;
        foreach ($this->GetPersons() as $u) {
            $max = max($max, (int) $u['employeeNo']);
        }
        return str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function nextFingerNo(string $EmployeeNo): int
    {
        $r = $this->acs('POST', '/ISAPI/AccessControl/FingerPrintUpload?format=json',
            ['FingerPrintCond' => ['searchID' => 'vdm10', 'employeeNo' => $EmployeeNo, 'cardReaderNo' => 1]]);
        $used = array_map(static fn (array $f): int => (int) $f['fingerPrintID'], $r['FingerPrintInfo']['FingerPrintList'] ?? []);
        for ($i = 1; $i <= 10; $i++) {
            if (!in_array($i, $used, true)) {
                return $i;
            }
        }
        throw new RuntimeException($this->Translate('All 10 fingerprint slots of this person are used'));
    }

    /** @return array<string,mixed> */
    private function validity(int $begin, int $end): array
    {
        $unlimited = $begin === 0 && $end === 0;
        $b = $unlimited ? time() : ($begin ?: time());
        $e = $unlimited ? strtotime('+10 years', $b) : $end;
        return ['enable' => !$unlimited, 'beginTime' => date('Y-m-d\T00:00:00', $b),
            'endTime' => date('Y-m-d\T23:59:59', $e), 'timeType' => 'local'];
    }

    private function checkName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 32) {
            throw new InvalidArgumentException($this->Translate('Name must have 1 to 32 characters'));
        }
        return $name;
    }

    private function checkPin(string $pin): string
    {
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            throw new InvalidArgumentException($this->Translate('PIN must have 4 to 8 digits'));
        }
        return $pin;
    }

    /**
     * JSON-Aufruf der Zutrittskontrolle; wirft bei Fehlern mit dem Gerätegrund.
     *
     * @return array<string,mixed>
     */
    private function acs(string $method, string $path, array $body): array
    {
        $r = $this->isapi()->request($method, $path, (string) json_encode($body, JSON_UNESCAPED_UNICODE));
        $json = json_decode($r['body'], true);
        if ($r['code'] !== 200 || (is_array($json) && isset($json['statusCode']) && (int) $json['statusCode'] !== 1)) {
            $reason = is_array($json) ? (string) ($json['subStatusCode'] ?? $json['statusString'] ?? '') : '';
            throw new RuntimeException($reason !== '' ? $reason : VDM10Isapi::errorText($r));
        }
        return is_array($json) ? $json : [];
    }
}
