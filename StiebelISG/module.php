<?php

declare(strict_types=1);

class StiebelISG extends IPSModule
{
    private const DAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    private const SLOTS_PER_DAY = 3;
    private const EMPTY_SLOT = '128;128';
    // Das ISG meldet 0x9000 / 10 = 3686,4 fuer nicht gesetzte oder abgeschaltete Werte.
    private const UNSET_LIMIT = 3276.0;
    private const UNSET_INFO = 'ISG meldet: nicht gesetzt oder abgeschaltet';
    private const ANTILEGIONELLA_TIME_ID = '10913';
    // Ohne Auswahl in der Instanz aktive Variablen.
    private const DEFAULT_ACTIVE = [
        'R_1_0_WARMWASSER_ISTTEMPERATUR', 'R_1_0_WARMWASSER_SOLLTEMPERATUR', 'R_2_0_BETRIEBSSTATUS_VERDICHTER',
        'R_2_2_STATUS_WAERMEPUMPE_VERDICHTER_EIN_EXTERN', 'P_38', 'P_372'
    ];
    // Die Statusseiten listen nur laufende Komponenten, ein EVU-Kontakt taucht darum evtl. erst spaeter auf.
    private const DEFAULT_ACTIVE_PATTERN = '/EVU|EGV/';
    private const PAGE_IDENTS = [
        '0'   => ['S_SYSTEM', 'S_PORTAL'],
        '2,9' => ['M_ACTIVE', 'M_TEXT'],
        '3,9' => ['A_TIME'],
        '5,0' => ['N_MAC', 'N_PORTAL', 'N_IP', 'N_NM', 'N_SG', 'N_DNS1', 'N_DNS2', 'N_DNS3', 'N_PROXYSERVER', 'N_PROXYPORT', 'N_DHCP', 'N_PROXY'],
        '5,2' => ['N_IMAGE'],
        '5,3' => ['N_LANG']
    ];
    private const CATEGORIES = [
        'R' => 'Messwert', 'S' => 'Status', 'M' => 'Status', 'P' => 'Einstellung', 'A' => 'Einstellung', 'T' => 'Zeitprogramm', 'N' => 'System'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyInteger('TickSeconds', 10);
        $this->RegisterPropertyInteger('StatusSeconds', 120);
        $this->RegisterPropertyInteger('SettingsSeconds', 1800);
        $this->RegisterPropertyBoolean('AllowSystemSettings', false);
        $this->RegisterPropertyString('Variables', '[]');
        $this->RegisterAttributeString('Readings', '{}');

        $this->RegisterTimer('Poll', 0, 'ISG_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->registerCatalogVariables();
        $this->syncReadings();
        $this->SetBuffer('Due', '');

        if ($this->ReadPropertyString('Host') === '') {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(202);
            return;
        }
        if (!$this->ReadPropertyBoolean('Active')) {
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('Poll', $this->ReadPropertyInteger('TickSeconds') * 1000);
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $names = array_map(function ($definition) {
            return $definition['name'];
        }, $this->definitions()) + $this->readings();
        $rows = [];
        foreach ($names as $ident => $name) {
            $ident = (string) $ident;
            $rows[] = ['Ident' => $ident, 'Enabled' => $this->isActive($ident), 'Category' => self::CATEGORIES[$ident[0]], 'Name' => $name];
        }
        $order = array_flip(array_values(array_unique(self::CATEGORIES)));
        usort($rows, function ($a, $b) use ($order) {
            return $order[$a['Category']] <=> $order[$b['Category']];
        });
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'Variables') {
                $element['values'] = $rows;
            }
        }
        return json_encode($form);
    }

