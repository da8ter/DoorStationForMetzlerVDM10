<?php

declare(strict_types=1);

/**
 * Hinweistext auf dem Touch-Display („Hinweisveröffentlichung“, benutzerdefinierter Inhalt):
 * ein statischer Text, den das Display im Ruhezustand einblendet (Metzler empfiehlt höchstens
 * 45 Zeichen). Schnittstelle wie in der Weboberfläche der Türstation:
 * GET/PUT /ISAPI/Publish/TerminalMgr/CustomText {CustomText:{enabled, content}}.
 *
 * Sichtbar und schaltbar nur, wenn ein Display-Modul am Bus hängt UND die Türstation die
 * Schnittstelle meldet (ohne Display antwortet sie notSupport). Ohne Hardware umgesetzt.
 * Die Diashow (Medienbibliothek, Programme) ist nicht enthalten.
 * Wird von VDM10 (module.php) als Trait eingebunden.
 */
trait VDM10DisplayTextTrait
{
    private const PATH_DISPLAY_TEXT = '/ISAPI/Publish/TerminalMgr/CustomText%s?format=json';
    private const POSITION_DISPLAY_TEXT = 350;

    /** Länge [min, max] des Hinweistexts; null = nicht verfügbar (kein Display oder nicht unterstützt). */
    private function displayTextCaps(): ?array
    {
        $hasDisplay = array_filter($this->subModules(), static fn (array $m): bool => VDM10SubModules::isDisplay($m['type'])) !== [];
        if (!$hasDisplay) {
            return null;
        }
        $cached = json_decode($this->GetBuffer('DisplayTextCaps'), true);
        if (is_array($cached) && time() - (int) $cached['t'] < 3600) {
            return $cached['caps'];
        }
        $r = $this->isapi()->request('GET', sprintf(self::PATH_DISPLAY_TEXT, '/capabilities'), '', 5);
        $caps = $r['code'] === 200 ? VDM10SubModules::parseDisplayTextCaps($r['body']) : null;
        $this->SetBuffer('DisplayTextCaps', (string) json_encode(['t' => time(), 'caps' => $caps]));
        return $caps;
    }

    /** Aktuell angezeigter Hinweistext ('' = aus). */
    private function displayText(): string
    {
        $cfg = $this->json($this->isapi()->request('GET', sprintf(self::PATH_DISPLAY_TEXT, ''), '', 5))['CustomText'] ?? [];
        $on = ($cfg['enabled'] ?? false) === true || ($cfg['enabled'] ?? '') === 'true';
        return $on ? (string) ($cfg['content'] ?? '') : '';
    }

    /** Hinweistext auf dem Touch-Display setzen; '' blendet ihn aus. */
    public function SetDisplayText(string $Text): bool
    {
        $caps = $this->displayTextCaps();
        if ($caps === null) {
            throw new RuntimeException($this->Translate('No touch display with notices on the door station.'));
        }
        $body = VDM10SubModules::displayTextPayload($caps, $Text);
        $this->writeChecked('PUT', sprintf(self::PATH_DISPLAY_TEXT, ''), (string) json_encode($body, JSON_UNESCAPED_UNICODE));
        if (@$this->GetIDForIdent('DISPLAY_TEXT')) {
            $this->SetValue('DISPLAY_TEXT', $body['CustomText']['content']);
        }
        return true;
    }

    /** Aus refreshSubModules(): Variable anlegen (nur mit Display) und Stand übernehmen. */
    private function refreshDisplayText(): void
    {
        if (!$this->ReadPropertyBoolean('SubModuleVariables') || $this->displayTextCaps() === null) {
            return;
        }
        if (!@$this->GetIDForIdent('DISPLAY_TEXT')) {
            $this->MaintainVariable('DISPLAY_TEXT', $this->Translate('Notice on the touch display'), VARIABLETYPE_STRING,
                ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => 'Information'], self::POSITION_DISPLAY_TEXT, true);
            $this->EnableAction('DISPLAY_TEXT');
        }
        $this->SetValue('DISPLAY_TEXT', $this->displayText());
    }

    /** RequestAction für den Hinweistext; false = Ident gehört nicht hierher. */
    private function displayTextAction(string $Ident, mixed $Value): bool
    {
        if ($Ident !== 'DISPLAY_TEXT') {
            return false;
        }
        $this->SetDisplayText((string) $Value);
        return true;
    }

    /** Formular: Bereich nur zeigen, wenn verfügbar; Wert der Türstation eintragen. */
    private function fillDisplayTextForm(array $items, bool $online): array
    {
        $caps = $online ? $this->displayTextCaps() : null;
        foreach ($items as &$item) {
            $name = $item['name'] ?? '';
            if (in_array($name, ['DisplayTextLabel', 'DisplayText', 'DisplayTextSave'], true)) {
                $item['visible'] = $caps !== null;
            }
            if ($name === 'DisplayText' && $caps !== null) {
                $item['value'] = $this->displayText();
                $item['validate'] = sprintf('^.{0,%d}$', $caps[1]);
            }
        }
        return $items;
    }

    /** Formular-Button. */
    public function UIDisplayTextSave(string $Text): string
    {
        try {
            $this->SetDisplayText($Text);
            return $this->Translate(trim($Text) === '' ? 'Notice hidden.' : 'Notice shown on the touch display.');
        } catch (Throwable $e) {
            return $this->Translate('Error') . ': ' . $e->getMessage();
        }
    }
}
