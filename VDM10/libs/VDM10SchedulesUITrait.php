<?php

declare(strict_types=1);

/**
 * Formularteil der Zugangszeitpläne: Liste mit Dialog (Name + Zeitfenster-Tabelle),
 * Speichern/Löschen wirken sofort auf der Türstation.
 */
trait VDM10SchedulesUITrait
{
    /** onAdd/onEdit der Zeitplanliste. $Windows: Zeilen der Zeitfenster-Tabelle (JSON). */
    public function UIScheduleSave(int $No, string $Name, string $Windows): string
    {
        try {
            $rows = json_decode($Windows, true);
            if (is_string($rows)) {
                $rows = json_decode($rows, true);
            }
            $windows = [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                $windows[] = ['days' => (string) ($row['Days'] ?? ''), 'from' => $this->timeFromForm($row['From'] ?? ''),
                    'to' => $this->timeFromForm($row['To'] ?? '')];
            }
            $saved = $this->SetSchedule($No, $Name, (string) json_encode($windows));
            $msg = $No > 0 ? $this->Translate('Saved') : sprintf($this->Translate('Schedule %d created'), $saved);
        } catch (Throwable $e) {
            $msg = $this->Translate('Error') . ': ' . $e->getMessage();
        }
        $this->refreshScheduleLists();
        return $msg;
    }

    public function UIScheduleDelete(int $No): string
    {
        try {
            $this->DeleteSchedule($No);
            $msg = $this->Translate('Schedule deleted');
        } catch (Throwable $e) {
            $msg = $this->Translate('Error') . ': ' . $e->getMessage();
        }
        $this->refreshScheduleLists();
        return $msg;
    }

    private function refreshScheduleLists(): void
    {
        $data = $this->listData();
        $this->UpdateFormField('Schedules', 'values', json_encode($this->scheduleRows($data)));
        $this->UpdateFormField('Persons', 'values', json_encode($this->personRows($data)));
    }

    /** @return array<int, array<string,mixed>> */
    private function scheduleRows(?array $data = null): array
    {
        $data ??= $this->listData();
        $schedules = $data['schedules'];
        $persons = $data['persons'];
        $rows = [];
        foreach ($schedules as $s) {
            $users = array_filter($persons, fn (array $u): bool => $this->personSchedule($u) === $s['no']);
            $rows[] = [
                'ScheduleNo'      => $s['no'],
                'name'            => $s['name'],
                'times'           => $this->scheduleSummary($s['windows']),
                'persons'         => implode(', ', array_map(static fn (array $u): string => (string) $u['name'], $users)),
                'ScheduleName'    => $s['name'],
                'ScheduleWindows' => array_map(fn (array $w): array => ['Days' => $w['days'],
                    'From' => $this->timeToForm($w['from']), 'To' => $this->timeToForm($w['to'])], $s['windows']),
            ];
        }
        return $rows;
    }

    /** @return array<int, array{caption:string,value:int}> Auswahl für den Personen-Dialog */
    private function scheduleOptions(): array
    {
        $options = [['caption' => $this->Translate('Around the clock'), 'value' => 0]];
        try {
            foreach ($this->GetSchedules() as $s) {
                $options[] = ['caption' => $s['name'], 'value' => $s['no']];
            }
        } catch (Throwable $e) {
            $this->SendDebug('Schedules', $e->getMessage(), 0);
        }
        return $options;
    }

    private function timeToForm(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return (string) json_encode(['hour' => $h, 'minute' => $m, 'second' => 0]);
    }

    private function timeFromForm(mixed $value): string
    {
        $t = is_array($value) ? $value : json_decode((string) $value, true);
        if (!is_array($t) || !isset($t['hour'])) {
            throw new InvalidArgumentException($this->Translate('Please enter all times'));
        }
        return sprintf('%02d:%02d', (int) $t['hour'], (int) ($t['minute'] ?? 0));
    }
}