    public function RequestAction($Ident, $Value)
    {
        if (preg_match('/^P_(\d+)$/', $Ident, $m)) {
            $this->writeParameter((int) $m[1], $Value);
        } elseif (preg_match('/^T_(\d+)_(\d)$/', $Ident, $m)) {
            $this->writeTimeProgram((int) $m[1], (int) $m[2], (string) $Value);
        } elseif ($Ident === 'A_TIME') {
            $this->writeAntilegionellaTime((string) $Value);
        } else {
            throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
        $this->SetValue($Ident, $Value);
    }

    /** Ruft die am laengsten faellige Seite ab. Wird vom Timer aufgerufen. */
    public function Poll()
    {
        $due = $this->readDue();
        asort($due);
        $page = (string) array_key_first($due);
        if ($due[$page] > time()) {
            return;
        }
        $html = $this->fetchPage($page);
        if ($html === null) {
            return;
        }
        $this->processPage($page, $html);
        $due = $this->readDue();
        $due[$page] = time() + $this->pageInterval($page);
        $this->SetBuffer('Due', json_encode($due));
    }

    /** Markiert alle Seiten als faellig, sie werden dann nacheinander im Takt abgerufen. */
    public function RefreshAll()
    {
        $this->SetBuffer('Due', '');
    }

    public function SetNetwork(bool $DHCP, string $IP, string $Netmask, string $Gateway, string $DNS1, string $DNS2, string $DNS3)
    {
        $this->requireSystemSettings();
        $fields = ['netzwerk_dhcp' => $DHCP ? '1' : null];
        $map = ['ip' => $IP, 'nm' => $Netmask, 'sg' => $Gateway, 'dns1' => $DNS1, 'dns2' => $DNS2, 'dns3' => $DNS3];
        foreach ($map as $key => $address) {
            $octets = explode('.', $address);
            if (count($octets) !== 4) {
                throw new Exception('Ungültige Adresse: ' . $address);
            }
            foreach ($octets as $i => $octet) {
                $fields['netzwerk_' . $key . '_' . $i] = $octet;
            }
        }
        $this->saveForm('5,0', $fields);
    }

    public function SetProxy(bool $Enabled, string $Server, int $Port, string $Username, string $Password)
    {
        $this->requireSystemSettings();
        $this->saveForm('5,0', [
            'netzwerk_proxy'       => $Enabled ? '1' : null,
            'proxyserver'          => $Server,
            'portnummer'           => (string) $Port,
            'benutzername'         => $Username,
            'passwort'             => $Password,
            'passwortwiederholung' => $Password
        ]);
    }

    public function SetLanguage(string $Language)
    {
        $this->requireSystemSettings();
        $this->saveForm('5,3', ['valspracheeinstellung' => $Language]);
    }

    public function SetPlantImage(string $File)
    {
        $this->requireSystemSettings();
        $this->saveForm('5,2', ['isg_anlagegeraetebild' => $File]);
    }

    private function requireSystemSettings(): void
    {
        if (!$this->ReadPropertyBoolean('AllowSystemSettings')) {
            throw new Exception('Änderungen an Systemeinstellungen sind in der Instanz nicht freigegeben.');
        }
    }

    private function catalog(): array
    {
        static $catalog = null;
        if ($catalog === null) {
            $catalog = json_decode(file_get_contents(__DIR__ . '/catalog.json'), true);
        }
        return $catalog;
    }

    private function pageInterval(string $page): int
    {
        $catalog = $this->catalog();
        if (in_array($page, $catalog['status_pages'], true) && $this->pageActive($page)) {
            return $this->ReadPropertyInteger('StatusSeconds');
        }
        return $this->ReadPropertyInteger('SettingsSeconds');
    }

    /** Prueft, ob auf der Seite mindestens eine ausgewaehlte Variable liegt. */
    private function pageActive(string $page): bool
    {
        $catalog = $this->catalog();
        foreach ($catalog['params'] as $param) {
            if ($param['page'] === $page && $this->isActive('P_' . $param['id'])) {
                return true;
            }
        }
        foreach ($catalog['programs'] as $index => $program) {
            if ($program['page'] === $page) {
                foreach (self::DAYS as $day => $dayName) {
                    if ($this->isActive('T_' . $index . '_' . $day)) {
                        return true;
                    }
                }
            }
        }
        foreach (self::PAGE_IDENTS[$page] ?? [] as $ident) {
            if ($this->isActive($ident)) {
                return true;
            }
        }
        $prefix = $this->tablePrefix($page);
        foreach ($this->readings() as $ident => $name) {
            if (strpos($ident, $prefix) === 0 && $this->isActive($ident)) {
                return true;
            }
        }
        return false;
    }

    private function tablePrefix(string $page): string
    {
        return 'R_' . str_replace(',', '_', $page) . '_';
    }

    private function readDue(): array
    {
        $due = json_decode($this->GetBuffer('Due'), true);
        if (is_array($due) && count($due) > 0) {
            return $due;
        }
        $catalog = $this->catalog();
        $pages = array_merge($catalog['status_pages'], $catalog['slow_pages'], $catalog['system_pages'],
            array_column($catalog['params'], 'page'), array_column($catalog['programs'], 'page'));
        // Tabellenseiten werden immer gelesen, damit neue Werte in der Auswahlliste erscheinen.
        $needed = array_filter(array_unique($pages), function ($page) use ($catalog) {
            return in_array($page, $catalog['table_pages'], true) || $this->pageActive($page);
        });
        return array_fill_keys($needed, 0);
    }

    private function markDue(string $page): void
    {
        $due = $this->readDue();
        $due[$page] = 0;
        $this->SetBuffer('Due', json_encode($due));
    }

    // ---------------------------------------------------------------- Variablen

    private function isActive(string $ident): bool
    {
        $choices = array_column(json_decode($this->ReadPropertyString('Variables'), true) ?: [], 'Enabled', 'Ident');
        return $choices[$ident] ?? (in_array($ident, self::DEFAULT_ACTIVE, true) || preg_match(self::DEFAULT_ACTIVE_PATTERN, $ident) === 1);
    }

    private function readings(): array
    {
        return json_decode($this->ReadAttributeString('Readings'), true) ?: [];
    }

    private function registerCatalogVariables(): void
    {
        foreach ($this->definitions() as $ident => $definition) {
            $keep = $this->isActive($ident);
            $this->MaintainVariable($ident, $definition['name'], $definition['type'], $definition['profile'], $definition['position'], $keep);
            if ($keep && $definition['action']) {
                $this->EnableAction($ident);
            }
        }
    }

    /** Merkt sich vorhandene Tabellenwerte fuer die Auswahlliste und entfernt abgewaehlte. */
    private function syncReadings(): void
    {
        $readings = $this->readings();
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $object = IPS_GetObject($child);
            if ($object['ObjectType'] !== OBJECTTYPE_VARIABLE || strpos($object['ObjectIdent'], 'R_') !== 0) {
                continue;
            }
            $readings[$object['ObjectIdent']] = $readings[$object['ObjectIdent']] ?? $object['ObjectName'];
            if (!$this->isActive($object['ObjectIdent'])) {
                $this->UnregisterVariable($object['ObjectIdent']);
            }
        }
        $this->WriteAttributeString('Readings', json_encode($readings));
    }

