# Verbinden Sie Ihren plentymarkets Shop mit wallee

wallee bietet Ihnen als PSP / E-Commerce hub direkten Zugang zu einer großen Auswahl an Zahlungsverarbeitern und
Zahlungsmethoden via eine standardisierte API. Mit anderen Worten lösen wir mit diesem Produkt
Ihre Zahlungsprobleme auf einen Schlag in Ihrem Webshop. Sobald Sie das Plugin in Ihrem Shop installiert haben, 
können Sie für die Verarbeitung Ihrer Zahlung aus einem der integrierten <a href="https://app-wallee.com/en/processors" target="_blank">Verarbeitern</a> auswählen. Damit wird die Verarbeitung via Kreditkarten aber auch jede weitere Form alternativer Zahlungsarten ermöglicht.
Sie können zudem auch Rechnungen verarbeiten (Für Schweizer Kunden sogar mit Einzahlungsschein).
Daneben können Sie Ihre Kunden auch mittels selbst-konfigurierbaren Mahnläufen mahnen u.v.m.
 
Für die Zahlungsabwicklung wird der Kunde auf die wallee payment page weitergeleitet, welche Sie vollständig selber gestalten können. 
 
Neben der Zahlungsverarbeitung löst Ihnen wallee auch noch zahlreiche weitere Probleme die Sie als Händler haben. Wie beispielsweise:

* Sie skalieren per Knopfdruck und können eine neu Zahlart aktivieren
* Sie können Ihre eigenen Rechnungsdokumente erstellen und den Kunden zustellen oder drucken via Cloud
* Dokumente können über die Cloud auf Ihrem Drucker gedruckt oder archiviert werden
* Definieren Sie Ihre Mahnläufe für Rechnungen die Sie selber verarbeiten
* Versenden Sie automatisch Erinnerungen und Mahnungen an Ihre Kunden. 

Dies und vieles mehr steht Ihnen ab sofort mit einer direkten Integration zur Verfügung. 


## Voraussetzungen

Damit Sie wallee nutzen können müssen Sie folgende Voraussetzungen erfüllen:

* Sie benötigen ein wallee Konto. Dieses können Sie mit dem <a href="https://app-wallee.com/user/signup" target="_blank">signup</a> Link kostenfrei erstellen.
* Sie müssen das Plugin installieren entweder via Marketplace oder indem Sie das Github Repository unter Plugin > Git einfügen.

 
## Plugin configuration
 
Das Plugin kann einfach in Ihrem Shop installiert und konfiguriert werden.

* Erstellen Sie Ihr Konto und den Application User inkl. User ID, Secret und Space ID. Dieses tragen Sie unter Plugins > Konfiguration ein.
* Aktivieren Sie die Zahlungsmethoden, welche Sie im Space aktiv haben

 
### Anpassen der E-Mail, Payment Page und Dokumente

Für die Verarbeitung der Zahlung werden Sie auf die Zahlungsseite von wallee weitergeleitet. Diese Seite können Sie mit Hilfe der TWIG Templates komplett selber gestalten. Mehr Informationen finden Sie in der <a href="https://app-wallee.com/de-ch/doc/document-handling" target="_blank">Dokumentation</a>.
 
 
### Gutschriften
 
Tragen Sie noch die Stati für die Gutschriften ein, bei welchem automatisch eine Gutschriftsanzeige an wallee übermittelt wird. 
Bitte führen Sie folgende Schritte durch:

1. Erstellen Sie unter Einstellung > Aufträge > Ereignisaktionen eine Ereignisaktion für Statuswechsel. Wählen Sie den initialen
Status und die Aktion "Rückzahlung der wallee-Zahlung", welche Sie im Ordner Plugin finden. 
2. Speichern Sie die Ereignisaktion.

Sie können nun entweder Gutschriften oder Retouren direkt in der Bestellung anlegen:

1. Öffnen Sie die Bestellung und wählen Sie entweder eine Gutschrift oder eine Retoure anlegen. 
2. Selektieren Sie die Produkte, die Sie gutschreiben / retournieren möchten. 
3. Verschieben Sie den Status der Bestellung in den initialen Status der Ereignisaktion, welche Sie oben definiert haben. Dies führt automatisch dazu,
dass die Bestellung mit wallee synchronisiert wird. 

## Weitere Informationen

Für mehr Informationen verweisen wir Sie auf unsere extensive <a href="https://app-wallee.com/de-ch/doc" target="_blank">Dokumentation</a>.
Bei Fragen zum Produkt oder zur Konfiguration steht Ihnen sonst unser <a href="https://wallee.com/ueber-wallee/support?_ga=2.171642464.1523640132.1674037856-1834608674.1611572458" target="_blank">Support</a> ebenfalls zur Verfügung. 
 
## Lizenz
 
Das Plugin wird unter der Apapche 2 Lizenz vertrieben. 