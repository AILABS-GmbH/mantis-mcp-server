# Fehlerbericht: SOAP-API von MantisBT defekt (tickets.fatchip.de)

**Datum:** 2026-06-10
**System:** tickets.fatchip.de — MantisBT 1.2.8
**Serverpfad (aus den Fehlermeldungen):** `/usr/www/users/fcticket/htdocs`
**Webserver:** Apache

## Zusammenfassung

Die SOAP-API von MantisBT (`/api/soap/mantisconnect.php`) ist auf diesem
Server **nicht funktionsfähig**. Es gibt zwei voneinander unabhängige Probleme,
beide verursacht durch die Kombination **altes MantisBT 1.2.8 + mitgeliefertes
nusoap unter PHP 8** (zusätzlich ist `display_errors` aktiv).

Ein externer SOAP-Client (z. B. PHP `SoapClient`) kann dadurch weder die WSDL
laden noch eine Methode aufrufen.

---

## Problem 1 — Fatal Error bei JEDEM SOAP-Methodenaufruf

**Reproduktion:**

```
POST https://tickets.fatchip.de/api/soap/mantisconnect.php
Content-Type: text/xml; charset=utf-8
SOAPAction: "mc_version"

<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                  xmlns:man="http://futureware.biz/mantisconnect">
  <soapenv:Body><man:mc_version/></soapenv:Body>
</soapenv:Envelope>
```

**Antwort (HTTP 200, aber statt SOAP-XML ein PHP-Fatal-Error):**

```
Fatal error: Uncaught ArgumentCountError: Too few arguments to function
mc_error_handler(), 4 passed in
/usr/www/users/fcticket/htdocs/library/nusoap/nusoap.php on line 898
and exactly 5 expected in
/usr/www/users/fcticket/htdocs/api/soap/mc_api.php:347
Stack trace:
#0 /usr/www/users/fcticket/htdocs/library/nusoap/nusoap.php(898): mc_error_handler()
#1 /usr/www/users/fcticket/htdocs/library/nusoap/nusoap.php(294): nusoap_base->getmicrotime()
#2 /usr/www/users/fcticket/htdocs/library/nusoap/nusoap.php(3832): nusoap_base->debug()
#3 /usr/www/users/fcticket/htdocs/api/soap/mantisconnect.php(1549): nusoap_server->service()
#4 {main}
  thrown in /usr/www/users/fcticket/htdocs/api/soap/mc_api.php on line 347
```

**Ursache:** In PHP 8.0 wurde das 5. Argument (`$errcontext`) von
Error-Handler-Callbacks entfernt. PHP ruft den Handler nur noch mit 4
Argumenten auf. Die Funktion `mc_error_handler()` in
`api/soap/mc_api.php` (Zeile 347) deklariert aber **5 Pflichtparameter** →
`ArgumentCountError` → Skriptabbruch bei jedem Aufruf.

---

## Problem 2 — WSDL-Ausgabe durch 217 PHP-Warnings zerstört

**Reproduktion:**

```
GET https://tickets.fatchip.de/api/soap/mantisconnect.php?wsdl
```

**Antwort (HTTP 200, 438.039 Bytes):** Der WSDL-XML wird **217 mal** dieselbe
PHP-Warnung vorangestellt/eingestreut:

```
Warning: Trying to access array offset on value of type bool in
/usr/www/users/fcticket/htdocs/library/nusoap/nusoap.php on line 5697
```

Dadurch ist das Dokument kein gültiges XML mehr. Standard-SOAP-Clients lehnen
es ab mit:

```
SOAP-ERROR: Parsing WSDL: Couldn't load from
'.../api/soap/mantisconnect.php?wsdl' : Extra content at the end of the document
```

**Ursache:** `nusoap.php` (Zeile 5697) ist nicht PHP-8-kompatibel
(„Trying to access array offset on value of type bool", Verhalten ab PHP 7.4).
Da `display_errors` aktiv ist, landen diese Warnungen direkt im HTTP-Output und
verschmutzen die WSDL.

---

## Lösungsvorschläge (für Hoster / Server-Administrator)

In sinnvoller Reihenfolge:

1. **Sauberste Lösung — PHP-Version zurückstellen:** Für diese Domain/diesen
   vHost PHP **7.4** (oder eine andere 7.x-Version) verwenden. MantisBT 1.2.8
   und das mitgelieferte nusoap sind für PHP 5/7 ausgelegt und laufen dort
   stabil. Dies behebt **beide** Probleme auf einmal, ohne Code zu ändern.

2. **Falls PHP 8 bleiben muss — zwei Code-Patches nötig:**
   - `display_errors = Off` für diese Domain setzen (entfernt die 217 Warnings
     aus dem Output → WSDL wird wieder valide). **Achtung:** behebt Problem 1
     NICHT, da ein `ArgumentCountError` ein Abbruch ist, der nicht durch
     `display_errors` unterdrückt wird.
   - `mc_error_handler()` in `api/soap/mc_api.php` (~Zeile 347)
     PHP-8-tauglich machen, indem das 5. Argument optional gemacht wird,
     z. B. `function mc_error_handler($p_type, $p_error, $p_file, $p_line,
     $p_context = null)`.
   - Zusätzlich bleibt die nusoap-Warnung in `nusoap.php:5697` bestehen
     (nur dann unsichtbar) — langfristig ist nusoap unter PHP 8 nicht
     wartungsfrei.

3. **Langfristig:** MantisBT auf eine aktuelle 2.x-Version aktualisieren. Diese
   bietet eine moderne REST-API mit API-Tokens und ist PHP-8-kompatibel.