    private function definitions(): array
    {
        $catalog = $this->catalog();
        $definitions = [];
        $add = function (string $ident, string $name, int $type, string $profile, int $position, bool $action = false) use (&$definitions) {
            $definitions[$ident] = ['name' => $name, 'type' => $type, 'profile' => $profile, 'position' => $position, 'action' => $action];
        };
        $position = 100;
        foreach ($catalog['params'] as $param) {
            $type = in_array($param['type'], ['enum', 'int'], true) ? VARIABLETYPE_INTEGER : VARIABLETYPE_FLOAT;
            $add('P_' . $param['id'], $param['group'] . ' - ' . $param['name'], $type, $this->parameterProfile($param), $position++, true);
        }
        foreach ($catalog['programs'] as $index => $program) {
            foreach (self::DAYS as $day => $dayName) {
                $add('T_' . $index . '_' . $day, $program['name'] . ' - ' . $dayName, VARIABLETYPE_STRING, '', $position++, true);
            }
        }
        $add('A_TIME', 'Antilegionellen - Startzeit', VARIABLETYPE_STRING, '', $position, true);

        $add('S_SYSTEM', 'Systemstatus', VARIABLETYPE_STRING, '', 1);
        $add('S_PORTAL', 'Portalstatus', VARIABLETYPE_STRING, '', 2);
        $add('M_ACTIVE', 'Meldung aktiv', VARIABLETYPE_BOOLEAN, '~Alert', 3);
        $add('M_TEXT', 'Meldungsliste', VARIABLETYPE_STRING, '', 4);

        $system = 9000;
        foreach ([
            'N_MAC' => 'Netzwerk - MAC-Adresse', 'N_PORTAL' => 'Netzwerk - Serviceportal', 'N_IP' => 'Netzwerk - IP-Adresse',
            'N_NM' => 'Netzwerk - Subnetzmaske', 'N_SG' => 'Netzwerk - Gateway', 'N_DNS1' => 'Netzwerk - DNS-Server 1',
            'N_DNS2' => 'Netzwerk - DNS-Server 2', 'N_DNS3' => 'Netzwerk - DNS-Server 3', 'N_PROXYSERVER' => 'Netzwerk - Proxy-Server',
            'N_PROXYPORT' => 'Netzwerk - Proxy-Port', 'N_LANG' => 'ISG - Sprache', 'N_IMAGE' => 'ISG - Anlagenbild'
        ] as $ident => $name) {
            $add($ident, $name, VARIABLETYPE_STRING, '', $system++);
        }
        $add('N_DHCP', 'Netzwerk - DHCP', VARIABLETYPE_BOOLEAN, '~Switch', $system++);
        $add('N_PROXY', 'Netzwerk - Proxy aktiv', VARIABLETYPE_BOOLEAN, '~Switch', $system);
        return $definitions;
    }

