<?php

declare(strict_types=1);

/**
 * Konfigurationsformular der Personenverwaltung: Liste mit Bearbeiten-Dialog je
 * Person (Name, PIN, Gültigkeit, Fingerabdruck/Karte anlernen und löschen,
 * Person löschen). Die Liste steht unter "actions"; jede Änderung geht sofort
 * an die Türstation. Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10PersonsUITrait
{
    /** Dialog für eine Zeile; leere Nummer = neue Person. */
    public function UIPersonForm(string $EmployeeNo, bool $Limited): string
    {
        $form = [
            ['type' => 'ValidationTextBox', 'name' => 'PersonNo', 'caption' => 'No.', 'visible' => false],
            ['type' => 'ValidationTextBox', 'name' => 'PersonName', 'caption' => $this->Translate('Name'), 'width' => '320px',
                'validate' => '^.{1,32}$'],
            ['type' => 'PasswordTextBox', 'name' => 'PersonPin', 'width' => '320px', 'visible' => $this->hasKeypad(),
                'caption' => $EmployeeNo === '' ? $this->Translate('PIN (4-8 digits, optional)') : $this->Translate('New PIN (empty = unchanged)')],
            ['type' => 'SelectMedia', 'name' => 'PersonPhoto', 'caption' => $this->Translate('Photo (for the tile)'), 'width' => '320px'],
            ['type' => 'Select', 'name' => 'PersonSchedule', 'caption' => $this->Translate('Access schedule'), 'width' => '320px',
                'options' => $this->scheduleOptions()],
            ['type' => 'CheckBox', 'name' => 'PersonLimited', 'caption' => $this->Translate('Valid only in a period'),
                'onChange' => 'VDM10_UIPersonLimited($id, $PersonLimited);'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'SelectDate', 'name' => 'PersonBegin', 'caption' => $this->Translate('From'), 'enabled' => $Limited],
                ['type' => 'SelectDate', 'name' => 'PersonEnd', 'caption' => $this->Translate('Until'), 'enabled' => $Limited],
            ]],
        ];
        if ($EmployeeNo === '') {
            $has = $this->credentials();
            if ($has['finger'] || $has['card']) {
                $form[] = ['type' => 'Label', 'caption' => $this->Translate($has['finger'] && $has['card']
                    ? 'Fingerprints and cards can be enrolled after the person has been created.'
                    : ($has['finger'] ? 'Fingerprints can be enrolled after the person has been created.' : 'Cards can be enrolled after the person has been created.'))];
            }
            return (string) json_encode($form);
        }
        $button = fn (string $caption, string $action, string $confirm = ''): array => array_filter([
            'type' => 'Button', 'caption' => $this->Translate($caption),
            'onClick' => "echo VDM10_UIPersonAction(\$id, '$action', \$PersonNo);",
            'confirm' => $confirm !== '' ? $this->Translate($confirm) : null,
        ], static fn ($v) => $v !== null);
        $has = $this->credentials();
        $form[] = ['type' => 'Label', 'name' => 'PersonCredentials', 'caption' => $this->credentialText($EmployeeNo), 'bold' => true];
        if ($has['finger'] || $has['card']) {
            $form[] = ['type' => 'Label', 'caption' => $this->Translate(match (true) {
                $has['finger'] && $has['card'] => 'Enrolling: click the button, then within 10 seconds place the finger on the reader of the door station (lift and place it again after each beep) or hold the card in front of it.',
                $has['finger']                 => 'Enrolling: click the button, then within 10 seconds place the finger on the reader of the door station (lift and place it again after each beep).',
                default                        => 'Enrolling: click the button, then within 10 seconds hold the card in front of the reader of the door station.',
            })];
        }
        $first = array_merge($has['finger'] ? [$button('Enroll fingerprint', 'fingerprint')] : [], $has['card'] ? [$button('Enroll card', 'card')] : [],
            $has['pin'] ? [$button('Remove PIN', 'nopin', 'Remove the PIN of this person?')] : []);
        if ($first !== []) {
            $form[] = ['type' => 'RowLayout', 'items' => $first];
        }
        $form[] = ['type' => 'RowLayout', 'items' => array_merge(
            $has['finger'] ? [$button('Delete fingerprints', 'delfp', 'Delete all fingerprints of this person?')] : [],
            $has['card'] ? [$button('Delete cards', 'delcard', 'Delete all cards of this person?')] : [],
            [$button('Delete person', 'delete', 'Delete this person from the door station?')]
        )];
        $form[] = ['type' => 'Label', 'caption' => $this->Translate($has['pin']
            ? 'Name, PIN and validity are saved with OK; the buttons act immediately.'
            : 'Name and validity are saved with OK; the buttons act immediately.')];
        return (string) json_encode($form);
    }

    /** onAdd/onEdit: Name, PIN und Gültigkeit speichern. */
    public function UIPersonSave(string $EmployeeNo, string $Name, string $Pin, bool $Limited, string $Begin, string $End, int $Schedule, int $Photo): string
    {
        try {
            [$from, $until] = $Limited ? [$this->dateValue($Begin), $this->dateValue($End)] : [0, 0];
            if ($EmployeeNo === '') {
                $no = $this->AddPerson($Name, $Pin);
                if ($Limited) {
                    $this->SetPersonValidity($no, $from, $until);
                }
                if ($Schedule > 0) {
                    $this->SetPersonSchedule($no, $Schedule);
                }
                $this->setPersonPhoto($no, $Photo);
                $msg = sprintf($this->Translate('Person %s created'), $no);
            } elseif (!in_array($EmployeeNo, array_map(static fn (array $u): string => (string) $u['employeeNo'], $this->GetPersons()), true)) {
                $msg = ''; // im Dialog gelöscht und dann mit OK geschlossen
            } else {
                $person = $this->person($EmployeeNo);
                if ($Name !== (string) ($person['name'] ?? '')) {
                    $this->RenamePerson($EmployeeNo, $Name);
                }
                if ($Pin !== '') {
                    $this->SetPersonPin($EmployeeNo, $Pin);
                }
                $this->SetPersonValidity($EmployeeNo, $from, $until);
                if ($Schedule !== $this->personSchedule($person)) {
                    $this->SetPersonSchedule($EmployeeNo, $Schedule);
                }
                $this->setPersonPhoto($EmployeeNo, $Photo);
                $msg = $this->Translate('Saved');
            }
        } catch (Throwable $e) {
            $msg = $this->Translate('Error') . ': ' . $e->getMessage();
        }
        $this->refreshScheduleLists(); // beide Listen: die Zeitplanliste zeigt die zugeordneten Personen
        return $msg;
    }

    /** Buttons im Dialog: wirken sofort. */
    public function UIPersonAction(string $Action, string $EmployeeNo): string
    {
        try {
            $msg = match ($Action) {
                'fingerprint' => $this->EnrollFingerprint($EmployeeNo, 0) ? $this->Translate('Fingerprint enrolled') : '',
                'card'        => sprintf($this->Translate('Card %s enrolled'), $this->EnrollCard($EmployeeNo)),
                'nopin'       => $this->SetPersonPin($EmployeeNo, '') ? $this->Translate('PIN removed') : '',
                'delfp'       => $this->DeleteFingerprints($EmployeeNo) ? $this->Translate('Fingerprints deleted') : '',
                'delcard'     => $this->DeleteCards($EmployeeNo) ? $this->Translate('Cards deleted') : '',
                'delete'      => $this->DeletePerson($EmployeeNo) ? $this->Translate('Person deleted. Close the dialog with Cancel.') : '',
                default       => throw new InvalidArgumentException('Unknown action ' . $Action),
            };
            if ($Action !== 'delete') {
                $this->UpdateFormField('PersonCredentials', 'caption', $this->credentialText($EmployeeNo));
            }
        } catch (Throwable $e) {
            $msg = $this->Translate('Error') . ': ' . $e->getMessage();
        }
        $this->refreshScheduleLists(); // beide Listen: die Zeitplanliste zeigt die zugeordneten Personen
        return $msg;
    }

    public function UIPersonLimited(bool $Limited): void
    {
        $this->UpdateFormField('PersonBegin', 'enabled', $Limited);
        $this->UpdateFormField('PersonEnd', 'enabled', $Limited);
    }

    public function UIReloadPersons(): void
    {
        $this->refreshScheduleLists();
    }

    /**
     * Personen und Zeitpläne einmal pro Aufruf von der Türstation holen. Ist die Instanz nicht
     * verbunden, bleibt es bei leeren Listen, damit das Formular ohne Wartezeit öffnet.
     *
     * @return array{persons: array<int, array<string,mixed>>, schedules: array<int, array<string,mixed>>}
     */
    private function listData(): array
    {
        if ($this->ReadPropertyString('Host') === '' || $this->GetStatus() !== IS_ACTIVE) {
            return ['persons' => [], 'schedules' => []];
        }
        try {
            return ['persons' => $this->GetPersons(), 'schedules' => $this->GetSchedules()];
        } catch (Throwable $e) {
            $this->SendDebug('Lists', $e->getMessage(), 0);
            return ['persons' => [], 'schedules' => []];
        }
    }

    /** Personenliste im Formular befüllen; neue Personen starten mit heutigem Datum. */
    private function fillPersonsForm(array $form): array
    {
        $data = null;
        $has = $this->credentials();
        foreach ($form['actions'] as &$action) {
            if (!in_array($action['name'] ?? '', ['PersonsPanel', 'SchedulesPanel'], true)) {
                continue;
            }
            if ($action['name'] === 'PersonsPanel') {
                $action['caption'] = $this->Translate(match (true) {
                    $has['finger'] && $has['card'] => 'Persons, fingerprints and cards',
                    $has['finger']                 => 'Persons and fingerprints',
                    $has['card']                   => 'Persons and cards',
                    default                        => 'Persons',
                });
            }
            foreach ($action['items'] as &$item) {
                if (!in_array($item['name'] ?? '', ['Persons', 'Schedules'], true)) {
                    continue;
                }
                $data ??= $this->listData();
                $item['values'] = $item['name'] === 'Persons' ? $this->personRows($data) : $this->scheduleRows($data);
                foreach ($item['columns'] as &$column) {
                    if (in_array($column['name'], ['PersonBegin', 'PersonEnd'], true)) {
                        $column['add'] = $this->dateJson('');
                    }
                    if ($column['name'] === 'fingerprints' || $column['name'] === 'cards') {
                        $column['visible'] = $has[$column['name'] === 'fingerprints' ? 'finger' : 'card'];
                    }
                }
            }
        }
        return $form;
    }

    /** @return array<int, array<string,mixed>> Zeilen der Formularliste inkl. Dialogwerten */
    private function personRows(?array $data = null): array
    {
        $data ??= $this->listData();
        $persons = $data['persons'];
        $names = array_column($data['schedules'], 'name', 'no');
        $rows = [];
        foreach ($persons as $u) {
            $valid = $u['Valid'] ?? [];
            $schedule = $this->personSchedule($u);
            $limited = !empty($valid['enable']);
            $rows[] = [
                'employeeNo'    => (string) $u['employeeNo'],
                'name'          => (string) ($u['name'] ?? ''),
                'fingerprints'  => (int) ($u['numOfFP'] ?? 0),
                'cards'         => (int) ($u['numOfCard'] ?? 0),
                'valid'         => $limited
                    ? substr((string) $valid['beginTime'], 0, 10) . ' – ' . substr((string) $valid['endTime'], 0, 10)
                    : $this->Translate('unlimited'),
                'schedule'      => $schedule > 0 ? ($names[$schedule] ?? '#' . $schedule) : $this->Translate('Around the clock'),
                'PersonSchedule' => $schedule,
                'PersonPhoto'   => (int) ($this->personPhotos()[(string) $u['employeeNo']] ?? 0),
                'photo'         => $this->personPhotoId((string) $u['employeeNo']) > 0
                    ? IPS_GetName($this->personPhotoId((string) $u['employeeNo'])) : '–',
                'PersonNo'      => (string) $u['employeeNo'],
                'PersonName'    => (string) ($u['name'] ?? ''),
                'PersonPin'     => '',
                'PersonLimited' => $limited,
                'PersonBegin'   => $this->dateJson((string) ($valid['beginTime'] ?? '')),
                'PersonEnd'     => $this->dateJson((string) ($valid['endTime'] ?? '')),
            ];
        }
        return $rows;
    }

    /** Hat die Türstation ein Tastenfeld? Nur dann ist eine persönliche PIN nutzbar (Raum-Nummer + PIN). */
    private function hasKeypad(): bool
    {
        return $this->credentials()['pin'];
    }

    private function credentialText(string $EmployeeNo): string
    {
        try {
            $p = $this->person($EmployeeNo);
            $has = $this->credentials();
            $parts = [sprintf($this->Translate('No. %s'), $EmployeeNo)];
            if ($has['finger']) {
                $parts[] = sprintf($this->Translate('Fingerprints: %d'), (int) ($p['numOfFP'] ?? 0));
            }
            if ($has['card']) {
                $parts[] = sprintf($this->Translate('Cards: %d'), (int) ($p['numOfCard'] ?? 0));
            }
            return implode(' · ', $parts);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /** ISO-Zeit der Türstation → SelectDate-JSON; leer = heute. */
    private function dateJson(string $iso): string
    {
        $ts = $iso !== '' ? (strtotime(substr($iso, 0, 10)) ?: time()) : time();
        return (string) json_encode(['year' => (int) date('Y', $ts), 'month' => (int) date('n', $ts), 'day' => (int) date('j', $ts)]);
    }

    /** SelectDate-JSON → Unix-Zeit (Tagesbeginn). */
    private function dateValue(string $json): int
    {
        $d = json_decode($json, true);
        if (!is_array($d) || empty($d['year'])) {
            throw new InvalidArgumentException($this->Translate('Please select a date'));
        }
        return (int) mktime(0, 0, 0, (int) $d['month'], (int) $d['day'], (int) $d['year']);
    }
}
