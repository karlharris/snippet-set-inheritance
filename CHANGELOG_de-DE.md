# 0.1.1
- Intern: Die Storefront löst die Vererbung jetzt über den offiziellen `storefront.snippets.post`-Extension-Point auf und die Administration über einen eigenen API-Endpoint, statt den Core-Snippet-Service zu dekorieren. Keine funktionale Änderung.

# 0.1.0
- Jedes Snippetset erhält ein optionales Eltern-Set. Nicht gepflegte Snippet-Keys übernehmen den effektiven Wert des Eltern-Sets (rekursiv), danach den eines konfigurierbaren system-weiten Fallback-Sets, erst dann die eigene Basisdatei des Sets.
- Selbstreferenzen und Zyklen in der Eltern-Kette werden beim Speichern abgelehnt; die maximale Kettentiefe ist konfigurierbar.
- Der Einzel-Snippet-Editor und die Snippet-Übersicht zeigen an, aus welchem Set ein geerbter Wert stammt.
- Die Übersetzungs-Kataloge abhängiger Kind-/Enkel-/... -Sets werden automatisch invalidiert, wenn sich ein Set oder seine Eltern-Zuordnung ändert.