    private function parameterProfile(array $param): string
    {
        if ($param['type'] === 'enum') {
            $name = 'ISG.E_' . substr(md5(json_encode($param['options'])), 0, 10);
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
                foreach ($param['options'] as $value => $caption) {
                    IPS_SetVariableProfileAssociation($name, (int) $value, $caption, '', -1);
                }
            }
            return $name;
        }
        $isInt = $param['type'] === 'int';
        $name = 'ISG.' . ($isInt ? 'I_' : 'F_') . $this->slug(implode('_', [$param['min'], $param['max'], $param['step'], $param['unit']]));
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $isInt ? VARIABLETYPE_INTEGER : VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileValues($name, $param['min'], $param['max'], $param['step']);
            if (!$isInt) {
                IPS_SetVariableProfileDigits($name, $this->digits($param));
            }
            IPS_SetVariableProfileText($name, '', $param['unit'] === '' ? '' : ' ' . $param['unit']);
        }
        return $name;
    }

    private function digits(array $param): int
    {
        if ($param['type'] === 'double' || $param['step'] < 0.1) {
            return 2;
        }
        return $param['type'] === 'float' ? 1 : 0;
    }

    private function slug(string $text): string
    {
        $text = str_replace(['Ä', 'Ö', 'Ü', 'ä', 'ö', 'ü', 'ß', '°', '-', '/', '%'], ['AE', 'OE', 'UE', 'ae', 'oe', 'ue', 'ss', '', 'm', '_', 'P'], $text);
        return trim(preg_replace('/[^A-Za-z0-9]+/', '_', $text), '_');
    }

    // ---------------------------------------------------------------- Lesen

    private function processPage(string $page, string $html): void
    {
        $catalog = $this->catalog();
        foreach ($catalog['params'] as $param) {
            if ($param['page'] === $page) {
                $this->readParameter($param, $html);
            }
        }
        foreach ($catalog['programs'] as $index => $program) {
            if ($program['page'] === $page) {
                $this->readTimeProgram($index, $program, $html);
            }
        }
        if (in_array($page, $catalog['table_pages'], true)) {
            $this->readTables($page, $html);
        }
        switch ($page) {
            case '0':
                $this->readStartPage($html);
                break;
            case '2,9':
                $this->readMessages($html);
                break;
            case '3,9':
                $this->readAntilegionellaTime($html);
                break;
            case '5,0':
                $this->readNetwork($html);
                break;
            case '5,2':
                $fields = $this->formFields($html);
                $this->store('N_IMAGE', $fields['isg_anlagegeraetebild'] ?? '');
                break;
            case '5,3':
                $fields = $this->formFields($html);
                $this->store('N_LANG', $fields['valspracheeinstellung'] ?? '');
                break;
        }
    }

    /** Setzt den Wert nur, wenn die Variable ausgewaehlt ist und darum existiert. */
    private function store(string $ident, $value): void
    {
        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false) {
            $this->SetValue($ident, $value);
        }
    }

    private function readParameter(array $param, string $html): void
    {
        $ident = 'P_' . $param['id'];
        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            return;
        }
        if ($param['type'] === 'enum') {
            $pattern = '/<input[^>]*name="' . preg_quote($param['radio'], '/') . '"[^>]*value="([^"]*)"[^>]*checked/';
            if (preg_match($pattern, $html, $m)) {
                $this->SetValue($ident, (int) $m[1]);
            }
            return;
        }
        if (!preg_match("/jsvalues\['?" . $param['id'] . "'?\]\['val'\]='([^']*)'/", $html, $m) || $m[1] === '') {
            return;
        }
        $value = (float) str_replace(',', '.', $m[1]);
        if (abs($value) >= self::UNSET_LIMIT) {
            $this->SendDebug($ident, self::UNSET_INFO, 0);
            return;
        }
        $this->SetValue($ident, $param['type'] === 'int' ? (int) $value : $value);
    }

    private function readTimeProgram(int $index, array $program, string $html): void
    {
        foreach (self::DAYS as $day => $dayName) {
            $first = $program['base'] + $day * self::SLOTS_PER_DAY;
            if (preg_match('/values:\s*\[([^\]]*)\](?:(?!values:).)*?ids:\s*\["val' . $first . '"/s', $html, $m)) {
                $this->store('T_' . $index . '_' . $day, $this->formatWindows($m[1]));
            }
        }
    }

    private function formatWindows(string $values): string
    {
        $numbers = $values === '' ? [] : array_map('intval', explode(',', $values));
        $windows = [];
        foreach (array_chunk($numbers, 2) as $pair) {
            if (count($pair) === 2) {
                $windows[] = $this->quarterToTime($pair[0]) . '-' . $this->quarterToTime($pair[1]);
            }
        }
        return implode(', ', $windows);
    }

    private function quarterToTime(int $quarter): string
    {
        return sprintf('%02d:%02d', intdiv($quarter, 4), ($quarter % 4) * 15);
    }

    private function readTables(string $page, string $html): void
    {
        $prefix = $this->tablePrefix($page);
        $position = 1000 + 100 * array_search($page, $this->catalog()['table_pages'], true);
        $section = '';
        $seen = [];
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/s', $html, $rows);
        foreach ($rows[1] as $row) {
            $position++;
            if (preg_match('/<th[^>]*>(.*?)<\/t[hd]>/s', $row, $m)) {
                $section = $this->text($m[1]);
                continue;
            }
            if (!preg_match('/<td class="key[^"]*"[^>]*>(.*?)<\/td>\s*<td class="value[^"]*"[^>]*>(.*?)<\/td>/s', $row, $m)) {
                continue;
            }
            $key = $this->text($m[1]);
            $text = $this->text($m[2]);
            if ($text === '' && strpos($m[2], '<img') !== false) {
                $ident = $prefix . $this->slug($section . '_' . $key);
                $this->setDynamic($ident, $section . ' - ' . $key, VARIABLETYPE_BOOLEAN, '~Switch', $position, strpos($m[2], 'symbol_an') !== false);
            } elseif ($text === '') {
                $ident = $prefix . $this->slug($section);
                $this->setDynamic($ident, $section, VARIABLETYPE_STRING, '', $position, $key);
            } elseif (preg_match('/^(-?[\d.]*\d(?:,\d+)?)\s*(\S*)$/u', $text, $n)) {
                $ident = $prefix . $this->slug($section . '_' . $key);
                $hasDecimals = strpos($n[1], ',') !== false;
                $number = (float) str_replace(['.', ','], ['', '.'], $n[1]);
                $profile = $this->readingProfile($n[2], $hasDecimals ? strlen($n[1]) - strpos($n[1], ',') - 1 : 0);
                $type = $hasDecimals ? VARIABLETYPE_FLOAT : VARIABLETYPE_INTEGER;
                $this->setDynamic($ident, $section . ' - ' . $key, $type, $profile, $position, $hasDecimals ? $number : (int) $number);
            } else {
                $ident = $prefix . $this->slug($section . '_' . $key);
                $this->setDynamic($ident, $section . ' - ' . $key, VARIABLETYPE_STRING, '', $position, $text);
            }
            $seen[$ident] = true;
        }
        // Die Statusseiten listen nur laufende Komponenten, nicht gelistete sind aus.
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $child) {
            $object = IPS_GetObject($child);
            if (strpos($object['ObjectIdent'], $prefix) === 0 && !isset($seen[$object['ObjectIdent']])
                && $object['ObjectType'] === OBJECTTYPE_VARIABLE && IPS_GetVariable($child)['VariableType'] === VARIABLETYPE_BOOLEAN) {
                $this->SetValue($object['ObjectIdent'], false);
            }
        }
    }

    private function setDynamic(string $ident, string $name, int $type, string $profile, int $position, $value): void
    {
        $readings = $this->readings();
        if (!isset($readings[$ident])) {
            $readings[$ident] = $name;
            $this->WriteAttributeString('Readings', json_encode($readings));
        }
        if (!$this->isActive($ident)) {
            return;
        }
        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            switch ($type) {
                case VARIABLETYPE_BOOLEAN:
                    $this->RegisterVariableBoolean($ident, $name, $profile, $position);
                    break;
                case VARIABLETYPE_INTEGER:
                    $this->RegisterVariableInteger($ident, $name, $profile, $position);
                    break;
                case VARIABLETYPE_FLOAT:
                    $this->RegisterVariableFloat($ident, $name, $profile, $position);
                    break;
                default:
                    $this->RegisterVariableString($ident, $name, $profile, $position);
            }
        }
        $this->SetValue($ident, $value);
    }

    private function readingProfile(string $unit, int $decimals): string
    {
        if ($unit === '') {
            return '';
        }
        $name = 'ISG.R' . $decimals . '_' . $this->slug($unit);
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $decimals > 0 ? VARIABLETYPE_FLOAT : VARIABLETYPE_INTEGER);
            if ($decimals > 0) {
                IPS_SetVariableProfileDigits($name, $decimals);
            }
            IPS_SetVariableProfileText($name, '', ' ' . $unit);
        }
        return $name;
    }

    private function readStartPage(string $html): void
    {
        if (preg_match('/id="box_start_status_system".*?<h3>.*?<\/h3>(.*?)<\/div>/s', $html, $m)) {
            $this->store('S_SYSTEM', $this->text($m[1]));
        }
        if (preg_match('/id="box_start_status_portal".*?<h3>.*?<\/h3>(.*?)<\/div>/s', $html, $m)) {
            $this->store('S_PORTAL', $this->text($m[1]));
        }
    }

    private function readMessages(string $html): void
    {
        if (!preg_match('/<div id="content">(.*?)<\/form>/s', $html, $m)) {
            return;
        }
        $text = $this->text($m[1]);
        $this->store('M_TEXT', $text);
        $this->store('M_ACTIVE', stripos($text, 'KEINE FEHLER') === false);
    }

    private function readAntilegionellaTime(string $html): void
    {
        $id = self::ANTILEGIONELLA_TIME_ID;
        if (preg_match('/class="edit2 schph" id="' . $id . '".*?value="(\d+)" selected.*?class="edit2 schpm" id="' . $id . '".*?value="(\d+)" selected/s', $html, $m)) {
            $this->store('A_TIME', $m[1] . ':' . $m[2]);
        }
    }

    private function readNetwork(string $html): void
    {
        $fields = $this->formFields($html);
        foreach (['ip' => 'N_IP', 'nm' => 'N_NM', 'sg' => 'N_SG', 'dns1' => 'N_DNS1', 'dns2' => 'N_DNS2', 'dns3' => 'N_DNS3'] as $key => $ident) {
            $octets = [];
            for ($i = 0; $i < 4; $i++) {
                $octets[] = $fields['netzwerk_' . $key . '_' . $i] ?? '';
            }
            $this->store($ident, trim(implode('.', $octets), '.'));
        }
        $this->store('N_DHCP', isset($fields['netzwerk_dhcp']));
        $this->store('N_PROXY', isset($fields['netzwerk_proxy']));
        $this->store('N_PROXYSERVER', $fields['proxyserver'] ?? '');
        $this->store('N_PROXYPORT', $fields['portnummer'] ?? '');
        if (preg_match('/MAC-address<\/h3>.*?values"[^>]*>\s*([^<]+?)\s*</s', $html, $m)) {
            $this->store('N_MAC', $m[1]);
        }
        if (preg_match('/PORTAL<\/h3>.*?values"[^>]*>\s*([^<]+?)\s*</s', $html, $m)) {
            $this->store('N_PORTAL', $m[1]);
        }
    }

    private function text(string $html): string
    {
        $text = preg_replace('/<script.*?<\/script>/s', ' ', $html);
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** Liest die Felder des Formulars wie jQuery.serializeArray: nicht angehakte Felder fehlen. */
    private function formFields(string $html): array
    {
        $fields = [];
        preg_match_all('/<input\b[^>]*>/s', $html, $inputs);
        foreach ($inputs[0] as $input) {
            if (!preg_match('/\bname="([^"]*)"/', $input, $name)) {
                continue;
            }
            $type = preg_match('/\btype="([^"]*)"/', $input, $m) ? strtolower($m[1]) : 'text';
            if (in_array($type, ['radio', 'checkbox'], true) && !preg_match('/\bchecked\b/', $input)) {
                continue;
            }
            $fields[$name[1]] = preg_match('/\bvalue="([^"]*)"/', $input, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
        }
        return $fields;
    }

    // ---------------------------------------------------------------- Schreiben

    private function writeParameter(int $id, $value): void
    {
        foreach ($this->catalog()['params'] as $param) {
            if ($param['id'] === $id) {
                $this->save([['name' => 'val' . $id, 'value' => $this->encodeValue($param, $value)]]);
                $this->markDue($param['page']);
                return;
            }
        }
        throw new Exception('Unbekannter Parameter: ' . $id);
    }

    private function encodeValue(array $param, $value): string
    {
        if ($param['type'] === 'enum') {
            if (!isset($param['options'][(string) $value])) {
                throw new Exception($param['name'] . ': ungültige Auswahl ' . $value);
            }
            return (string) $value;
        }
        if ($value < $param['min'] || $value > $param['max']) {
            throw new Exception(sprintf('%s: erlaubt sind %s bis %s %s', $param['name'], $param['min'], $param['max'], $param['unit']));
        }
        $rounded = round($value / $param['step']) * $param['step'];
        return number_format($rounded, $this->digits($param), ',', '');
    }

    private function writeTimeProgram(int $index, int $day, string $value): void
    {
        $program = $this->catalog()['programs'][$index] ?? null;
        if ($program === null || !isset(self::DAYS[$day])) {
            throw new Exception('Unbekanntes Zeitprogramm');
        }
        $slots = $this->parseWindows($value);
        $first = $program['base'] + $day * self::SLOTS_PER_DAY;
        $entries = [];
        for ($i = 0; $i < self::SLOTS_PER_DAY; $i++) {
            $entries[] = ['name' => 'val' . ($first + $i), 'value' => $slots[$i] ?? self::EMPTY_SLOT];
        }
        $this->save($entries);
        $this->markDue($program['page']);
    }

    /** Wandelt "06:00-08:30, 16:00-22:00" in die Viertelstunden-Slots des ISG. Leer heisst: keine Zeit. */
    private function parseWindows(string $value): array
    {
        $slots = [];
        $last = -1;
        foreach (array_filter(array_map('trim', explode(',', $value)), 'strlen') as $window) {
            if (!preg_match('/^(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $window, $m)) {
                throw new Exception('Format: 06:00-08:30, 16:00-22:00');
            }
            $start = $this->timeToQuarter($m[1]);
            $end = $this->timeToQuarter($m[2]);
            if ($start >= $end || $start <= $last) {
                throw new Exception('Zeitfenster müssen aufsteigend sein und dürfen sich nicht überschneiden: ' . $window);
            }
            $slots[] = $start . ';' . $end;
            $last = $end;
        }
        if (count($slots) > self::SLOTS_PER_DAY) {
            throw new Exception('Maximal ' . self::SLOTS_PER_DAY . ' Zeitfenster pro Tag');
        }
        return $slots;
    }

    private function timeToQuarter(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        if ($minute % 15 !== 0 || $minute > 45 || $hour > 24 || ($hour === 24 && $minute !== 0)) {
            throw new Exception('Nur Viertelstunden bis 24:00 erlaubt: ' . $time);
        }
        return $hour * 4 + intdiv($minute, 15);
    }

    private function writeAntilegionellaTime(string $value): void
    {
        if (!preg_match('/^(\d{2}):(00|15|30|45)$/', $value, $m) || (int) $m[1] > 24 || ($m[1] === '24' && $m[2] !== '00') || $value === '00:00') {
            throw new Exception('Format: HH:MM in Viertelstunden von 00:15 bis 24:00, z. B. 02:15');
        }
        $id = self::ANTILEGIONELLA_TIME_ID;
        $this->save([['name' => $id, 'value' => $m[1]], ['name' => $id, 'value' => $m[2]]]);
        $this->markDue('3,9');
    }

    /** Sendet ein Formular so, wie es die Weboberflaeche tut. Null entfernt ein Feld (Checkbox aus). */
    private function saveForm(string $page, array $overrides): void
    {
        $html = $this->fetchPage($page);
        if ($html === null) {
            throw new Exception('ISG nicht erreichbar');
        }
        $fields = array_merge($this->formFields($html), $overrides);
        $entries = [];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $entries[] = ['name' => $name, 'value' => $value];
            }
        }
        $this->save($entries);
        $this->markDue($page);
    }

    private function save(array $entries): void
    {
        $data = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->SendDebug('save', $data, 0);
        $answer = $this->request('/save.php', ['data' => $data]);
        if ($answer === null) {
            throw new Exception('ISG nicht erreichbar');
        }
        $result = json_decode($answer, true);
        if (!is_array($result) || (empty($result['success']) && empty($result['warning']))) {
            $message = is_array($result) ? ($result['message'] ?? '') : $answer;
            throw new Exception('ISG hat den Wert abgelehnt: ' . html_entity_decode((string) $message, ENT_QUOTES, 'UTF-8'));
        }
    }

    // ---------------------------------------------------------------- HTTP

    private function fetchPage(string $page): ?string
    {
        return $this->request('/?s=' . $page);
    }

    private function request(string $path, ?array $post = null): ?string
    {
        $semaphore = 'ISG_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 40000)) {
            $this->SendDebug('request', 'Semaphore belegt', 0);
            return null;
        }
        $curl = curl_init('http://' . $this->ReadPropertyString('Host') . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30]);
        $cookie = $this->GetBuffer('Cookie');
        if ($cookie !== '') {
            curl_setopt($curl, CURLOPT_COOKIE, $cookie);
        }
        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $response = curl_exec($curl);
        $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $error = curl_error($curl);
        IPS_SemaphoreLeave($semaphore);

        if ($response === false || $code !== 200) {
            $this->SendDebug('request', $path . ' fehlgeschlagen: ' . $code . ' ' . $error, 0);
            $this->SetStatus(201);
            return null;
        }
        if (preg_match('/^Set-Cookie:\s*([^;\r\n]+)/mi', substr($response, 0, $headerSize), $m)) {
            $this->SetBuffer('Cookie', $m[1]);
        }
        if ($this->GetStatus() !== 102) {
            $this->SetStatus(102);
        }
        return substr($response, $headerSize);
    }
}
