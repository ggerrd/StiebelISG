# StiebelISG

Liest das STIEBEL ELTRON Internet Service Gateway (ISG) über seine Weboberfläche aus und schreibt Einstellungen und Zeitprogramme zurück.
Das Modul schreibt über denselben Weg wie die Weboberfläche (`POST /save.php`), das ISG prüft also jeden Wert selbst.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanz](#4-einrichten-der-instanz)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)
7. [Nicht enthalten](#7-nicht-enthalten)

### 1. Funktionsumfang

* Einstellungen: Betriebsart, Heizen HK1–3, Warmwasser, Kühlen, Energiemanagement, Party, Ferien, Datum/Zeit, Sommerzeit (83 Parameter mit den Grenzwerten des ISG)
* Zeitprogramme HK1–3, Warmwasser, Zirkulation, Silent 1+2 als Text, z. B. `06:00-08:30, 16:00-22:00`
* Prozessdaten, Laufzeiten, Wärmemengen, Anlagenstatus, Softwarestände, Meldungsliste (nur lesen)
* Netzwerk, Proxy, Sprache und Anlagenbild (lesen; schreiben nur per Befehl und erst nach Freigabe in der Instanz)
* Auswahlliste in der Instanz: Nur angehakte Variablen werden angelegt, und das ISG wird nur für deren Seiten abgefragt

### 2. Voraussetzungen

* IP-Symcon ab Version 7.0 (getestet mit 9.0)
* STIEBEL ELTRON ISG (web) mit Weboberfläche **ohne** Benutzername/Passwort, erreichbar im lokalen Netz
* Getestet mit ISG-Weboberfläche 10.1.0 und einer WPL 25 AC. Andere Wärmepumpen zeigen andere Seiten und Werte. Parameter, die das ISG nicht anzeigt, bleiben leer.

### 3. Software-Installation

* Über den Module Store das Modul „StiebelISG“ installieren.
* Alternativ über das Module Control folgende URL hinzufügen: `https://github.com/ggerrd/StiebelISG`

### 4. Einrichten der Instanz

Unter „Instanz hinzufügen“ ist das Modul unter dem Hersteller „STIEBEL ELTRON“ aufgeführt.

__Konfigurationsseite__:

Name                                   | Beschreibung
-------------------------------------- | ------------------
IP-Adresse des ISG                     | IP-Adresse oder Hostname des ISG
Abfrage aktiv                          | Schaltet die Abfrage ein oder aus
Takt                                   | Abstand zwischen zwei Seitenabrufen (Standard 10 s). Das ISG braucht rund 5 Sekunden pro Seite, darum wird je Takt nur eine Seite geholt.
Status und Prozessdaten aktualisieren  | Standard 120 s
Einstellungen aktualisieren            | Standard 1800 s
Änderungen an Netzwerk, Proxy, …       | Gibt die Befehle `ISG_SetNetwork`, `ISG_SetProxy`, `ISG_SetLanguage` und `ISG_SetPlantImage` frei (Standard aus)
Variablen                              | Liste aller Variablen zum An- und Abwählen

Standardmäßig sind nur diese Variablen angehakt: Warmwasser Ist- und Solltemperatur, Verdichter (Betriebsstatus), Verdichter ein extern, maximale Vorlauftemperatur Heizen und Warmwasser sowie alle Werte mit `EVU` oder `EGV` im Namen.

Abgewählte Variablen werden beim Übernehmen samt ihren Werten gelöscht.
Messwerte erscheinen in der Liste, sobald das ISG sie zum ersten Mal meldet. Auf einer neuen Instanz füllt sich die Liste darum in den ersten 30 Minuten.
Die Statusseiten des ISG listen nur laufende Komponenten. Eine Pumpe, die bisher nie lief, erscheint deshalb erst, wenn sie zum ersten Mal läuft.

### 5. Statusvariablen und Profile

Ident                 | Inhalt                                                                  | Schaltbar
--------------------- | ----------------------------------------------------------------------- | ---------
`P_<id>`              | Einstellungen, `<id>` ist die Parameternummer des ISG                   | ja
`T_<programm>_<tag>`  | Zeitprogramm als Text, leer = keine Zeit, max. 3 Fenster, nur Viertelstunden | ja
`A_TIME`              | Antilegionellen-Startzeit `HH:MM`, 00:15 bis 24:00                      | ja
`R_<seite>_…`         | Prozessdaten, Laufzeiten, Wärmemengen, Status, Softwarestände           | nein
`S_*`, `M_*`          | Systemstatus, Portalstatus, Meldungsliste                               | nein
`N_*`                 | Netzwerk, Sprache, Anlagenbild                                          | nur per Befehl

Meldet das ISG für einen Wert 3686,4 (nicht gesetzt oder abgeschaltet), bleibt die Variable unverändert. Das Debug-Fenster der Instanz zeigt dazu einen Hinweis.

Die Namen der Messwerte stammen aus der Weboberfläche des ISG. Wird die Sprache des ISG umgestellt, entstehen neue Messwerte.

Das Modul legt Profile mit dem Präfix `ISG.` an (Grenzwerte, Schrittweite und Einheit wie im ISG, Auswahllisten für Betriebsart usw.).

### 6. PHP-Befehlsreferenz

`void ISG_RefreshAll(integer $InstanzID);`
Liest alle Seiten nacheinander im Takt neu ein.

`void ISG_Poll(integer $InstanzID);`
Holt die am längsten fällige Seite. Wird vom Timer aufgerufen.

Die folgenden Befehle sind nur nutzbar, wenn die Freigabe in der Instanz angehakt ist. Falsche Netzwerkwerte machen das ISG unerreichbar.

`void ISG_SetNetwork(integer $InstanzID, boolean $DHCP, string $IP, string $Netmask, string $Gateway, string $DNS1, string $DNS2, string $DNS3);`

`void ISG_SetProxy(integer $InstanzID, boolean $Enabled, string $Server, integer $Port, string $Username, string $Password);`

`void ISG_SetLanguage(integer $InstanzID, string $Language);` z. B. `'DEUTSCH'`

`void ISG_SetPlantImage(integer $InstanzID, string $File);` z. B. `'classic.jpg'`

### 7. Nicht enthalten

* ISG-Benutzername und -Passwort. Das Modul kann sich nicht am ISG anmelden und würde sich damit selbst aussperren.
* ISG-Neustart und Portal-Verbindung
* Die 7-Tage-Diagramme der Startseite. Dafür das Symcon-Archiv verwenden.

Dieses Modul ist kein Produkt der STIEBEL ELTRON GmbH & Co. KG und wird von ihr nicht unterstützt.
